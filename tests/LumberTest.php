<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests;

use InfiniteToken\Lumber\Internal\Answer;
use InfiniteToken\Lumber\Level;
use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Tests\Support\ContractFiles;
use InfiniteToken\Lumber\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

enum Plan: string
{
    case Pro = 'pro';
}

final class LumberTest extends TestCase
{
    private string $key;
    private FakeTransport $transport;
    private ?Lumber $client = null;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
        $this->transport = new FakeTransport();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidApiKeys(): iterable
    {
        yield 'empty' => ['', 'Missing API key'];
        yield 'whitespace' => ["  \n", 'Missing API key'];
        yield 'a bare token' => ['3f9c0e7a1b2d4c6e8f0a1b2c3d4e5f60', 'has no encryption key'];
        yield 'a dot and nothing after' => ['3f9c0e7a.', 'has no encryption key'];
        yield 'not base64' => ['3f9c0e7a.not*base64', 'should be 32 bytes of base64, got 0'];
        yield 'a short key' => ['3f9c0e7a.' . base64_encode('sixteen bytes!!!'), 'should be 32 bytes of base64, got 16'];
    }

    #[DataProvider('invalidApiKeys')]
    public function testRefusesAnInvalidApiKey(string $apiKey, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new Lumber($apiKey);
    }

    public function testRefusesAnEndpointThatIsntAUrl(): void
    {
        $this->expectExceptionMessage('The endpoint must be an http or https URL, got "api.lumber.infinitetoken.com"');
        new Lumber($this->apiKey(), endpoint: 'api.lumber.infinitetoken.com');
    }

