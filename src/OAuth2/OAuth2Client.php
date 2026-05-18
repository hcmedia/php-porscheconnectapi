<?php

declare(strict_types=1);

namespace PorscheConnect\OAuth2;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PorscheConnect\Consts;
use PorscheConnect\Exception\PorscheCaptchaRequiredException;
use PorscheConnect\Exception\PorscheException;
use PorscheConnect\Exception\PorscheWrongCredentialsException;

class OAuth2Client
{
    /** @var array<string, string> */
    private array $headers;

    public function __construct(
        private readonly Client $client,
        private readonly ?string $email,
        private readonly ?string $password,
        private ?string $captchaCode = null,
        private ?string $captchaState = null,
        private readonly int $leeway = 60,
    ) {
        $this->headers = [
            'User-Agent' => Consts::USER_AGENT,
            'X-Client-ID' => Consts::X_CLIENT_ID,
        ];
    }

    public function setCaptcha(?string $captchaCode, ?string $state): void
    {
        $this->captchaCode = $captchaCode;
        $this->captchaState = $state;
    }

    public function ensureValidToken(OAuth2Token $token): void
    {
        $tokenIsExpired = $token->isExpired($this->leeway);

        if ($tokenIsExpired === true) {
            $tokenData = $this->refreshToken($token->getRefreshToken());
            $token->update($tokenData);
            if (isset($tokenData['expires_in'])) {
                $token->setExpiresAt((int) $tokenData['expires_in']);
            }
        }

        if ($token->getAccessToken() === null || $tokenIsExpired === null) {
            $authCode = $this->fetchAuthorizationCode();
            $tokenData = $this->fetchAccessToken($authCode);
            $token->update($tokenData);
            if (isset($tokenData['expires_in'])) {
                $token->setExpiresAt((int) $tokenData['expires_in']);
            }
        }
    }

    public function fetchAuthorizationCode(): string
    {
        $authParams = [
            'response_type' => 'code',
            'client_id' => Consts::CLIENT_ID,
            'redirect_uri' => Consts::REDIRECT_URI,
            'audience' => Consts::AUDIENCE,
            'scope' => Consts::SCOPE,
            'state' => 'php-porsche-connect-api',
        ];

        if ($this->captchaCode === null) {
            $params = $this->getAndExtractLocationParams(Consts::AUTHORIZATION_URL, $authParams);

            $authorizationCode = $params['code'][0] ?? null;
            if ($authorizationCode !== null) {
                return $authorizationCode;
            }

            $state = $params['state'][0] ?? '';
            $resumeLocation = $this->loginWithIdentifier($state);
            $params = $this->resumeAuthorization($resumeLocation);

            return $params['code'][0] ?? throw new PorscheException(null, 'Could not fetch authorization code');
        }

        $resumeLocation = $this->loginWithIdentifier($this->captchaState ?? '');
        $params = $this->resumeAuthorization($resumeLocation);

        return $params['code'][0] ?? throw new PorscheException(null, 'Could not fetch authorization code');
    }

    /**
     * @return array<string, list<string>>
     */
    private function resumeAuthorization(string $resumeLocation): array
    {
        return $this->followRedirectsForAuthCode($this->resolveUrl($resumeLocation));
    }

