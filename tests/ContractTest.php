<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests;

use InfiniteToken\Lumber\Internal\Contract;
use InfiniteToken\Lumber\Internal\Crypto;
use InfiniteToken\Lumber\Level;
use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Tests\Support\ContractFiles;
use InfiniteToken\Lumber\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lumber-Kit's known answers (tests/contract), which Lumber's Node, Python and Go clients pass too.
 */
final class ContractTest extends TestCase
{
    public function testConstants(): void
    {
        $constants = ContractFiles::vectors()['constants'];

        self::assertSame($constants['defaultEndpoint'], Contract::DEFAULT_ENDPOINT);
        self::assertSame($constants['defaultEndpoint'], Lumber::DEFAULT_ENDPOINT);
        self::assertSame($constants['ingestPath'], Contract::INGEST_PATH);
        self::assertSame($constants['keyVersion'], Contract::KEY_VERSION);
        self::assertSame($constants['keyBytes'], Contract::KEY_BYTES);
        self::assertSame($constants['nonceBytes'], Contract::NONCE_BYTES);
        self::assertSame($constants['tagBytes'], Contract::TAG_BYTES);
        self::assertSame($constants['levels'], [
            'debug' => Level::Debug->value,
            'info' => Level::Info->value,
            'warn' => Level::Warn->value,
            'error' => Level::Error->value,
            'fatal' => Level::Fatal->value,
        ]);
        self::assertSame($constants['delivery'], [
            'maxAttempts' => Contract::MAX_ATTEMPTS,
            'baseDelayMs' => Contract::BASE_DELAY_MS,
            'maxDelayMs' => Contract::MAX_DELAY_MS,
            'pauseMs' => Contract::PAUSE_MS,
        ]);
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('apiKeys')]
    public function testApiKeys(array $vector): void
    {
        self::assertSame([$vector['token'], $vector['encryptionKey']], Contract::splitApiKey($vector['apiKey']));
    }

    /**
     * @param array<string, string> $vector
     */
    #[DataProvider('encryption')]
    public function testEncryption(array $vector): void
    {
        $key = base64_decode($vector['key'], true);
        $nonce = base64_decode($vector['nonce'], true);
        self::assertIsString($key);
        self::assertIsString($nonce);

        self::assertSame($vector['payload'], base64_encode(Crypto::seal($key, $vector['plaintext'], $nonce)));
    }

    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('payloads')]
    public function testPayloads(array $vector, \stdClass $expected): void
    {
        $payload = Contract::buildPayload($vector['level'], $vector['message'], $vector['data'] ?? [], $vector['autoContext'] ?? []);

        ContractFiles::assertSameJson($expected->payload, self::asJson($payload));
        ContractFiles::assertConforms('LogPayload', $payload);
    }

    /**
     * Through the whole client: what it sends is a LogIngestBody whose plaintext is the vector's payload. The data goes
     * in with the API's names, which the client takes as they are.
     *
     * @param array<string, mixed> $vector
     */
    #[DataProvider('clientPayloads')]
    public function testClientSendsPayloads(array $vector, \stdClass $expected): void
    {
        $transport = new FakeTransport();
        $key = random_bytes(32);
        $lumber = new Lumber('token.' . base64_encode($key), autoContext: false, transport: $transport);

        $lumber->log(Level::from($vector['level']), $vector['message'], $vector['data'] ?? []);
        self::assertTrue($lumber->flush());

        [$body] = $transport->sent();
        ContractFiles::assertConforms('LogIngestBody', $body);
        $constants = ContractFiles::vectors()['constants'];
        self::assertSame($vector['level'], $body->level);
        self::assertSame($constants['keyVersion'], $body->encrypted->keyVersion);
        self::assertSame($constants['nonceBytes'], \strlen((string) base64_decode($body->encrypted->nonce, true)));
        ContractFiles::assertSameJson($expected->payload, ContractFiles::decrypt($body->encrypted, $key));
    }

    public function testDeliveryOutcomes(): void
    {
        foreach (ContractFiles::vectors()['deliveryOutcomes'] as $vector) {
            self::assertSame($vector['outcome'], Contract::deliveryOutcome($vector['status'])->value, "status {$vector['status']}");
        }
    }

    public function testRetryDelays(): void
    {
        foreach (ContractFiles::vectors()['retryDelaysMs'] as $vector) {
            self::assertSame($vector['delayMs'], Contract::retryDelayMs($vector['failedAttempts']), "after {$vector['failedAttempts']} failures");
        }
        self::assertSame(Contract::MAX_DELAY_MS, Contract::retryDelayMs(200), 'a delay past the cap is the cap');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function apiKeys(): iterable
    {
        foreach (ContractFiles::vectors()['apiKeys'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function encryption(): iterable
    {
        foreach (ContractFiles::vectors()['encryption'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /**
     * Each payload vector twice over: as PHP arrays, the way a caller passes data, and as objects to compare with.
     *
     * @return iterable<string, array{array<string, mixed>, \stdClass}>
     */
    public static function payloads(): iterable
    {
        $objects = ContractFiles::vectorObjects()->payloads;
        foreach (ContractFiles::vectors()['payloads'] as $index => $vector) {
            yield $vector['name'] => [$vector, $objects[$index]];
        }
    }

    /**
     * The payload vectors without an automatic context, since the client makes its own (the process id and hostname).
     *
     * @return iterable<string, array{array<string, mixed>, \stdClass}>
     */
    public static function clientPayloads(): iterable
    {
        foreach (self::payloads() as $name => [$vector, $expected]) {
            if (!\array_key_exists('autoContext', $vector)) {
                yield $name => [$vector, $expected];
            }
        }
    }

    private static function asJson(mixed $value): mixed
    {
        return json_decode(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), false, 512, \JSON_THROW_ON_ERROR);
    }
}
