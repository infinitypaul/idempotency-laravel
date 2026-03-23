<?php

namespace Infinitypaul\Idempotency\Data;

use Symfony\Component\HttpFoundation\Response;

final class CachedResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $content,
    )
    {
    }

    public static function fromResponse(Response $response): self
    {
        return new self(
            status: $response->getStatusCode(),
            headers: $response->headers->all(),
            content: (string)$response->getContent(),
        );
    }

    /** @param array{status: int, headers: array<string, string|array<string>>, content: string} $response */
    public static function fromArray(array $response): self
    {
        return new self(
            status: $response['status'],
            headers: $response['headers'],
            content: $response['content'],
        );
    }

    public function toResponse(): Response
    {
        $response = response($this->content, $this->status);

        foreach ($this->headers as $name => $values) {
            foreach ((array)$values as $value) {
                $response->headers->set($name, $value, false);
            }
        }

        return $response;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'content' => $this->content,
        ];
    }
}
