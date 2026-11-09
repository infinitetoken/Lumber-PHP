<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber;

use InfiniteToken\Lumber\Internal\Contract;
use InfiniteToken\Lumber\Internal\Crypto;
use InfiniteToken\Lumber\Internal\CurlTransport;
use InfiniteToken\Lumber\Internal\Json;
use InfiniteToken\Lumber\Internal\Sender;
use InfiniteToken\Lumber\Internal\SharedState;
use InfiniteToken\Lumber\Internal\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Sends logs to Lumber, cloud logging with end-to-end encryption. Every log is encrypted with AES-256-GCM before it
 * leaves the process, so Lumber's servers store only ciphertext plus each log's level.
 *
 *     $lumber = new Lumber(getenv('LUMBER_API_KEY'));
 *     $lumber->info('Order placed', ['order_id' => 789, 'request_id' => 'req-123']);
 *
 * It's a PSR-3 logger. Logging never waits on the network: logs are held and sent when the request ends, after the
 * response has gone to the visitor where PHP-FPM allows it, or when a long-running worker calls flush().
 */
final class Lumber implements LoggerInterface
{
    use LoggerTrait;

    /** This package's version, sent in the User-Agent. */
    public const VERSION = '1.0.0';

    /** The Lumber API logs go to unless the constructor is given an endpoint. */
    public const DEFAULT_ENDPOINT = Contract::DEFAULT_ENDPOINT;

    private readonly string $key;
    private readonly Sender $sender;
    private readonly ?string $hostname;
    private bool $closed = false;
    private bool $shutdownRegistered = false;

    /** @var \Closure(string): void */
    private readonly \Closure $warn;

    /**
     * @param string $apiKey the API key from your app's Secrets screen in Lumber, `<token>.<encryptionKey>`. Only the
     *                       token is sent to Lumber; the encryption key encrypts every log and never leaves this process.
     * @param string|null $environment sent with every log as `env` in its context ("production", "staging")
     * @param bool $autoContext send the process id and hostname (and the environment) with every log, in its context
     * @param bool $caller also send the file, line and function that logged, in its context
     * @param int $callerDepth stack frames to skip for `caller`, one for each function of yours wrapped around this client
     * @param bool $sync send each log as it's logged, waiting for Lumber to answer, rather than when the request ends
     * @param bool $afterResponse finish the response (PHP-FPM, LiteSpeed, FrankenPHP) before sending, so visitors never
     *                            wait on logging
     * @param float $timeout seconds to wait for Lumber to answer one log
     * @param float $flushTimeout seconds a flush (the one as the request ends included) may take before the logs left
     *                            wait for the next one, or are dropped at the end
     * @param int $maxBuffer logs held before they're sent; when it's full, a new log sends them there and then
     * @param string $endpoint the Lumber API's URL, for testing against another server
     * @param (\Closure(string): void)|null $warnings receives the client's warnings (a log refused, Lumber unreachable)
     *                                                in place of PHP's error log
     * @param Transport|null $transport for tests only
     *
     * @throws \InvalidArgumentException when the API key or another option is invalid
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        private readonly ?string $environment = null,
        private readonly bool $autoContext = true,
        private readonly bool $caller = false,
        private readonly int $callerDepth = 0,
        private readonly bool $sync = false,
        private readonly bool $afterResponse = true,
        float $timeout = 10.0,
        private readonly float $flushTimeout = 5.0,
        int $maxBuffer = 1_000,
        string $endpoint = self::DEFAULT_ENDPOINT,
        ?\Closure $warnings = null,
        ?Transport $transport = null,
    ) {
        [$token, $encryptionKey] = Contract::splitApiKey($apiKey);
        if ($token === '') {
            throw new \InvalidArgumentException("[lumber] Missing API key. Create one on your app's Secrets screen in Lumber.");
        }
        if ($encryptionKey === null || $encryptionKey === '') {
            throw new \InvalidArgumentException("[lumber] The API key has no encryption key. Copy a new API key from your app's Secrets screen in Lumber, which includes it.");
        }
        $key = base64_decode($encryptionKey, true);
        if ($key === false || \strlen($key) !== Contract::KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                "[lumber] The API key's encryption key should be %d bytes of base64, got %d. Copy the API key again from your app's Secrets screen in Lumber.",
                Contract::KEY_BYTES,
                $key === false ? 0 : \strlen($key),
            ));
        }
        $endpoint = rtrim($endpoint, '/');
        if (preg_match('~^https?://[^/]~i', $endpoint) !== 1) {
            throw new \InvalidArgumentException(\sprintf('[lumber] The endpoint must be an http or https URL, got "%s".', $endpoint));
        }
        if (!($timeout > 0) || !($flushTimeout > 0) || $maxBuffer < 1 || $callerDepth < 0) {
            throw new \InvalidArgumentException('[lumber] timeout and flushTimeout must be above 0, maxBuffer 1 or more and callerDepth 0 or more.');
        }

        $this->key = $key;
        $this->warn = static function (string $message) use ($warnings): void {
            try {
                if ($warnings !== null) {
                    $warnings($message);
                } else {
                    // Straight to PHP's error log, never through a logger, where a LumberHandler would send Lumber's own
                    // warnings back to Lumber
                    error_log('[lumber] ' . $message);
                }
            } catch (\Throwable) {
                // A warning must never break the app
            }
        };
        $this->hostname = $autoContext ? (gethostname() ?: null) : null;
        $userAgent = \sprintf('lumber-php/%s php/%s', self::VERSION, \PHP_VERSION);
        $this->sender = new Sender(
            $transport ?? new CurlTransport($endpoint . Contract::INGEST_PATH, $token, $userAgent),
            $timeout,
            $maxBuffer,
            $this->warn,
            SharedState::forToken($token),
        );
    }

    /**
     * Logs at a PSR-3 level (Psr\Log\LogLevel's names, a Level, or 'warn' or 'fatal'). `$context` is the log's data:
     *
     * - `request_id` and `trace_id` (or `requestId` and `traceId`) are the log's own fields
     * - `context` and `metadata` arrays merge into its context and metadata
     * - an `exception` holding a Throwable goes into metadata as `exception` (its class and message) and `trace`
     * - every other key goes into metadata
     *
     * `{placeholders}` in the message are filled from `$context`, as PSR-3 describes. Logging never throws, except for
     * an unknown level, as PSR-3 asks.
     *
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = Level::of($level);
        try {
            $this->record($level, self::interpolate((string) $message, $context), $context, $this->caller ? $this->callerContext() : []);
        } catch (\Throwable $error) {
            ($this->warn)(\sprintf("couldn't log at %s: %s", strtoupper($level->name), $error->getMessage()));
        }
    }

    /**
     * Logs at WARN: PSR-3's warning() by Lumber's name. See log() for the context.
     *
     * @param array<array-key, mixed> $context
     */
    public function warn(string|\Stringable $message, array $context = []): void
    {
        $this->log(Level::Warn, $message, $context);
    }

