<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * Lumber-Kit's contract (tests/contract): its known answers, its JSON Schema, and decrypting what a client sent.
 */
final class ContractFiles
{
    private const SCHEMA_ID = 'https://unpkg.com/@infinitetoken/lumber-kit/contract/schema.json';

    private static ?Validator $validator = null;

    /**
     * vectors.json with JSON objects as arrays, as a PHP caller passes data.
     *
     * @return array<string, mixed>
     */
    public static function vectors(): array
    {
        $vectors = json_decode(self::read('vectors.json'), true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($vectors);

        return $vectors;
    }

    /**
     * vectors.json with JSON objects as stdClass, to compare what was sent with, `{}` and `[]` kept apart.
     */
    public static function vectorObjects(): \stdClass
    {
        $vectors = json_decode(self::read('vectors.json'), false, 512, \JSON_THROW_ON_ERROR);
        Assert::assertInstanceOf(\stdClass::class, $vectors);

        return $vectors;
    }

    /**
     * Asserts a value, as JSON, is valid against one of the schema's definitions (LogPayload, LogIngestBody).
     */
    public static function assertConforms(string $definition, mixed $value): void
    {
        $data = json_decode(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), false, 512, \JSON_THROW_ON_ERROR);
        $result = self::validator()->validate($data, self::SCHEMA_ID . '#/definitions/' . $definition);
        $errors = $result->error() !== null ? (new ErrorFormatter())->format($result->error()) : [];
        Assert::assertTrue($result->isValid(), "Not a valid {$definition}: " . json_encode($errors));
    }

    /**
     * Asserts two decoded JSON values are the same: types strictly (1 isn't "1", {} isn't []), and an object's keys in
     * any order.
     */
    public static function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        Assert::assertSame(self::canonical($expected), self::canonical($actual), $message);
    }

    /**
     * Opens a POST /logs body's `encrypted` with the app's key, as the Lumber apps do.
     */
    public static function decrypt(\stdClass $encrypted, string $key): mixed
    {
        $nonce = base64_decode((string) $encrypted->nonce, true);
        $sealed = base64_decode((string) $encrypted->payload, true);
        Assert::assertIsString($nonce);
        Assert::assertIsString($sealed);
        $plaintext = openssl_decrypt(substr($sealed, 16), 'aes-256-gcm', $key, \OPENSSL_RAW_DATA, $nonce, substr($sealed, 0, 16));
        Assert::assertIsString($plaintext, "Doesn't open with the key");

        return json_decode($plaintext, false, 512, \JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = array_map(self::canonical(...), get_object_vars($value));
            ksort($properties, \SORT_STRING);

            return ['{}' => $properties];
        }
        if (\is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator();
            self::$validator->resolver()?->registerFile(self::SCHEMA_ID, __DIR__ . '/../contract/schema.json');
        }

        return self::$validator;
    }

    private static function read(string $name): string
    {
        $json = file_get_contents(__DIR__ . '/../contract/' . $name);
        Assert::assertIsString($json);

        return $json;
    }
}
