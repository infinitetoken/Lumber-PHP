<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * Lumber-Kit's contract in PHP: the constants, API key format, payload and delivery rules every Lumber plugin shares.
 * tests/contract holds the kit's schema and known answers, which everything here is tested against.
 *
 * @internal
 */
final class Contract
{
    public const DEFAULT_ENDPOINT = 'https://api.lumber.infinitetoken.com';
    public const INGEST_PATH = '/logs';
    public const KEY_VERSION = 1;
    public const KEY_BYTES = 32;
    public const NONCE_BYTES = 12;
    public const TAG_BYTES = 16;

    // Delivery (Lumber-Kit's logDelivery), in milliseconds
    public const MAX_ATTEMPTS = 5;
    public const BASE_DELAY_MS = 1_000;
    public const MAX_DELAY_MS = 30_000;
    public const PAUSE_MS = 3_600_000;

    /**
     * An API key's token and encryption key (`<token>.<encryptionKey>`). A value with no dot is all token.
     *
     * @return array{string, ?string}
     */
    public static function splitApiKey(string $apiKey): array
    {
        $parts = explode('.', trim($apiKey), 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * The plaintext a log call encrypts. requestId and traceId become the payload's own fields, the call's context goes
     * over the automatic context, and every other key goes into metadata, where an explicit metadata key wins. A context
     * or metadata that isn't a map is kept as an ordinary metadata key, and a null one is left out.
     *
     * A map is an array with keys (or an empty one) or a stdClass; an array that's a list isn't. The payload's context and
     * metadata are objects, so they're JSON objects even when empty or numbered.
     *
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $autoContext
     *
     * @return array<string, mixed>
     */
    public static function buildPayload(int $level, string $message, array $data = [], array $autoContext = []): array
    {
        $rest = $data;
        $requestId = self::take($rest, 'requestId');
        $traceId = self::take($rest, 'traceId');
        $givenContext = self::take($rest, 'context');
        $givenMetadata = self::take($rest, 'metadata');

        $context = self::asMap($givenContext);
        $metadata = self::asMap($givenMetadata);
        if ($givenContext !== null && $context === null) {
            $rest['context'] = $givenContext;
        }
        if ($givenMetadata !== null && $metadata === null) {
            $rest['metadata'] = $givenMetadata;
        }

        $payload = ['level' => $level, 'message' => $message];
        if ($requestId !== null) {
            $payload['requestId'] = $requestId;
        }
        if ($traceId !== null) {
            $payload['traceId'] = $traceId;
        }
        if ($autoContext !== []) {
            $payload['context'] = (object) array_replace($autoContext, $context ?? []);
        } elseif ($context !== null) {
            $payload['context'] = (object) $context;
        }
        if ($rest !== []) {
            $payload['metadata'] = (object) array_replace($rest, $metadata ?? []);
        } elseif ($metadata !== null) {
            $payload['metadata'] = (object) $metadata;
        }

        return $payload;
    }

    /**
     * What to do with a log once POST /logs answers with `$status`, or null for no answer (a network error).
     */
    public static function deliveryOutcome(?int $status): Outcome
    {
        return match (true) {
            $status === null => Outcome::Retry,
            $status >= 200 && $status < 300 => Outcome::Sent,
            $status === 429 => Outcome::Pause,
            $status >= 400 && $status < 500 => Outcome::Drop,
            default => Outcome::Retry,
        };
    }

    /**
     * Milliseconds to wait before trying a log again after its nth failure: 1s, 2s, 4s and so on, up to 30s.
     */
    public static function retryDelayMs(int $failedAttempts): int
    {
        return min(self::BASE_DELAY_MS * 2 ** min(max(0, $failedAttempts - 1), 30), self::MAX_DELAY_MS);
    }

    /**
     * @param array<array-key, mixed> $map
     */
    private static function take(array &$map, string $key): mixed
    {
        $value = $map[$key] ?? null;
        unset($map[$key]);

        return $value;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function asMap(mixed $value): ?array
    {
        if (\is_array($value)) {
            return $value === [] || !array_is_list($value) ? $value : null;
        }
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return null;
    }
}