    /**
     * Logs at FATAL, as critical(), alert() and emergency() do. It doesn't exit. See log() for the context.
     *
     * @param array<array-key, mixed> $context
     */
    public function fatal(string|\Stringable $message, array $context = []): void
    {
        $this->log(Level::Fatal, $message, $context);
    }

    /**
     * Sends the logs held so far, waiting for Lumber up to `$timeout` seconds (flushTimeout unless given). A request's
     * logs are sent when it ends without it; call it in a long-running worker after each job or request. True when every
     * log was sent (or dropped); false when time ran out first, and the rest wait for the next flush.
     */
    public function flush(?float $timeout = null): bool
    {
        try {
            return $this->sender->flush($timeout ?? $this->flushTimeout);
        } catch (\Throwable $error) {
            ($this->warn)('send error: ' . $error->getMessage());

            return false;
        }
    }

    /**
     * Sends what's held, waiting up to `$timeout` seconds (flushTimeout unless given), drops what's left with a warning,
     * and stops: logs after this are dropped. True when every log was sent.
     */
    public function close(?float $timeout = null): bool
    {
        if ($this->closed) {
            return true;
        }
        $sent = $this->flush($timeout);
        $this->sender->discard();
        $this->closed = true;

        return $sent;
    }

    /**
     * Logs waiting to send.
     */
    public function pending(): int
    {
        return $this->sender->pending();
    }

    /**
     * Builds, encrypts and holds one log; LumberHandler sends Monolog's records through it. `$data` takes PHP's names
     * (request_id, trace_id) or the API's; `$context` goes over the automatic context, under the log's own.
     *
     * @internal
     *
     * @param array<array-key, mixed> $data
     * @param array<string, mixed> $context
     */
    public function record(Level $level, string $message, array $data, array $context = []): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $auto = [];
            if ($this->autoContext) {
                $auto['pid'] = getmypid();
                if ($this->hostname !== null) {
                    $auto['hostname'] = $this->hostname;
                }
                if ($this->environment !== null && $this->environment !== '') {
                    $auto['env'] = $this->environment;
                }
            }
            /** @var array<array-key, mixed> $fields */
            $fields = Json::normalize(self::fields($data));
            /** @var array<array-key, mixed> $auto */
            $auto = Json::normalize(array_replace($auto, $context));

