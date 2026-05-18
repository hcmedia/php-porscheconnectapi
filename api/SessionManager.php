<?php

declare(strict_types=1);

namespace PorscheConnect\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PorscheConnect\Connection;
use PorscheConnect\Exception\PorscheCaptchaRequiredException;
use PorscheConnect\PorscheConnectAccount;

class SessionManager
{
    private const STORAGE_DIR = __DIR__ . '/../storage/sessions';

    public function __construct()
    {
        if (!is_dir(self::STORAGE_DIR)) {
            mkdir(self::STORAGE_DIR, 0700, true);
        }
    }

    public function createSession(
        ?string $email = null,
        ?string $password = null,
        ?array $token = null,
        ?string $captchaCode = null,
        ?string $captchaState = null,
    ): string {
        $sessionId = bin2hex(random_bytes(16));

        $this->save($sessionId, [
            'email' => $email,
            'password' => $password,
            'token' => $token ?? [],
            'cookies' => [],
            'captcha_code' => $captchaCode,
            'captcha_state' => $captchaState,
        ]);

        return $sessionId;
    }

    public function getAccount(string $sessionId): ?PorscheConnectAccount
    {
        $connection = $this->createConnection($sessionId);
        if ($connection === null) {
            return null;
        }

        return new PorscheConnectAccount(connection: $connection);
    }

    public function getConnection(string $sessionId): ?Connection
    {
        return $this->createConnection($sessionId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getToken(string $sessionId): ?array
    {
        $connection = $this->createConnection($sessionId);
        if ($connection === null) {
            return null;
        }

        return $this->refreshAndPersistToken($sessionId, $connection);
    }

    /**
     * Erneuert den Access-Token per refresh_token, wenn er abgelaufen ist oder bald abläuft (Leeway),
     * und speichert ihn in der Session-Datei.
     *
     * @return array<string, mixed>|null
     */
    public function ensureValidToken(string $sessionId): ?array
    {
        return $this->getToken($sessionId);
    }

    public function canWriteSession(string $sessionId): bool
    {
        $path = $this->path($sessionId);

        if (is_file($path)) {
            return is_writable($path);
        }

        return is_writable(self::STORAGE_DIR);
    }

    public function persistConnection(string $sessionId, Connection $connection): void
    {
        if (!$this->canWriteSession($sessionId)) {
            return;
        }

        $this->persistCookies($sessionId, $connection);
        $this->updateToken($sessionId, $connection->getToken()->toArray());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refreshAndPersistToken(string $sessionId, Connection $connection): ?array
    {
        try {
            $token = $connection->getToken()->toArray();
        } catch (PorscheCaptchaRequiredException $e) {
            if ($this->canWriteSession($sessionId)) {
                $this->persistCookies($sessionId, $connection);
            }
            throw $e;
        }

        if ($this->canWriteSession($sessionId)) {
            $this->persistCookies($sessionId, $connection);
            $this->updateToken($sessionId, $token);
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $token
     */
    public function updateToken(string $sessionId, array $token): void
    {
        $data = $this->load($sessionId);
        if ($data === null) {
            return;
        }

        $data['token'] = $token;
        $this->save($sessionId, $data);
    }

    public function setCaptcha(string $sessionId, string $captchaCode, string $state): void
    {
        $data = $this->load($sessionId);
        if ($data === null) {
            return;
        }

        $data['captcha_code'] = $captchaCode;
        $data['captcha_state'] = $state;
        $this->save($sessionId, $data);

        $connection = $this->createConnection($sessionId);
        $connection?->setCaptcha($captchaCode, $state);
    }

    public function persistCookies(string $sessionId, Connection $connection): void
    {
        $data = $this->load($sessionId);
        if ($data === null) {
            return;
        }

        $jar = $connection->getHttpClient()->getConfig('cookies');
        if ($jar instanceof CookieJar) {
            $data['cookies'] = $jar->toArray();
            $this->save($sessionId, $data);
        }
    }

    private function createConnection(string $sessionId): ?Connection
    {
        $data = $this->load($sessionId);
        if ($data === null) {
            return null;
        }

        $storedCookies = $data['cookies'] ?? [];
        $jar = is_array($storedCookies) && $storedCookies !== []
            ? new CookieJar(false, $storedCookies)
            : new CookieJar();

        $client = new Client(['cookies' => $jar]);

        return new Connection(
            $data['email'] ?? null,
            $data['password'] ?? null,
            is_string($data['captcha_code'] ?? null) ? $data['captcha_code'] : null,
            is_string($data['captcha_state'] ?? null) ? $data['captcha_state'] : null,
            $client,
            is_array($data['token'] ?? null) ? $data['token'] : [],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $sessionId): ?array
    {
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(string $sessionId, array $data): void
    {
        $path = $this->path($sessionId);
        $written = file_put_contents(
            $path,
            json_encode($data, JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        if ($written === false) {
            throw new \RuntimeException(
                'Could not write session file: ' . $path
                . '. Fix permissions, e.g.: sudo chmod 664 ' . $path
                . ' && sudo chown www-data:hcmedia ' . $path,
            );
        }

        @chmod($path, 0660);
    }

    private function path(string $sessionId): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', $sessionId);

        return self::STORAGE_DIR . '/' . $safe . '.json';
    }
}
