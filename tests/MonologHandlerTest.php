<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests;

use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Monolog\LumberHandler;
use InfiniteToken\Lumber\Tests\Support\ContractFiles;
use InfiniteToken\Lumber\Tests\Support\FakeTransport;
use Monolog\Handler\TestHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;

final class MonologHandlerTest extends TestCase
{
    private string $key;
    private FakeTransport $transport;
    private Lumber $lumber;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
        $this->transport = new FakeTransport();
        $this->lumber = new Lumber('token.' . base64_encode($this->key), autoContext: false, transport: $this->transport);
    }

    public function testSendsEachRecordAtLumbersLevel(): void
    {
        $logger = new Logger('app', [new LumberHandler($this->lumber)]);
        foreach (MonologLevel::cases() as $level) {
            $logger->log($level, $level->getName());
        }
        $this->lumber->flush();

        $levels = [];
        foreach ($this->transport->sent() as $body) {
            $levels[$this->payload($body)->message] = $body->level;
        }
        self::assertSame([
            'DEBUG' => 0, 'INFO' => 1, 'NOTICE' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4, 'ALERT' => 4, 'EMERGENCY' => 4,
        ], $levels);
    }

    public function testTheContextAndExtraAreTheLogsData(): void
    {
        $logger = new Logger('billing', [new LumberHandler($this->lumber)]);
        $logger->pushProcessor(static fn(LogRecord $record): LogRecord => $record->with(extra: ['url' => '/checkout', 'host' => 'extra']));
        $logger->error('Payment failed', [
            'request_id' => 'req-123',
            'exception' => new \RuntimeException('Card declined'),
            'context' => ['region' => 'us-east'],
            'host' => 'context',
        ]);
        $this->lumber->flush();

        $payload = $this->payload($this->transport->sent()[0]);
        self::assertSame('Payment failed', $payload->message);
        self::assertSame('req-123', $payload->requestId);
        ContractFiles::assertSameJson((object) ['channel' => 'billing', 'region' => 'us-east'], $payload->context);
        self::assertSame('/checkout', $payload->metadata->url);
        self::assertSame('context', $payload->metadata->host, 'the context wins over extra');
        self::assertSame('RuntimeException: Card declined', $payload->metadata->exception);
        self::assertStringContainsString('Stack trace:', $payload->metadata->trace);
    }

    public function testSendsOnlyFromItsLevelAndBubbles(): void
    {
        $after = new TestHandler();
        $logger = new Logger('app', [new LumberHandler($this->lumber, MonologLevel::Warning), $after]);
        $logger->info('Below');
        $logger->warning('At');
        $this->lumber->flush();

        self::assertCount(1, $this->transport->bodies);
        self::assertCount(2, $after->getRecords(), 'records go on to the next handler');
    }

    public function testRunsItsOwnProcessors(): void
    {
        $handler = new LumberHandler($this->lumber);
        $handler->pushProcessor(new PsrLogMessageProcessor());
        (new Logger('app', [$handler]))->info('Hello {name}', ['name' => 'Zoë']);
        $this->lumber->flush();

        self::assertSame('Hello Zoë', $this->payload($this->transport->sent()[0])->message);
    }

    public function testResetAndCloseSendWhatsHeld(): void
    {
        $logger = new Logger('app', [new LumberHandler($this->lumber)]);
        $logger->info('Before a reset');
        $logger->reset();
        self::assertCount(1, $this->transport->bodies);

        $logger->info('Before closing');
        $logger->close();
        self::assertCount(2, $this->transport->bodies);
    }

    public function testMakesItsOwnClientFromAnApiKey(): void
    {
        $handler = new LumberHandler('token.' . base64_encode($this->key));

        self::assertInstanceOf(Lumber::class, $handler->getLumber());
    }

    private function payload(\stdClass $body): \stdClass
    {
        $payload = ContractFiles::decrypt($body->encrypted, $this->key);
        self::assertInstanceOf(\stdClass::class, $payload);
        ContractFiles::assertConforms('LogPayload', $payload);

        return $payload;
    }
}