    public function testIsAPsr3Logger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, $this->lumber());
    }

    public function testMapsPsr3LevelsToLumbers(): void
    {
        $lumber = $this->lumber();
        $lumber->debug('debug');
        $lumber->info('info');
        $lumber->notice('notice');
        $lumber->warning('warning');
        $lumber->warn('warn');
        $lumber->error('error');
        $lumber->critical('critical');
        $lumber->alert('alert');
        $lumber->emergency('emergency');
        $lumber->fatal('fatal');
        $lumber->log(LogLevel::INFO, 'log');
        $lumber->log(Level::Warn, 'level');
        $lumber->log('FATAL', 'name');
        $lumber->flush();

        $levels = [];
        foreach ($this->transport->sent() as $body) {
            $levels[$this->payload($body)->message] = $body->level;
        }
        self::assertSame([
            'debug' => 0, 'info' => 1, 'notice' => 1, 'warning' => 2, 'warn' => 2, 'error' => 3, 'critical' => 4,
            'alert' => 4, 'emergency' => 4, 'fatal' => 4, 'log' => 1, 'level' => 2, 'name' => 4,
        ], $levels);
    }

    public function testAnUnknownLevelThrowsAsPsr3Asks(): void
    {
        $this->expectException(PsrInvalidArgumentException::class);
        $this->lumber()->log('verbose', 'Hello');
    }

    public function testTheContextIsTheLogsData(): void
    {
        $lumber = $this->lumber(environment: 'production');
        $lumber->info('Order placed', [
            'request_id' => 'req-123',
            'trace_id' => 'trace-abc',
            'context' => ['region' => 'us-east'],
            'metadata' => ['plan' => 'pro'],
            'order_id' => 789,
        ]);

        $payload = $this->sentPayload();
        self::assertSame('req-123', $payload->requestId);
        self::assertSame('trace-abc', $payload->traceId);
        ContractFiles::assertSameJson((object) [
            'pid' => getmypid(),
            'hostname' => gethostname(),
            'env' => 'production',
            'region' => 'us-east',
        ], $payload->context);
        ContractFiles::assertSameJson((object) ['plan' => 'pro', 'order_id' => 789], $payload->metadata);
    }

    public function testTheApisNamesWinOverPhps(): void
    {
        $this->lumber()->info('Hello', ['requestId' => 'api', 'request_id' => 'php', 'trace_id' => null]);

        $payload = $this->sentPayload();
        self::assertSame('api', $payload->requestId);
        self::assertObjectNotHasProperty('traceId', $payload);
        self::assertObjectNotHasProperty('metadata', $payload);
    }

    public function testAnExceptionIsSentAsItsClassMessageAndTrace(): void
    {
        $previous = new \LogicException('No card on file');
        $this->lumber()->error('Payment failed', ['exception' => new \RuntimeException('Card declined', 0, $previous)]);

        $metadata = $this->sentPayload()->metadata;
        self::assertSame('RuntimeException: Card declined', $metadata->exception);
        self::assertStringContainsString('RuntimeException: Card declined in ' . __FILE__, $metadata->trace);
        self::assertStringContainsString('Stack trace:', $metadata->trace);
        self::assertStringContainsString('LogicException: No card on file', $metadata->trace);
    }

    public function testFillsPsr3Placeholders(): void
    {
        $this->lumber()->info('{user} paid {amount} on {when} ({paid}, {missing})', [
            'user' => 'Zoë',
            'amount' => 12.5,
            'when' => new \DateTimeImmutable('2026-11-09T10:00:00Z'),
            'paid' => true,
        ]);

        $payload = $this->sentPayload();
        self::assertSame('Zoë paid 12.5 on 2026-11-09T10:00:00+00:00 (true, {missing})', $payload->message);
        self::assertSame('Zoë', $payload->metadata->user, 'the values are still sent');
    }

    public function testValuesJsonCantHoldAreSentAsText(): void
    {
        $node = new \stdClass();
        $node->self = $node;
        $file = fopen('php://memory', 'r');
        $this->lumber()->info('Odd values', [
            'nan' => \NAN,
            'when' => new \DateTimeImmutable('2026-11-09T10:00:00.250Z'),
            'plan' => Plan::Pro,
            'level' => Level::Error,
            'node' => $node,
            'file' => $file,
            'closure' => static fn() => null,
            'empty' => new \stdClass(),
            'numbered' => (object) ['7' => 'seven'],
            'bytes' => "caf\xE9",
            'object' => new class {
                public int $id = 7;
                private string $secret = 'hidden';
            },
        ]);

        ContractFiles::assertSameJson((object) [
            'nan' => null,
            'when' => '2026-11-09T10:00:00.250+00:00',
            'plan' => 'pro',
            'level' => 3,
            'node' => (object) ['self' => '[Circular]'],
            'file' => 'resource (stream)',
            'closure' => 'Closure',
            'empty' => new \stdClass(),
            'numbered' => (object) ['7' => 'seven'],
            'bytes' => "caf\u{FFFD}",
            'object' => (object) ['id' => 7],
        ], $this->sentPayload()->metadata);
    }

    public function testAutoContextCanBeTurnedOff(): void
    {
        $this->lumber(autoContext: false, environment: 'production')->info('Hello');

        self::assertObjectNotHasProperty('context', $this->sentPayload());
    }

    public function testCallerSendsTheFileLineAndFunctionThatLogged(): void
    {
        $lumber = $this->lumber(autoContext: false, caller: true);
        $lumber->info('Direct');
        $line = __LINE__ - 1;
        $lumber->warn('Through warn');
        $lumber->log(LogLevel::ERROR, 'Through log');
        $lumber->flush();

        $contexts = array_map(fn(\stdClass $body): mixed => $this->payload($body)->context, $this->transport->sent());
        $method = self::class . '::' . __FUNCTION__;
        ContractFiles::assertSameJson((object) ['code' => __FILE__, 'line' => $line, 'method' => $method], $contexts[0]);
        ContractFiles::assertSameJson((object) ['code' => __FILE__, 'line' => $line + 2, 'method' => $method], $contexts[1]);
        ContractFiles::assertSameJson((object) ['code' => __FILE__, 'line' => $line + 3, 'method' => $method], $contexts[2]);
    }

    public function testCallerDepthSkipsWrappers(): void
    {
        $lumber = $this->lumber(autoContext: false, caller: true, callerDepth: 1);
        $log = static function (string $message) use ($lumber): void {
            $lumber->info($message);
        };
        $log('Wrapped');
        $line = __LINE__ - 1;

        $context = $this->sentPayload()->context;
        self::assertSame(__FILE__, $context->code);
        self::assertSame($line, $context->line);
        self::assertSame(self::class . '::' . __FUNCTION__, $context->method);
    }

    public function testHoldsLogsUntilAFlush(): void
    {
        $lumber = $this->lumber();
        $lumber->info('One');
        $lumber->info('Two');

        self::assertSame(2, $lumber->pending());
        self::assertSame([], $this->transport->bodies);
        self::assertTrue($lumber->flush());
        self::assertCount(2, $this->transport->bodies);
        self::assertSame(0, $lumber->pending());
    }

    public function testSyncSendsEachLogAsItsLogged(): void
    {
        $lumber = $this->lumber(sync: true);
        $lumber->info('One');

        self::assertCount(1, $this->transport->bodies);
        self::assertSame(0, $lumber->pending());
    }

    public function testCloseSendsWhatsHeldAndDropsLaterLogs(): void
    {
        $lumber = $this->lumber();
        $lumber->info('Before');

        self::assertTrue($lumber->close());
        $lumber->info('After');
        self::assertSame(0, $lumber->pending());
        self::assertCount(1, $this->transport->bodies);
    }

    public function testAClientLetGoOfSendsWhatItHolds(): void
    {
        $lumber = $this->lumber();
        $lumber->info('Last words');
        $this->client = null;
        unset($lumber);

        self::assertCount(1, $this->transport->bodies);
    }

    public function testWarningsGoToTheCallback(): void
    {
        $this->transport->answer(new Answer(401, body: '{"message":"Invalid token"}'));
        $lumber = $this->lumber();
        $lumber->info('Hello');
        $lumber->flush();

        self::assertSame(['log refused: HTTP 401 (Invalid token)'], $this->warnings);
    }

    public function testOnlyTheLevelIsReadable(): void
    {
        $lumber = $this->lumber();
        $lumber->error('Card 4242 declined', ['email' => 'zoe@example.com']);
        $lumber->flush();

        [$body] = $this->transport->bodies;
        self::assertStringNotContainsString('4242', $body);
        self::assertStringNotContainsString('zoe@example.com', $body);
        self::assertSame(['level', 'encrypted'], array_keys((array) json_decode($body)));
    }

    private function apiKey(): string
    {
        return '3f9c0e7a1b2d4c6e8f0a1b2c3d4e5f60.' . base64_encode($this->key);
    }

    private function lumber(
        ?string $environment = null,
        bool $autoContext = true,
        bool $caller = false,
        int $callerDepth = 0,
        bool $sync = false,
    ): Lumber {
        return $this->client = new Lumber(
            $this->apiKey(),
            environment: $environment,
            autoContext: $autoContext,
            caller: $caller,
            callerDepth: $callerDepth,
            sync: $sync,
            warnings: function (string $message): void {
                $this->warnings[] = $message;
            },
            transport: $this->transport,
        );
    }

    private function payload(\stdClass $body): \stdClass
    {
        $payload = ContractFiles::decrypt($body->encrypted, $this->key);
        self::assertInstanceOf(\stdClass::class, $payload);
        ContractFiles::assertConforms('LogPayload', $payload);

        return $payload;
    }

    /**
     * The first log the test's client sent, flushing it first.
     */
    private function sentPayload(): \stdClass
    {
        self::assertNotNull($this->client);
        $this->client->flush();
        $sent = $this->transport->sent();
        self::assertNotEmpty($sent, 'Nothing was sent');

        return $this->payload($sent[0]);
    }
}
