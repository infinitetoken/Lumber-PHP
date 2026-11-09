<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * A pause (Lumber's 429 at the monthly cap) or an outage that one process ran into, kept in a small file in the temp
 * directory so the processes after it skip sending until it's over. PHP serving a web request forgets everything when
 * the request ends, so without it every request would be refused the same 429, or wait out the same outage holding its
 * PHP-FPM worker. Best effort: where the file can't be written or read, each process finds out for itself.
 *
 * @internal
 */
final class SharedState
{
    public const PAUSE = 'pause';
    public const DOWN = 'down';

    public function __construct(private readonly string $path) {}

    /**
     * The state for one API key. The file is named by a hash of its token, never the token itself.
     */
    public static function forToken(#[\SensitiveParameter] string $token): self
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\');

        return new self($directory . \DIRECTORY_SEPARATOR . 'lumber-' . substr(hash('sha256', $token), 0, 16) . '.json');
    }

    /**
     * What's recorded, over or not: until when (seconds since the epoch), why, and for an outage, how many requests in a
     * row have given up on Lumber.
     *
     * @return array{until: float, reason: string, failures: int}|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $json = @file_get_contents($this->path);
        $state = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($state) || !is_numeric($state['until'] ?? null) || !\in_array($state['reason'] ?? null, [self::PAUSE, self::DOWN], true)) {
            return null;
        }

        return [
            'until' => (float) $state['until'],
            'reason' => $state['reason'],
            'failures' => \is_int($state['failures'] ?? null) ? $state['failures'] : 0,
        ];
    }

    /**
     * Records a pause or outage until `$until` (seconds since the epoch), replacing the file in one step so a process
     * reading it never sees half of it.
     */
    public function write(float $until, string $reason, int $failures = 0): void
    {
        $temporary = $this->path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, json_encode(['until' => $until, 'reason' => $reason, 'failures' => $failures])) === false) {
            return;
        }
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }

    /**
     * Forgets an outage once a log gets through.
     */
    public function clear(): void
    {
        @unlink($this->path);
    }
}