    private function resolveUrl(string $location, ?string $baseUrl = null): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }

        if (!str_starts_with($location, '/')) {
            $location = '/' . $location;
        }

        if ($baseUrl !== null) {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            if (is_string($host) && $host !== '') {
                return $scheme . '://' . $host . $location;
            }
        }

        return 'https://' . Consts::AUTHORIZATION_SERVER . $location;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, list<string>>
     */
    private function getAndExtractLocationParams(string $url, array $params = []): array
    {
        try {
            $response = $this->client->get($url, [
                'query' => $this->mergeQueryParams($url, $params),
                'timeout' => Consts::TIMEOUT,
                'headers' => $this->headers,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new PorscheException($e->getCode(), $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();

        if (!$this->isRedirectStatus($status)) {
            throw new PorscheException(
                $status,
                'Could not fetch authorization code (HTTP ' . $status . ')',
            );
        }

        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            throw new PorscheException(null, 'Could not fetch authorization code (missing Location header)');
        }

        return $this->extractParamsFromUrl($location);
    }

    /**
     * @return array<string, list<string>>
     */
    private function followRedirectsForAuthCode(string $url): array
    {
        $queryParams = [];

        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                $response = $this->client->get($url, [
                    'query' => $this->mergeQueryParams($url, $queryParams),
                    'timeout' => Consts::TIMEOUT,
                    'headers' => $this->headers,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                throw new PorscheException($e->getCode(), $e->getMessage(), $e);
            }

            $queryParams = [];
            $status = $response->getStatusCode();
            $location = $response->getHeaderLine('Location');

            if ($location !== '') {
                if ($this->isLoginPageLocation($location)) {
                    throw new PorscheWrongCredentialsException(401, 'Wrong credentials');
                }

                $extracted = $this->extractParamsFromUrl($location);
                if (($extracted['code'][0] ?? null) !== null) {
                    return $extracted;
                }
            }

            if (!$this->isRedirectStatus($status)) {
                throw new PorscheException(
                    $status,
                    'Could not fetch authorization code after resume (HTTP ' . $status . ')',
                );
            }

            if ($location === '' || !$this->isHttpRedirect($location)) {
                throw new PorscheException(
                    null,
                    'Could not fetch authorization code (unexpected redirect target)',
                );
            }

            $url = $this->resolveUrl($location, $url);
        }

        throw new PorscheException(null, 'Too many redirects while fetching authorization code');
    }

    private function isHttpRedirect(string $location): bool
    {
        return str_starts_with($location, 'http://')
            || str_starts_with($location, 'https://')
            || str_starts_with($location, '/')
            || str_starts_with($location, '//');
    }

    private function isRedirectStatus(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }

    private function isLoginPageLocation(string $location): bool
    {
        return str_contains($location, '/u/login/password')
            || str_contains($location, '/u/login/identifier');
    }

    private function extractStateFromUrl(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $parsed);

        return isset($parsed['state']) ? (string) $parsed['state'] : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function extractParamsFromUrl(string $url): array
    {
        if (preg_match('/[?&]code=([^&]+)/', $url, $match)) {
            return ['code' => [rawurldecode($match[1])]];
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return [];
        }

        parse_str($query, $parsed);

        $result = [];
        foreach ($parsed as $key => $value) {
            $result[$key] = is_array($value) ? array_map('strval', $value) : [(string) $value];
        }

        return $result;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, string>
     */
    private function mergeQueryParams(string $url, array $params): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        $existing = [];
        if (is_string($query)) {
            parse_str($query, $existing);
        }

        return array_merge($existing, $params);
    }

    private function extractCaptchaImage(string $html): ?string
    {
        if (preg_match('/atob\("([A-Za-z0-9+\/=]+)"/', $html, $scriptMatch)) {
            $decoded = base64_decode($scriptMatch[1], true);
            if ($decoded !== false) {
                $contextData = json_decode($decoded, true);
                if (is_array($contextData)) {
                    $captchaImg = $contextData['screen']['captcha']['image'] ?? null;
                    if (is_string($captchaImg) && $captchaImg !== '') {
                        return $captchaImg;
                    }
                }
            }
        }

        if (preg_match('/<img[^>]+alt=["\']captcha["\'][^>]+src=["\']([^"\']+)["\']/i', $html, $imgMatch)) {
            return $imgMatch[1];
        }

        if (preg_match('/(data:image\/svg[^ ]+)/', $html, $svgMatch)) {
            return $svgMatch[1];
        }

        return null;
    }

    private function loginWithIdentifier(string $state): string
    {
        $data = [
            'state' => $state,
            'username' => $this->email,
            'js-available' => 'true',
            'webauthn-available' => 'false',
            'is-brave' => 'false',
            'webauthn-platform-available' => 'false',
            'action' => 'default',
        ];

        if ($this->captchaCode !== null) {
            $data['captcha'] = $this->captchaCode;
        }

        $identifierUrl = 'https://' . Consts::AUTHORIZATION_SERVER . '/u/login/identifier';

        try {
            $resp = $this->client->post($identifierUrl, [
                'form_params' => $data,
                'query' => ['state' => $state],
                'timeout' => Consts::TIMEOUT,
                'headers' => $this->headers,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new PorscheException($e->getCode(), $e->getMessage(), $e);
        }

        $status = $resp->getStatusCode();

        if ($status === 401) {
            throw new PorscheWrongCredentialsException(401, 'Wrong credentials');
        }

        if ($status === 400 && $this->captchaCode === null) {
            $captchaImg = $this->extractCaptchaImage((string) $resp->getBody());
            if ($captchaImg === null) {
                throw new PorscheException(null, 'Captcha required but could not parse captcha image');
            }

            throw new PorscheCaptchaRequiredException($captchaImg, $state);
        }

        if ($status !== 302) {
            throw new PorscheException($status, 'Unexpected response after submitting email (HTTP ' . $status . ')');
        }

        $passwordLocation = $resp->getHeaderLine('Location');
        if ($passwordLocation === '') {
            throw new PorscheException(null, 'Missing password step URL after submitting email');
        }

        $passwordUrl = $this->resolveUrl($passwordLocation, $identifierUrl);
        $passwordState = $this->extractStateFromUrl($passwordUrl) ?? $state;

        $passwordData = [
            'state' => $passwordState,
            'username' => $this->email,
            'password' => $this->password,
            'action' => 'default',
        ];

        try {
            $passwordResp = $this->client->post($passwordUrl, [
                'form_params' => $passwordData,
                'timeout' => Consts::TIMEOUT,
                'headers' => $this->headers,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new PorscheException($e->getCode(), $e->getMessage(), $e);
        }

        $passwordStatus = $passwordResp->getStatusCode();

        if ($passwordStatus === 400) {
            throw new PorscheWrongCredentialsException(400, 'Wrong credentials');
        }

        if ($passwordStatus !== 302) {
            throw new PorscheException(
                $passwordStatus,
                'Unexpected response after submitting password (HTTP ' . $passwordStatus . ')',
            );
        }

        $resumeUrl = $passwordResp->getHeaderLine('Location');
        if ($resumeUrl === '') {
            throw new PorscheException(null, 'Missing resume URL after login');
        }

        if ($this->isLoginPageLocation($resumeUrl)) {
            throw new PorscheWrongCredentialsException(401, 'Wrong credentials');
        }

        usleep(2_500_000);

        return $resumeUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccessToken(string $authorizationCode): array
    {
        try {
            $resp = $this->client->post(Consts::TOKEN_URL, [
                'form_params' => [
                    'client_id' => Consts::CLIENT_ID,
                    'grant_type' => 'authorization_code',
                    'code' => $authorizationCode,
                    'redirect_uri' => Consts::REDIRECT_URI,
                ],
                'timeout' => Consts::TIMEOUT,
                'headers' => $this->headers,
                'http_errors' => false,
            ]);

            $data = json_decode((string) $resp->getBody(), true);
            if (!is_array($data)) {
                throw new PorscheException('Invalid token response');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new PorscheException($e->getCode(), $e->getMessage(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshToken(?string $refreshToken): array
    {
        if ($refreshToken === null) {
            return ['access_token' => null, 'expires_in' => 0];
        }

        try {
            $resp = $this->client->post(Consts::TOKEN_URL, [
                'form_params' => [
                    'client_id' => Consts::CLIENT_ID,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
                'timeout' => Consts::TIMEOUT,
                'headers' => $this->headers,
                'http_errors' => false,
            ]);

            $data = json_decode((string) $resp->getBody(), true);
            if (!is_array($data)) {
                throw new PorscheException('Invalid refresh token response');
            }

            return $data;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 403) {
                return ['access_token' => null, 'expires_in' => 0];
            }

            throw new PorscheException($e->getCode(), $e->getMessage(), $e);
        }
    }
}
