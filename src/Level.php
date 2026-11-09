<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Lumber's log levels. The numbers are the API's: never change them.
 */
enum Level: int
{
    case Debug = 0;
    case Info = 1;
    case Warn = 2;
    case Error = 3;
    case Fatal = 4;

    /**
     * Lumber's level for a PSR-3 level (Psr\Log\LogLevel's names), a Level, or Lumber's own names (warn, fatal). PSR-3
     * has eight levels to Lumber's five: notice is INFO, and critical, alert and emergency are FATAL.
     *
     * @throws InvalidArgumentException for any other level, as PSR-3 asks
     */
    public static function of(mixed $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        return match (\is_string($level) ? strtolower($level) : $level) {
            LogLevel::DEBUG => self::Debug,
            LogLevel::INFO, LogLevel::NOTICE => self::Info,
            LogLevel::WARNING, 'warn' => self::Warn,
            LogLevel::ERROR => self::Error,
            LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY, 'fatal' => self::Fatal,
            default => throw new InvalidArgumentException(\sprintf(
                '[lumber] Unknown log level %s: use a PSR-3 level (Psr\Log\LogLevel) or %s.',
                \is_scalar($level) ? var_export($level, true) : get_debug_type($level),
                self::class,
            )),
        };
    }
}
