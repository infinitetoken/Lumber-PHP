<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InfiniteToken\Lumber\Lumber;
use InfiniteToken\Lumber\Monolog\LumberHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Makes the `lumber` log channel (a `custom` driver's `via`): a Monolog logger sending to the app's one Lumber client.
 *
 * Its config takes Laravel's usual keys: `level` (the lowest level sent, LUMBER_LEVEL's unless set), `bubble`,
 * `replace_placeholders` (fill `{placeholders}` in messages from the context, on unless false) and `name`.
 */
final class LumberChannel
{
    public function __construct(private readonly Container $app) {}

    /**
     * @param array<string, mixed> $config
     */
    public function __invoke(array $config): Logger
    {
        $handler = new LumberHandler(
            $this->app->make(Lumber::class),
            self::level($config['level'] ?? $this->app->make(Repository::class)->get('lumber.level') ?? 'debug'),
            (bool) ($config['bubble'] ?? true),
        );
        if (($config['replace_placeholders'] ?? true) !== false) {
            $handler->pushProcessor(new PsrLogMessageProcessor());
        }
        $name = $config['name'] ?? 'laravel';

        return new Logger(\is_string($name) ? $name : 'laravel', [$handler]);
    }

    /**
     * A level as Laravel's config names it: debug, info, notice, warning, error, critical, alert or emergency.
     */
    private static function level(mixed $level): MonologLevel
    {
        if ($level instanceof MonologLevel) {
            return $level;
        }
        foreach (MonologLevel::cases() as $case) {
            if (\is_string($level) && strcasecmp($case->name, $level) === 0 || $level === $case->value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(\sprintf('[lumber] Invalid log level %s for the lumber channel.', var_export($level, true)));
    }
}
