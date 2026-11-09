<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * How POST /logs answered one log: its status, or null and an error when there was no answer.
 *
 * @internal
 */
final class Answer
{
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $retryAfter = null,
        public readonly string $body = '',
        public readonly ?string $error = null,
    ) {}

    /**
     * The API's error message (`{ "message": ... }`), when it sent one.
     */
    public function message(): ?string
    {
        try {
            $parsed = json_decode($this->body, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($parsed) && \is_string($parsed['message'] ?? null) && $parsed['message'] !== '' ? $parsed['message'] : null;
    }
}
