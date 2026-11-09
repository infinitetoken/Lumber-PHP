<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * Logs as JSON the Lumber apps can always parse.
 *
 * @internal
 */
final class Json
{
    private const FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    private const MAX_DEPTH = 64;

    /**
     * Compact UTF-8 JSON of a value normalize() has made safe. Text that isn't valid UTF-8 has its bad bytes replaced.
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /**
     * A value as JSON can hold it, rather than a log being lost or sent as `{}`: a date as ISO 8601, an exception as its
     * class and message, an enum as its value, a JsonSerializable as what it serializes to, a Stringable as its text,
     * another object as its public properties (or its class when it has none), a NaN or infinity as null, and an object
     * that contains itself as "[Circular]". stdClass stays an object, so an empty one is still `{}`.
     *
     * @param array<int, true> $seen
     */
    public static function normalize(mixed $value, int $depth = 0, array &$seen = []): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '[Too deep]';
        }
        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item, $depth + 1, $seen);
            }

            return $normalized;
        }
        if (\is_object($value)) {
            return self::object($value, $depth, $seen);
        }

        // A resource, open or closed
        return get_debug_type($value);
    }

    /**
     * @param array<int, true> $seen
     */
    private static function object(object $value, int $depth, array &$seen): mixed
    {
        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            return '[Circular]';
        }
        $seen[$id] = true;

        try {
            return match (true) {
                $value instanceof \DateTimeInterface => $value->format(\DATE_RFC3339_EXTENDED),
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \UnitEnum => $value->name,
                $value instanceof \Throwable => $value::class . ': ' . $value->getMessage(),
                $value instanceof \JsonSerializable => self::normalize($value->jsonSerialize(), $depth + 1, $seen),
                $value instanceof \Stringable => (string) $value,
                $value instanceof \Closure => 'Closure',
                $value instanceof \stdClass => (object) self::properties($value, $depth, $seen),
                default => ($properties = self::properties($value, $depth, $seen)) === [] ? $value::class : (object) $properties,
            };
        } catch (\Throwable) {
            // A jsonSerialize() or __toString() that throws
            return $value::class;
        } finally {
            unset($seen[$id]);
        }
    }

    /**
     * @param array<int, true> $seen
     *
     * @return array<array-key, mixed>
     */
    private static function properties(object $value, int $depth, array &$seen): array
    {
        $properties = [];
        foreach (get_object_vars($value) as $name => $item) {
            $properties[$name] = self::normalize($item, $depth + 1, $seen);
        }

        return $properties;
    }
}
