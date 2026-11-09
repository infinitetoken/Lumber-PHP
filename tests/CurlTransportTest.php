<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests;

use InfiniteToken\Lumber\Internal\CurlTransport;
use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Tests\Support\ContractFiles;
use InfiniteToken\Lumber\Tests\Support\Server;
use PHPUnit\Framework\TestCase;

/**
 * curl against a real HTTP server standing in for Lumber's API.
 */
final class CurlTransportTest extends TestCase
{
    protected function setUp(): void
    {
        Server::url();
        Server::clear();
    }

    public function testPostsTheBodyWithOnlyTheTokenAsBearer(): void
    {
        // Over 1 KB, where curl would otherwise ask for a 100 Continue first
        $body = json_encode(['level' => 1, 'encrypted' => ['nonce' => 'n', 'payload' => str_repeat('A', 2048), 'keyVersion' => 1]]);
        $answer = $this->transport()->post((string) $body, 5.0);

        self::assertSame(201, $answer->status);
        [$request] = Server::requests();
        self::assertSame('POST', $request['method']);
        self::assertSame('/logs', $request['path']);
        self::assertSame($body, $request['body']);
        self::assertSame('Bearer the-token', $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame('lumber-php/' . Lumber::VERSION . ' php/' . \PHP_VERSION, $request['headers']['user-agent']);
        self::assertArrayNotHasKey('expect', $request['headers']);
    }

    public function testReturnsTheStatusRetryAfterAndMessage(): void
    {
        $answer = $this->transport('/status/429/retry-after/86400')->post('{}', 5.0);

        self::assertSame(429, $answer->status);
        self::assertSame('86400', $answer->retryAfter);
        self::assertSame('Refused with HTTP 429', $answer->message());
    }

    public function testDoesntFollowARedirect(): void
    {
        self::assertSame(301, $this->transport('/status/301')->post('{}', 5.0)->status);
    }

    public function testNoAnswerInTimeIsAnError(): void
    {
        $started = microtime(true);
        $answer = $this->transport('/sleep/1000')->post('{}', 0.3);

        self::assertNull($answer->status);
        self::assertNotNull($answer->error);
        self::assertLessThan(0.9, microtime(true) - $started);
    }

    public function testNothingListeningIsAnError(): void
    {
        $answer = (new CurlTransport('http://127.0.0.1:' . Server::closedPort() . '/logs', 'the-token', 'test'))->post('{}', 2.0);

        self::assertNull($answer->status);
        self::assertNotNull($answer->error);
    }

    public function testTheClientSendsLogsTheAppCanOpen(): void
    {
        $key = random_bytes(32);
        $lumber = new Lumber('the-token.' . base64_encode($key), environment: 'test', endpoint: Server::url() . '/');
        $lumber->info('Server started', ['port' => 8080]);
        $lumber->error('Payment failed', ['request_id' => 'req-1']);
        self::assertTrue($lumber->flush());

        $requests = Server::requests();
        self::assertCount(2, $requests);
        $messages = [];
        foreach ($requests as $request) {
            self::assertSame('Bearer the-token', $request['headers']['authorization']);
            $body = json_decode($request['body'], false, 512, \JSON_THROW_ON_ERROR);
            ContractFiles::assertConforms('LogIngestBody', $body);
            $payload = ContractFiles::decrypt($body->encrypted, $key);
            ContractFiles::assertConforms('LogPayload', $payload);
            $messages[] = $payload->message;
        }
        self::assertSame(['Server started', 'Payment failed'], $messages, 'in the order they were logged');
    }

    private function transport(string $path = ''): CurlTransport
    {
        return new CurlTransport(Server::url() . $path . '/logs', 'the-token', 'lumber-php/' . Lumber::VERSION . ' php/' . \PHP_VERSION);
    }
}
