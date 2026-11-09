<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use InfiniteToken\Lumber\Lumber;

/**
 * Lumber in Laravel, found by package discovery: the app's one Lumber client, configured from `.env` (config/lumber.php),
 * and a `lumber` log channel to add to LOG_STACK or pick with LOG_CHANNEL.
 *
 * Logs are sent when each unit of work ends: a request, after its response has gone; a queued job; and Octane's
 * requests, tasks and ticks. Queue workers and Octane serve many of those from one long-running process, so waiting for
 * the process to end would hold their logs for hours.
 */
final class LumberServiceProvider extends ServiceProvider
{
    /** Octane's events, named as strings since Octane may not be installed */
    private const OCTANE_EVENTS = [
        'Laravel\Octane\Events\RequestTerminated',
        'Laravel\Octane\Events\TaskTerminated',
        'Laravel\Octane\Events\TickTerminated',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(self::configFile(), 'lumber');

        $this->app->singleton(Lumber::class, static function (Application $app): Lumber {
            $config = $app->make(Repository::class);
            // Laravel's environment (APP_ENV) unless LUMBER_ENVIRONMENT is set; set but empty sends none
            $environment = $config->get('lumber.environment') ?? $app->environment();

            return new Lumber(
                apiKey: self::string($config->get('lumber.api_key')),
                environment: \is_string($environment) && $environment !== '' ? $environment : null,
                autoContext: (bool) $config->get('lumber.auto_context', true),
                sync: (bool) $config->get('lumber.sync', false),
                timeout: (float) self::number($config->get('lumber.timeout'), 10.0),
                flushTimeout: (float) self::number($config->get('lumber.flush_timeout'), 5.0),
                maxBuffer: (int) self::number($config->get('lumber.max_buffer'), 1_000),
                endpoint: self::string($config->get('lumber.endpoint')) ?: Lumber::DEFAULT_ENDPOINT,
            );
        });

        // The channel, unless config/logging.php defines its own
        $config = $this->app->make(Repository::class);
        if (!$config->has('logging.channels.lumber')) {
            $config->set('logging.channels.lumber', ['driver' => 'custom', 'via' => LumberChannel::class]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::configFile() => $this->app->configPath('lumber.php')], 'lumber-config');
        }

        $flush = function (): void {
            if ($this->app->resolved(Lumber::class)) {
                $this->app->make(Lumber::class)->flush();
            }
        };
        // Runs as the request ends, after Laravel has sent the response (PHP-FPM) or at the end of an Artisan command
        $this->app->terminating($flush);
        $this->app->make(Dispatcher::class)->listen(
            [JobProcessed::class, JobExceptionOccurred::class, Looping::class, ...self::OCTANE_EVENTS],
            $flush,
        );
    }

    private static function configFile(): string
    {
        return \dirname(__DIR__, 2) . '/config/lumber.php';
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function number(mixed $value, float|int $default): float|int
    {
        return is_numeric($value) ? +$value : $default;
    }
}