            $payload = Contract::buildPayload($level->value, $message, $fields, $auto);
            $body = Json::encode(['level' => $level->value, 'encrypted' => Crypto::encrypt($this->key, $payload)]);
            $this->sender->add($body, $this->flushTimeout);
            $this->registerShutdown();
            if ($this->sync) {
                $this->sender->flush($this->flushTimeout);
            }
        } catch (\Throwable $error) {
            ($this->warn)(\sprintf("couldn't log at %s: %s", strtoupper($level->name), $error->getMessage()));
        }
    }

    public function __destruct()
    {
        // A client let go of before the process ends sends what it holds now
        if (!$this->closed && $this->sender->pending() > 0) {
            $this->flush();
            $this->sender->discard();
        }
    }

    /**
     * A log's data with PHP's names for requestId and traceId taken as the API's, and an exception as text.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function fields(array $data): array
    {
        foreach (['request_id' => 'requestId', 'trace_id' => 'traceId'] as $name => $wire) {
            if (\array_key_exists($name, $data)) {
                $value = $data[$name];
                unset($data[$name]);
                if ($value !== null && ($data[$wire] ?? null) === null) {
                    $data[$wire] = $value;
                }
            }
        }
        if (($data['exception'] ?? null) instanceof \Throwable) {
            $exception = $data['exception'];
            $data['exception'] = $exception::class . ': ' . $exception->getMessage();
            if (!\array_key_exists('trace', $data)) {
                // PHP's own account of it: where it was thrown, its stack trace and the exceptions that led to it
                $data['trace'] = (string) $exception;
            }
        }

        return $data;
    }

    /**
     * PSR-3's placeholders: `{key}` in the message is the context's value at `key`.
     *
     * @param array<array-key, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{') || $context === []) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            $placeholder = '{' . $key . '}';
            if (!str_contains($message, $placeholder)) {
                continue;
            }
            $replacements[$placeholder] = match (true) {
                $value === null => '',
                \is_bool($value) => $value ? 'true' : 'false',
                \is_scalar($value), $value instanceof \Stringable => (string) $value,
                $value instanceof \DateTimeInterface => $value->format(\DATE_RFC3339),
                \is_object($value) => '[object ' . $value::class . ']',
                default => Json::encode(Json::normalize($value)),
            };
        }

        return strtr($message, $replacements);
    }

    /**
     * The file, line and function that called this client's log method, skipping `callerDepth` more frames.
     *
     * @return array<string, mixed>
     */
    private function callerContext(): array
    {
        $frames = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 16 + $this->callerDepth);
        // Frames in this class (its log methods and PSR-3's LoggerTrait) lead to the call: the last of them is where it
        // was called from, and the frame after it the function that called
        $index = 0;
        while (isset($frames[$index + 1]) && ($frames[$index + 1]['class'] ?? null) === self::class) {
            ++$index;
        }
        $index += $this->callerDepth;
        $call = $frames[$index] ?? null;
        if (!isset($call['file'])) {
            return [];
        }
        $context = ['code' => $call['file']];
        if (isset($call['line'])) {
            $context['line'] = $call['line'];
        }
        $outer = $frames[$index + 1] ?? null;
        if ($outer !== null) {
            $context['method'] = isset($outer['class']) ? $outer['class'] . '::' . $outer['function'] : $outer['function'];
        }

        return $context;
    }

    /**
     * Sends what's held as the request or process ends. It's registered from inside a shutdown function, so it runs
     * after every shutdown function registered before the request ended (a framework's error reporting among them) and
     * sends the logs they write too. It holds the client weakly, so a client let go of early isn't kept alive by it.
     */
    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        $client = \WeakReference::create($this);
        register_shutdown_function(static function () use ($client): void {
            register_shutdown_function(static function () use ($client): void {
                $client->get()?->shutdown();
            });
        });
    }

    private function shutdown(): void
    {
        if ($this->closed || $this->sender->pending() === 0) {
            return;
        }
        if ($this->afterResponse && !$this->sync) {
            self::finishResponse();
        }
        $this->flush();
        $this->sender->discard();
    }

    /**
     * Sends the response to the visitor and closes it, so the logs go while nobody waits: PHP-FPM, LiteSpeed and
     * FrankenPHP can. Elsewhere (Apache's mod_php, the CLI) it does nothing.
     */
    private static function finishResponse(): void
    {
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            return;
        }
        // A native session stays locked until it's written: write it now, or the visitor's next request waits on the logs
        if (\function_exists('session_status') && session_status() === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (\function_exists('frankenphp_finish_request')) {
            frankenphp_finish_request();
        }
    }
}
