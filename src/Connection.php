<?php

declare(strict_types=1);

namespace PorscheConnect;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use PorscheConnect\Exception\PorscheException;
use PorscheConnect\OAuth2\OAuth2Client;
use PorscheConnect\OAuth2\OAuth2Token;

class Connection
{
    private Client $httpClient;

    private OAuth2Token $token;

    /** @var array<string, string> */
    private array $headers;

    private OAuth2Client $oauth2Client;

    public function __construct(
        private readonly ?string $email = null,
        private readonly ?string $password = null,
        ?string $captchaCode = null,
        ?string $captchaState = null,
        ?Client $httpClient = null,
        ?array $token = null,
        private readonly int $leeway = 60,
    ) {
        $this->httpClient = $httpClient ?? new Client(['cookies' => true]);
        $this->token = new OAuth2Token($token ?? []);
        $this->headers = [
            'User-Agent' => Consts::USER_AGENT,
            'X-Client-ID' => Consts::X_CLIENT_ID,
        ];
        $this->oauth2Client = new OAuth2Client(
            $this->httpClient,
            $email,
            $password,
            $captchaCode,
            $captchaState,
            $leeway,
        );
    }

    public function getHttpClient(): Client
    {
        return $this->httpClient;
    }

    public function getOAuth2Client(): OAuth2Client
    {
        return $this->oauth2Client;
    }

    public function setCaptcha(?string $captchaCode, ?string $state): void
    {
        $this->oauth2Client->setCaptcha($captchaCode, $state);
    }

    public function getToken(): OAuth2Token
    {
        $this->oauth2Client->ensureValidToken($this->token);

        return $this->token;
    }

    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>|list<mixed>
     */
    public function get(string $url, ?array $params = null): array
    {
        return $this->request('GET', $url, ['query' => $params]);
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>|list<mixed>
     */
    public function post(string $url, ?array $data = null, ?array $json = null): array
    {
        $options = [];
        if ($data !== null) {
            $options['form_params'] = $data;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->request('POST', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|list<mixed>
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $this->oauth2Client->ensureValidToken($this->token);

        $options['headers'] = array_merge(
            $this->headers,
            ['Authorization' => 'Bearer ' . $this->token->getAccessToken()],
            $options['headers'] ?? [],
        );
        $options['timeout'] = Consts::TIMEOUT;

        try {
            $response = $this->httpClient->request(
                $method,
                Consts::API_BASE_URL . $url,
                $options,
            );

            $body = json_decode((string) $response->getBody(), true);

            return is_array($body) ? $body : [];
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? $e->getCode();
            throw new PorscheException($status, $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new PorscheException($e->getCode(), $e->getMessage(), $e);
        }
    }
}
