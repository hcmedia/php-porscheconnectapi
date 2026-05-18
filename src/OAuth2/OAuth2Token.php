<?php

declare(strict_types=1);

namespace PorscheConnect\OAuth2;

class OAuth2Token implements \ArrayAccess
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params = [])
    {
        $this->data = $params;

        if (isset($params['expires_at'])) {
            $this->data['expires_at'] = (int) $params['expires_at'];
        } elseif (isset($params['expires_in'])) {
            $this->setExpiresAt((int) $params['expires_in']);
        }
    }

    public function isExpired(int $leeway = 60): ?bool
    {
        $expiresAt = $this->data['expires_at'] ?? null;
        if ($expiresAt === null) {
            return null;
        }

        return ($expiresAt - $leeway) < time();
    }

    public function getExpiresAt(): ?int
    {
        return isset($this->data['expires_at']) ? (int) $this->data['expires_at'] : null;
    }

    public function getAccessToken(): ?string
    {
        return isset($this->data['access_token']) ? (string) $this->data['access_token'] : null;
    }

    public function getRefreshToken(): ?string
    {
        return isset($this->data['refresh_token']) ? (string) $this->data['refresh_token'] : null;
    }

    public function setExpiresAt(int $expiresIn): void
    {
        $this->data['expires_at'] = time() + $expiresIn;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function update(array $params): void
    {
        $this->data = array_merge($this->data, $params);

        if (isset($params['expires_in']) && !isset($params['expires_at'])) {
            $this->setExpiresAt((int) $params['expires_in']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}
