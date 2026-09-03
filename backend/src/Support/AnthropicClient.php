<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\BadRequestException;
use RuntimeException;

/**
 * Thin cURL wrapper around the Anthropic Messages API. Deliberately minimal --
 * this app has no HTTP client dependency anywhere else, so a hand-rolled call
 * fits its existing "no framework, wire it by hand" style rather than pulling
 * in Guzzle for one endpoint.
 */
final class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Sends one Messages API request with a single forced tool call and
     * returns that tool's parsed input. Forcing tool use is what keeps the
     * response machine-parseable instead of prose that happens to contain JSON.
     *
     * @param array<int, array<string, mixed>> $content the user message's content blocks
     * @param array<string, mixed> $tool a single tool definition (name, description, input_schema)
     * @return array<string, mixed> the tool_use block's `input`
     */
    public function callTool(array $content, array $tool, int $maxTokens = 4096): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $content]],
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_POSTFIELDS => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new RuntimeException("Could not reach the Anthropic API: {$error}");
        }

        $decoded = json_decode($raw, true);

        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new RuntimeException("Anthropic API request failed ({$status}): {$message}");
        }

        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $tool['name']) {
                $input = $block['input'] ?? null;

                if (!is_array($input)) {
                    throw new BadRequestException('The AI response did not include the expected structured data.');
                }

                return $input;
            }
        }

        throw new BadRequestException('The AI did not return a structured result for this file.');
    }
}
