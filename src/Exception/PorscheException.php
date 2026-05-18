<?php

declare(strict_types=1);

namespace PorscheConnect\Exception;

class PorscheException extends \RuntimeException
{
    public function __construct(
        public readonly int|string|null $statusCode = null,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        if ($message === '' && $statusCode !== null) {
            $message = $this->resolveMessage($statusCode);
        }

        parent::__construct($message, is_int($statusCode) ? $statusCode : 0, $previous);
    }

    private function resolveMessage(int|string $statusCode): string
    {
        if (is_string($statusCode)) {
            return $statusCode;
        }

        return match ($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            404 => 'NOT_FOUND',
            405 => 'MOBILE_ACCESS_DISABLED',
            408 => 'VEHICLE_UNAVAILABLE',
            423 => 'ACCOUNT_LOCKED',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'SERVER_ERROR',
            503 => 'SERVICE_MAINTENANCE',
            504 => 'UPSTREAM_TIMEOUT',
            default => is_int($statusCode) && $statusCode > 299 ? "UNKNOWN_ERROR_{$statusCode}" : '',
        };
    }
}

