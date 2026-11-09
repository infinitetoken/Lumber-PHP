<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests;

use InfiniteToken\Lumber\Internal\Answer;
use InfiniteToken\Lumber\Internal\Sender;
use InfiniteToken\Lumber\Internal\SharedState;
use InfiniteToken\Lumber\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * The delivery rules (Lumber-Kit's logDelivery) on a clock the tests move by hand.
 */
final class SenderTest extends TestCase
{
    private float $now = 1_900_000_000.0;

    /** @var list<float> */
    private array $slept = [];

    /** @var list<string> */
    private array $warnings = [];

    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/lumber-test-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    public function testSendsInOrderOneLogAtATime(): void
    {
        $transport = new FakeTransport();
        $sender = $this->sender($transport);
        foreach (['a', 'b', 'c'] as $body) {
            $sender->add($body, 5.0);
        }

        self::assertSame(3, $sender->pending());
        self::assertSame([], $transport->bodies, 'nothing is sent before a flush');
        self::assertTrue($sender->flush(30.0));
        self::assertSame(['a', 'b', 'c'], $transport->bodies);
        self::assertSame(0, $sender->pending());
        self::assertSame([10.0, 10.0, 10.0], $transport->timeouts, "each waits Lumber's 10 seconds, within the flush's time");
        self::assertSame([], $this->warnings);
    }

    public function testRetriesAFailureWaitingOneTwoFourThenEightSecondsThenDropsIt(): void
    {
        $transport = new FakeTransport(
            new Answer(503),
            new Answer(null, error: 'Connection refused'),
            new Answer(500),
            new Answer(502),
            new Answer(503),
        );
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);
        $sender->add('b', 5.0);

        self::assertTrue($sender->flush(60.0));
        self::assertSame(['a', 'a', 'a', 'a', 'a', 'b'], $transport->bodies, 'five attempts, then the next log');
        self::assertSame([1.0, 2.0, 4.0, 8.0], $this->slept);
        self::assertSame([
            'send failed: HTTP 503',
            'send error: Connection refused',
            'send failed: HTTP 500',
            'send failed: HTTP 502',
            'send failed: HTTP 503',
            'dropping log after 5 failed attempts',
        ], $this->warnings);
    }

    public function testARefusedLogIsDroppedAtOnce(): void
    {
        $transport = new FakeTransport(new Answer(401, body: '{"message":"Invalid API key"}'), new Answer(400));
        $sender = $this->sender($transport);
        foreach (['a', 'b', 'c'] as $body) {
            $sender->add($body, 5.0);
        }

        self::assertTrue($sender->flush(5.0));
        self::assertSame(['a', 'b', 'c'], $transport->bodies);
        self::assertSame([], $this->slept);
        self::assertSame(['log refused: HTTP 401 (Invalid API key)', 'log refused: HTTP 400'], $this->warnings);
    }

    public function testA429PausesUntilRetryAfterDroppingWhatsHeld(): void
    {
        $transport = new FakeTransport(new Answer(429, '3600', '{"message":"This team has sent its 10,000 logs for the month."}'));
        $sender = $this->sender($transport);
        foreach (['a', 'b', 'c'] as $body) {
            $sender->add($body, 5.0);
        }

        self::assertTrue($sender->flush(5.0));
        self::assertSame(['a'], $transport->bodies);
        self::assertSame(0, $sender->pending(), 'the rest are dropped');
        self::assertSame(['This team has sent its 10,000 logs for the month. Dropping logs until 2030-03-17T18:46:40Z.'], $this->warnings);

        $sender->add('d', 5.0);
        self::assertSame(0, $sender->pending(), 'new logs are dropped during the pause');
        $this->now += 3600;
        $sender->add('e', 5.0);
        self::assertTrue($sender->flush(5.0));
        self::assertSame(['a', 'e'], $transport->bodies, 'and sent again once it ends');
        self::assertCount(1, $this->warnings, 'one warning for the whole pause');
    }

    public function testA429WithoutRetryAfterPausesForAnHour(): void
    {
        $transport = new FakeTransport(new Answer(429, 'Wed, 21 Oct 2015 07:28:00 GMT'));
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);
        $sender->flush(5.0);

        $this->now += 3599;
        $sender->add('b', 5.0);
        self::assertSame(0, $sender->pending());
        $this->now += 1;
        $sender->add('c', 5.0);
        self::assertSame(1, $sender->pending());
        self::assertStringStartsWith('Lumber is refusing logs for now (HTTP 429).', $this->warnings[0]);
    }

    public function testAFlushStopsWhenItsTimeRunsOutAndTheNextCarriesOn(): void
    {
        $transport = new FakeTransport(new Answer(503), new Answer(503), new Answer(503));
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);
        $sender->add('b', 5.0);

        // Tries at 0s, 1s and 3s; the next try, at 7s, is past the flush's 5 seconds
        self::assertFalse($sender->flush(5.0));
        self::assertSame(['a', 'a', 'a'], $transport->bodies);
        self::assertSame(2, $sender->pending(), 'what is left waits for the next flush');

        $this->now += 10;
        self::assertTrue($sender->flush(5.0));
        self::assertSame(['a', 'a', 'a', 'a', 'b'], $transport->bodies);
    }

    public function testNoAnswerInTimeIsAFailedAttempt(): void
    {
        $transport = new FakeTransport(function (): Answer {
            $this->now += 10;

            return new Answer(null, error: 'Operation timed out after 10000 milliseconds');
        });
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);

        self::assertTrue($sender->flush(30.0));
        self::assertSame(['a', 'a'], $transport->bodies);
        self::assertSame([1.0], $this->slept);
        self::assertSame(['send error: Operation timed out after 10000 milliseconds'], $this->warnings);
    }

    public function testAnAttemptWaitsNoLongerThanTheFlushHasLeft(): void
    {
        $transport = new FakeTransport();
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);

        self::assertTrue($sender->flush(2.5));
        self::assertSame([2.5], $transport->timeouts);
    }

    public function testAFlushWithUnderASecondLeftLeavesTheLogsForTheNext(): void
    {
        $transport = new FakeTransport();
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);

        self::assertFalse($sender->flush(0.5));
        self::assertSame([], $transport->bodies);
        self::assertTrue($sender->flush(1.0));
        self::assertSame(['a'], $transport->bodies);
    }

    public function testDiscardDropsWhatsLeftWithAWarningSayingWhy(): void
    {
        $transport = new FakeTransport(new Answer(503));
        $sender = $this->sender($transport);
        $sender->add('a', 5.0);
        $sender->add('b', 5.0);
        $sender->flush(1.5);
        $sender->discard();

        self::assertSame(0, $sender->pending());
        self::assertSame(['send failed: HTTP 503', "dropping 2 logs: Lumber couldn't be reached"], $this->warnings);

        $sender->add('c', 5.0);
        $sender->flush(0.5);
        $sender->discard();
        self::assertSame('dropping 1 log: sending them took too long', end($this->warnings));
    }

    public function testAFullBufferIsSentBeforeANewLog(): void
    {
        $transport = new FakeTransport();
        $sender = $this->sender($transport, maxBuffer: 2);
        foreach (['a', 'b', 'c'] as $body) {
            $sender->add($body, 5.0);
        }

        self::assertSame(['a', 'b'], $transport->bodies);
        self::assertSame(1, $sender->pending());
    }

    public function testAFullBufferLumberCantTakeDropsNewLogs(): void
    {
        $transport = new FakeTransport(new Answer(503), new Answer(503));
        $sender = $this->sender($transport, maxBuffer: 2);
        foreach (['a', 'b', 'c', 'd'] as $body) {
            $sender->add($body, 1.5);
        }

        self::assertSame(2, $sender->pending());
        self::assertSame(['send failed: HTTP 503', 'dropping logs: 2 are already waiting to send'], $this->warnings, 'one warning, not one a log');
        self::assertSame([], $this->slept, 'logging never waits out a retry');
        self::assertSame(['a'], $transport->bodies, 'nor tries again before the retry is due');
    }

    public function testAPauseIsSharedWithTheProcessesAfter(): void
    {
        $first = $this->sender(new FakeTransport(new Answer(429, '60')), shared: true);
        $first->add('a', 5.0);
        $first->flush(5.0);

        $transport = new FakeTransport();
        $next = $this->sender($transport, shared: true);
        $next->add('b', 5.0);
        self::assertTrue($next->flush(5.0));
        self::assertSame([], $transport->bodies, 'the next request sends nothing during the pause');
        self::assertCount(1, $this->warnings, 'and says nothing');

        $this->now += 60;
        $next->add('c', 5.0);
        $next->flush(5.0);
        self::assertSame(['c'], $transport->bodies);
    }

    public function testAnOutageIsSharedWithTheProcessesAfterLongerEachTime(): void
    {
        $first = $this->sender(new FakeTransport(new Answer(503), new Answer(503), new Answer(503)), shared: true);
        $first->add('a', 5.0);
        self::assertFalse($first->flush(5.0));
        $first->discard();
        // It gave up at 3s, when its next try was at 7s: the next requests wait for that rather than trying themselves
        self::assertSame(['until' => $this->now + 4, 'reason' => 'down', 'failures' => 1], $this->sharedState());

        $transport = new FakeTransport();
        $next = $this->sender($transport, shared: true);
        $next->add('b', 5.0);
        self::assertFalse($next->flush(1.0));
        self::assertSame([], $transport->bodies);
        $next->discard();
        self::assertSame("dropping 1 log: Lumber couldn't be reached", end($this->warnings));

        // The next to give up holds the others off twice as long
        $this->now += 4;
        $third = $this->sender(new FakeTransport(new Answer(503)), shared: true);
        $third->add('c', 5.0);
        self::assertFalse($third->flush(1.0));
        self::assertSame(['until' => $this->now + 2, 'reason' => 'down', 'failures' => 2], $this->sharedState());

        // Until a log gets through
        $this->now += 2;
        $next->add('d', 5.0);
        self::assertTrue($next->flush(1.0));
        self::assertSame(['d'], $transport->bodies);
        self::assertFileDoesNotExist($this->stateFile);
    }

    public function testAForkedChildStartsOverWithItsOwnBufferAndConnection(): void
    {
        $transport = new FakeTransport();
        $sender = $this->sender($transport);
        $sender->add('from the parent', 5.0);
        (new \ReflectionProperty(Sender::class, 'pid'))->setValue($sender, -1);

        self::assertSame(0, $sender->pending(), "the parent's logs are the parent's to send");
        self::assertSame(1, $transport->resets);
        $sender->add('from the child', 5.0);
        $sender->flush(5.0);
        self::assertSame(['from the child'], $transport->bodies);
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedState(): array
    {
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertIsArray($state);
        self::assertIsNumeric($state['until']);

        return ['until' => (float) $state['until']] + $state;
    }

    private function sender(FakeTransport $transport, int $maxBuffer = 100, bool $shared = false): Sender
    {
        return new Sender(
            $transport,
            10.0,
            $maxBuffer,
            function (string $message): void {
                $this->warnings[] = $message;
            },
            $shared ? new SharedState($this->stateFile) : null,
            fn(): float => $this->now,
            function (float $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
        );
    }
}
