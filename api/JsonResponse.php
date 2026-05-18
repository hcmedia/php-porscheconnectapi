<?php

declare(strict_types=1);

namespace PorscheConnect\Api;

final class JsonResponse
{
    /**
     * @param array<string, mixed>|list<mixed>|string|null $data
     */
    public static function send(
        mixed $data = null,
        int $status = 200,
        ?array $headers = null,
    ): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        if ($headers !== null) {
            foreach ($headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function error(string $message, int $status = 400, array $error = []): never
    {
        self::send([
            'error' => $message,
            ...$error,
        ], $status);
    }
}
