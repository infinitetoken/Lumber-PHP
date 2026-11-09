# infinitetoken/lumber

PHP client for [Lumber](https://lumber.infinitetoken.com): cloud logging with end-to-end encryption. Every log is encrypted with AES-256-GCM before it leaves your server, so Lumber's servers store only ciphertext plus each log's level (kept readable for filtering and push alerts). You read your logs in the Lumber app, which decrypts them on your device.

It's a PSR-3 logger, with a Monolog handler and a Laravel log channel. Its only dependencies are PHP's curl and OpenSSL extensions and `psr/log`, and logging never makes a visitor wait on the network.

## Installation

```bash
composer require infinitetoken/lumber
```

PHP 8.2 or later.

## Quick start

```php
use InfiniteToken\Lumber\Lumber;

$lumber = new Lumber((string) getenv('LUMBER_API_KEY'));

$lumber->info('Server started', ['port' => 8080]);
$lumber->warning('Rate limit approaching', ['request_id' => 'req-123', 'user_id' => 456]);
$lumber->error('Payment failed', ['trace_id' => 'trace-abc', 'order_id' => 789, 'exception' => $e]);
```

That's all: the logs are sent when the request (or script) ends. In Laravel, skip this and set up the [log channel](#laravel).

## Getting your API key

Create an app in the Lumber app and it takes you straight to adding an API key. The key is shown only once, so copy it right away. Any device holding the app's key can add more from the app's **Secrets** screen, one per deployment if you like.

An API key is two values joined by a dot: a token, then the app's encryption key. The client sends only the token to Lumber and encrypts every log with the rest, so the encryption key never leaves your server. Keep the API key in an environment variable or your secrets manager, never in source control.

## How logs are sent

Most PHP runs one request at a time, with nothing left running in the background between requests, so the client can't send from a background thread the way Lumber's Node, Python and Go clients do. Instead it holds each request's logs, already encrypted, and sends them as the request ends:

- **PHP-FPM** (nginx, Caddy, Apache with `proxy_fcgi`, most hosting), **LiteSpeed** and **FrankenPHP**: the response goes to the visitor first, and then the logs are sent, so nobody waits on Lumber. The PHP worker stays busy while they send, about a tenth of a second a log.
- **Apache's mod_php** and **`php -S`** have no way to finish a response early, so the visitor's page finishes loading once the logs are sent.
- **A command-line script** sends what it holds as it exits.

Logs are sent one at a time in the order they were logged, over one connection kept open between them. Sending stops after 5 seconds (`flushTimeout`), and anything left is dropped with a warning; raise it if a request logs more than a few dozen lines. Up to 1,000 logs are held (`maxBuffer`); one more sends them there and then.

Long-running processes (queue workers, Octane, RoadRunner) serve many requests or jobs from one process and never reach the end of a request, so they send at the end of each job or request instead: the Laravel integration does it for queue workers and Octane, the Monolog handler for Symfony, and anywhere else you call `flush()`. See [Long-running workers](#long-running-workers).

## Laravel

Laravel finds the package by itself. Add your API key to `.env`, and `lumber` to the channels the `stack` channel writes to:

```bash
LUMBER_API_KEY=your-api-key
LOG_CHANNEL=stack
LOG_STACK=single,lumber
```

Everything your app logs now goes to Lumber as well as `storage/logs/laravel.log`:

```php
use Illuminate\Support\Facades\Log;

Log::info('Order placed', ['order_id' => 789, 'request_id' => $request->header('X-Request-Id')]);
Log::channel('lumber')->warning('Sent to Lumber only');
```

Records are sent at the matching level (NOTICE is INFO, and CRITICAL, ALERT and EMERGENCY are FATAL), `{placeholders}` in messages are filled from the context, and an exception Laravel reports arrives with its trace. What you add with Laravel's `Context` goes into each log's metadata, with `trace_id` and `request_id` as the log's own fields. Each log's context carries your app's environment (`APP_ENV`).

Logs are sent after the response has gone to the visitor, after each queued job, and after each of Octane's requests, tasks and ticks.

Settings, all optional but the key:

| `.env` | Default | |
|--------|---------|---|
| `LUMBER_API_KEY` | | Your app's API key |
| `LUMBER_LEVEL` | `debug` | The lowest level the channel sends |
| `LUMBER_ENVIRONMENT` | `APP_ENV` | Sent as `env` in every log's context; empty sends none |
| `LUMBER_SYNC` | `false` | Send each log as it's logged (see [Serverless](#serverless)) |
| `LUMBER_AUTO_CONTEXT` | `true` | Send the process id and hostname |
| `LUMBER_TIMEOUT` | `10` | Seconds to wait for Lumber to answer one log |
| `LUMBER_FLUSH_TIMEOUT` | `5` | Seconds sending may take as a request or job ends |
| `LUMBER_MAX_BUFFER` | `1000` | Logs held before they're sent there and then |

`php artisan vendor:publish --tag=lumber-config` copies them to `config/lumber.php`. To change the channel itself, define `lumber` in `config/logging.php` (`'driver' => 'custom', 'via' => InfiniteToken\Lumber\Laravel\LumberChannel::class`, with Laravel's usual `level`, `bubble`, `replace_placeholders` and `name`). The client is in the container as `InfiniteToken\Lumber\Lumber`.

Laravel 12 or later. In an app whose `config/logging.php` predates `LOG_STACK`, add `'lumber'` to the `stack` channel's `channels`.

## Monolog

`LumberHandler` sends Monolog's records (Monolog 3), so any code that logs through Monolog sends to Lumber:

```php
use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Monolog\LumberHandler;
use Monolog\Level;
use Monolog\Logger;

$lumber = new Lumber((string) getenv('LUMBER_API_KEY'));
$logger = new Logger('app');
$logger->pushHandler(new LumberHandler($lumber, Level::Info));

$logger->error('Payment failed', ['order_id' => 789, 'exception' => $e]);
```

A record's context and extra (what processors add) are the log's data, as below, with the context winning, and its channel goes into the log's context. Add Monolog's `PsrLogMessageProcessor` to fill `{placeholders}`. `new LumberHandler($apiKey)` makes its own client, if you'd rather not share one.

In Symfony, register the client and handler as services in `config/services.yaml`:

```yaml
services:
    # ...
    InfiniteToken\Lumber\Lumber:
        arguments:
            $apiKey: '%env(LUMBER_API_KEY)%'
            $environment: '%kernel.environment%'
    InfiniteToken\Lumber\Monolog\LumberHandler:
        arguments:
            $lumber: '@InfiniteToken\Lumber\Lumber'
            $level: info
```

and give the handler to MonologBundle under `monolog:` in `config/packages/monolog.yaml`:

```yaml
monolog:
    handlers:
        lumber:
            type: service
            id: InfiniteToken\Lumber\Monolog\LumberHandler
```

Symfony resets its loggers between Messenger's messages, and the handler sends what's held when it's reset.

## Log methods

| Method | Level |
|--------|-------|
| `debug($message, $context)` | DEBUG |
| `info(...)`, `notice(...)` | INFO |
| `warning(...)`, `warn(...)` | WARN |
| `error(...)` | ERROR |
| `critical(...)`, `alert(...)`, `emergency(...)`, `fatal(...)` | FATAL. None of them exit |
| `log($level, $message, $context)` | A PSR-3 level (`Psr\Log\LogLevel`), a `Level`, or `'warn'` or `'fatal'` |
| `flush(?float $timeout)` | Sends what's held now, waiting `flushTimeout` seconds at most; `false` if time ran out first |
| `close(?float $timeout)` | Sends what's held and stops; later logs are dropped |

Logging never throws, but for an unknown level, which PSR-3 asks a logger to refuse.

## Data

The context array is the log's data:

```php
$lumber->info('User signed in', [
    'request_id' => 'req-abc',             // the log's request ID
    'trace_id' => 'trace-xyz',             // the log's trace ID
    'context' => ['region' => 'us-east'],  // merged into its context
    'metadata' => ['plan' => 'pro'],       // merged into its metadata
    'user_id' => 123,                      // any other key goes into metadata
    'exception' => $e,                     // a Throwable: its class and message, and its trace
]);
```

`requestId` and `traceId`, the API's own names, work too. An explicit `metadata` key wins over a colliding key. A `Throwable` under `exception` goes into metadata as `exception` (`RuntimeException: Card declined`) and `trace` (PHP's own account of it, with the exceptions that led to it). `{placeholders}` in the message are filled from the context, as PSR-3 describes, and the values are still sent. Values JSON has no form for are sent as text: a date as ISO 8601, an enum as its value, a `JsonSerializable` as what it serializes to, another object as its public properties, and a NaN as null. Everything, message included, is encrypted.

## Options

```php
$lumber = new Lumber(
    (string) getenv('LUMBER_API_KEY'),
    environment: 'production', // sent as `env` in every log's context
    autoContext: true,         // send the process id and hostname with every log
    caller: false,             // also send the file, line and function that logged
    callerDepth: 0,            // frames to skip for `caller`, one per function of yours wrapped around Lumber
    sync: false,               // send each log as it's logged, waiting for Lumber, rather than as the request ends
    afterResponse: true,       // finish the response before sending (PHP-FPM, LiteSpeed, FrankenPHP)
    timeout: 10.0,             // seconds to wait for Lumber to answer one log
    flushTimeout: 5.0,         // seconds sending may take at a flush, the end of the request included
    maxBuffer: 1000,           // logs held before a new one sends them there and then
    warnings: fn (string $message) => $other->warning($message), // in place of PHP's error log
);
```

Finishing the response early writes a PHP session first (`session_write_close()`), or the visitor's next request would wait on the logs for the session's lock. Turn `afterResponse` off if something after your script's own end still needs to send output.

## Delivery

A network error or a 5xx counts as a failed attempt, and so does no answer within `timeout`: each log gets 5, waiting 1, 2, 4, then 8 seconds between them, and is then dropped with a warning. A log Lumber refuses (a 4xx, such as an invalid API key) is dropped at once with a warning, since sending it again won't help. When your team has sent its plan's logs for the month, Lumber answers 429: the client warns once and drops logs until the month turns.

Every request starts afresh, so a request that finds Lumber unreachable, or at the monthly limit, leaves a note in the temp directory (named by a hash of your token), and the requests after it skip sending until it's over rather than each finding out for itself. While Lumber can't be reached, they hold off for 1 second, then 2, 4 and so on up to 30, until a log gets through. A Lumber outage costs your servers little, and never your visitors.

Warnings go to PHP's error log (`error_log()`), never through a logger, where they'd be sent back to Lumber, unless you pass `warnings`.

## Long-running workers

A process that serves many requests or jobs sends at the end of each:

- **Laravel**: queue workers (`queue:work`, Horizon) send after each job, and Octane after each request, task and tick, with nothing to set up.
- **Symfony**: Messenger's workers reset loggers between messages, and the Monolog handler sends then.
- **RoadRunner, Swoole, FrankenPHP's worker mode, or your own loop**: call `flush()` after each response or job:

```php
while ($request = $worker->waitRequest()) {
    $worker->respond(handle($request));
    $lumber->flush(); // after the response, so nobody waits on it
}
```

Without a `flush()`, logs still go once 1,000 are held or the process exits, which for a worker could be hours. A forked process (pcntl) starts with an empty buffer and its own connection; the parent sends what it held before the fork.

## Serverless

AWS Lambda (Bref, Laravel Vapor) freezes the process as soon as the response is sent, and Google Cloud Run slows it to a crawl unless CPU is always allocated, so logs held for after the response may not go until the next request, or at all. Use `sync: true` (`LUMBER_SYNC=true` in Laravel): each log is sent as it's logged, and the request waits for Lumber to answer, about a tenth of a second a log.

## Development

```bash
composer install
composer test && composer analyse && composer lint
```

`tests/contract` holds [Lumber-Kit](https://github.com/infinitetoken/lumber-kit)'s contract at the version in `tests/contract/VERSION`: a JSON Schema of the wire format and known answers for API keys, encryption, payloads and delivery, which Lumber's Node, Python and Go clients are tested against too. `composer contract -- 0.8.0` moves it to a new kit version. The tests start PHP's built-in web server as a stand-in for Lumber's API.

## License

MIT. See `LICENSE`.
