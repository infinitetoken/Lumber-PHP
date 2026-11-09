<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A stand-in for the Lumber API: PHP's built-in web server with tests/Support/router.php, on a free local port.
 */
final class Server
{
    /** @var resource|null */
    private static $process = null;

    private static string $url = '';
    private static string $requests = '';

    /**
     * The server's URL, starting it the first time.
     */
    public static function url(): string
    {
        if (self::$process !== null) {
            return self::$url;
        }

        $port = self::freePort();
        self::$requests = sys_get_temp_dir() . '/lumber-requests-' . bin2hex(random_bytes(6)) . '.jsonl';
        touch(self::$requests);
        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            null,
            ['LUMBER_TEST_REQUESTS' => self::$requests] + getenv(),
        );
        Assert::assertIsResource($process, "Couldn't start php -S");
        self::$process = $process;
        self::$url = "http://127.0.0.1:{$port}";
        register_shutdown_function(self::stop(...));

        // Waits for it to listen
        for ($tries = 0; $tries < 100; ++$tries) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return self::$url;
            }
            usleep(50_000);
        }
        Assert::fail("php -S didn't start listening on port {$port}");
    }

    /**
     * The requests the server got since the last clear(), oldest first.
     *
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public static function requests(): array
    {
        self::url();
        $lines = file(self::$requests, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

        /** @var list<array{method: string, path: string, headers: array<string, string>, body: string}> */
        return array_map(static fn(string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR), $lines);
    }

    public static function clear(): void
    {
        self::url();
        file_put_contents(self::$requests, '');
    }

    /**
     * A local port nothing listens on.
     */
    public static function closedPort(): int
    {
        return self::freePort();
    }

    private static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
            @unlink(self::$requests);
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        Assert::assertIsResource($socket, "Couldn't find a free port: {$error}");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
