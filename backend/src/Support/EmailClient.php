<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Thin cURL wrapper around the Resend API. Same reasoning as
 * AnthropicClient: this app has no HTTP client dependency anywhere, so a
 * hand-rolled call fits its existing style better than a mail library for
 * the one email this app sends.
 */
final class EmailClient
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $from
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function send(string $to, string $subject, string $html): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }

        $payload = [
            'from' => $this->from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Could not reach the Resend API: {$error}");
        }

        if ($status >= 400) {
            $decoded = json_decode($raw, true);
            $message = is_array($decoded) ? ($decoded['message'] ?? $raw) : $raw;

            throw new RuntimeException("Resend API request failed ({$status}): {$message}");
        }
    }
}
