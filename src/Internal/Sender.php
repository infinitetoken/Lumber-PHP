<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * Holds encrypted logs and sends them to POST /logs in the order they were logged, one at a time over one kept-alive
 * connection (Lumber stores each log's time as it arrives, so sending them side by side would shuffle them). PHP has no
 * background thread to send from, so logs go at a flush: the end of the request or process (see Lumber), a call to
 * flush() in a long-running worker, or a full buffer. What each answer means and how long to wait are Lumber-Kit's
 * (see Contract).
 *
 * @internal
 */
final class Sender
{
    /** Seconds after an outage ends that its count of failed requests is remembered */
    private const OUTAGE_MEMORY = 60.0;

    /** @var list<string> */
    private array $buffer = [];

    /** Failed attempts of the oldest log, the next to send */
    private int $failed = 0;

    /** When the oldest log may be tried again, in seconds since the epoch */
    private float $retryAt = 0.0;

    /** After a 429, nothing is sent or held until then */
    private float $pausedUntil = 0.0;

    /** Requests in a row that gave up on Lumber, from the shared state */
    private int $sharedFailures = 0;

    private bool $fullWarned = false;
    private bool $flushing = false;
    private int $pid;

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param float $timeout seconds to wait for Lumber to answer one log
     * @param int $maxBuffer logs held before a new one sends them
     * @param \Closure(string): void $warn
     * @param (\Closure(): float)|null $now seconds since the epoch
     * @param (\Closure(float): void)|null $sleep
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly float $timeout,
        private readonly int $maxBuffer,
        private readonly \Closure $warn,
        private readonly ?SharedState $shared = null,
        ?\Closure $now = null,
        ?\Closure $sleep = null,
    ) {
        $this->now = $now ?? static fn(): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
        $this->pid = (int) getmypid();
    }

    /**
     * Logs held, waiting to send.
     */
    public function pending(): int
    {
        $this->forked();

        return \count($this->buffer);
    }

    /**
     * Holds one log to send. When the buffer is full, what it holds is sent there and then (for `$flushTimeout` seconds
     * at most, and never waiting out a retry), and a log that still doesn't fit, since Lumber can't be reached, is
     * dropped.
     */
    public function add(string $body, float $flushTimeout): void
    {
        $this->forked();
        if (($this->now)() < $this->pausedUntil) {
            return;
        }
        if (\count($this->buffer) >= $this->maxBuffer) {
            $this->flush($flushTimeout, wait: false);
        }
        if (\count($this->buffer) >= $this->maxBuffer) {
            if (!$this->fullWarned) {
                $this->fullWarned = true;
                ($this->warn)(\sprintf('dropping logs: %d are already waiting to send', $this->maxBuffer));
            }

            return;
        }
        $this->fullWarned = false;
        $this->buffer[] = $body;
    }

    /**
     * Sends what's held, in order, waiting between attempts as the contract says (unless `$wait` is false), for
     * `$timeout` seconds at most. True when nothing is left; false when time ran out first, and the rest stays for the
     * next flush.
     */
    public function flush(float $timeout, bool $wait = true): bool
    {
        $this->forked();
        // A log written while sending (by a warnings callback that logs) waits for the flush already running
        if ($this->buffer === [] || $this->flushing) {
            return $this->buffer === [];
        }
        $this->flushing = true;
        try {
            return $this->send(($this->now)() + $timeout, $wait);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Drops what's still held, as a request or process ends, with a warning saying why.
     */
    public function discard(): void
    {
        $this->forked();
        $count = \count($this->buffer);
        if ($count === 0) {
            return;
        }
        $why = $this->failed > 0 || $this->retryAt > ($this->now)() ? "Lumber couldn't be reached" : 'sending them took too long';
        $this->buffer = [];
        $this->failed = 0;
        $this->retryAt = 0.0;
        ($this->warn)(\sprintf('dropping %d %s: %s', $count, $count === 1 ? 'log' : 'logs', $why));
    }

    private function send(float $deadline, bool $wait): bool
    {
        $this->readShared();
        while ($this->buffer !== []) {
            $now = ($this->now)();
            if ($now < $this->pausedUntil) {
                $this->buffer = [];

                return true;
            }
            if ($this->retryAt > $now) {
                if (!$wait || $this->retryAt >= $deadline) {
                    return $this->giveUp($now);
                }
                ($this->sleep)($this->retryAt - $now);
                $now = ($this->now)();
            }
            // An attempt gets a second at least (or Lumber's whole timeout, if that's shorter): one cut shorter might fail
            // for want of time rather than Lumber's fault
            if ($deadline - $now < min(1.0, $this->timeout)) {
                return $this->giveUp($now);
            }

            $answer = $this->transport->post($this->buffer[0], min($this->timeout, $deadline - $now));
            switch (Contract::deliveryOutcome($answer->status)) {
                case Outcome::Sent:
                    $this->next();
                    if ($this->sharedFailures > 0) {
                        $this->sharedFailures = 0;
                        $this->shared?->clear();
                    }
                    break;
                case Outcome::Pause:
                    $this->pause($answer);

                    return true;
                case Outcome::Drop:
                    // A bad key or a malformed log won't go through on a later try
                    $message = $answer->message();
                    ($this->warn)(\sprintf('log refused: HTTP %d', $answer->status) . ($message !== null ? " ({$message})" : ''));
                    $this->next();
                    break;
                case Outcome::Retry:
                    // No answer in time counts too: a Lumber that hangs mustn't hold every request for its whole flush
                    $this->failedAttempt($answer);
                    break;
            }
        }

        return true;
    }

    /**
     * Leaves what's held for the next flush. When Lumber failed this flush, the next requests hold off too, longer each
     * time one gives up (1, 2, 4 seconds and so on, up to 30) until a log gets through.
     */
    private function giveUp(float $now): bool
    {
        if ($this->failed > 0 && $this->shared !== null) {
            $failures = $this->sharedFailures + 1;
            $until = max($this->retryAt, $now + Contract::retryDelayMs($failures) / 1000);
            $this->shared->write($until, SharedState::DOWN, $failures);
            $this->sharedFailures = $failures;
        }

        return false;
    }

    private function failedAttempt(Answer $answer): void
    {
        ($this->warn)($answer->status === null ? "send error: {$answer->error}" : "send failed: HTTP {$answer->status}");
        ++$this->failed;
        if ($this->failed >= Contract::MAX_ATTEMPTS) {
            ($this->warn)(\sprintf('dropping log after %d failed attempts', Contract::MAX_ATTEMPTS));
            $this->next();

            return;
        }
        $this->retryAt = ($this->now)() + Contract::retryDelayMs($this->failed) / 1000;
    }

    private function next(): void
    {
        array_shift($this->buffer);
        $this->failed = 0;
        $this->retryAt = 0.0;
    }

    /**
     * Stops sending until the API's Retry-After (the start of the next month, for the monthly cap), dropping what's held,
     * with one warning for the whole pause.
     */
    private function pause(Answer $answer): void
    {
        $seconds = is_numeric($answer->retryAfter) ? (float) $answer->retryAfter : 0.0;
        if (!($seconds > 0)) {
            $seconds = Contract::PAUSE_MS / 1000;
        }
        $this->pausedUntil = ($this->now)() + $seconds;
        $this->buffer = [];
        $this->failed = 0;
        $this->retryAt = 0.0;
        $this->shared?->write($this->pausedUntil, SharedState::PAUSE);
        $message = $answer->message() ?? 'Lumber is refusing logs for now (HTTP 429).';
        ($this->warn)(\sprintf('%s Dropping logs until %s.', $message, gmdate('Y-m-d\TH:i:s\Z', (int) ceil($this->pausedUntil))));
    }

    /**
     * Takes up a pause or outage another process recorded: a pause drops what's held, as here; an outage holds off the
     * first try until it's over, and its count of requests that gave up carries on unless it ended a while ago.
     */
    private function readShared(): void
    {
        $this->sharedFailures = 0;
        $state = $this->shared?->read();
        if ($state === null) {
            return;
        }
        $now = ($this->now)();
        if ($state['reason'] === SharedState::PAUSE) {
            if ($state['until'] > $now) {
                $this->pausedUntil = max($this->pausedUntil, $state['until']);
            }

            return;
        }
        if ($state['until'] > $now - self::OUTAGE_MEMORY) {
            $this->sharedFailures = $state['failures'];
        }
        if ($state['until'] > $now && $this->failed === 0) {
            $this->retryAt = max($this->retryAt, $state['until']);
        }
    }

    /**
     * A forked child inherits the parent's buffer and connection: the parent sends those logs, and the child starts
     * over with its own.
     */
    private function forked(): void
    {
        $pid = (int) getmypid();
        if ($pid === $this->pid) {
            return;
        }
        $this->pid = $pid;
        $this->buffer = [];
        $this->failed = 0;
        $this->retryAt = 0.0;
        $this->transport->reset();
    }
}
