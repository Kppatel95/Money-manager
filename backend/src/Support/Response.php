<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small helper for emitting consistent JSON responses.
 */
final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = ['error' => $message];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        self::json($payload, $status);
    }
}
