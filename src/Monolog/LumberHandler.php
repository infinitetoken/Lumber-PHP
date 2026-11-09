<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Monolog;

use InfiniteToken\Lumber\Level;
use InfiniteToken\Lumber\Lumber;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Handler\ProcessableHandlerTrait;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;
use Psr\Log\LogLevel;

/**
 * Sends Monolog's records to Lumber (Monolog 3), so code that already logs through Monolog (Laravel, Symfony) sends to
 * Lumber too.
 *
 * Each record becomes a log at the matching level: NOTICE is INFO, and CRITICAL, ALERT and EMERGENCY are FATAL. Its
 * context and extra (what processors add) are the log's data, as Lumber::log() takes them, with the context winning;
 * the record's channel goes into the log's context.
 */
final class LumberHandler extends AbstractHandler implements ProcessableHandlerInterface
{
    use ProcessableHandlerTrait;

    private readonly Lumber $lumber;

    /**
     * @param Lumber|string $lumber a client, to share it with your own calls, or an API key to make one with the defaults
     * @param int|string|MonologLevel $level the lowest level sent
     * @param bool $bubble whether records go on to the handlers after this one
     *
     * @phpstan-param value-of<MonologLevel::VALUES>|value-of<MonologLevel::NAMES>|MonologLevel|LogLevel::* $level
     */
    public function __construct(
        #[\SensitiveParameter]
        Lumber|string $lumber,
        int|string|MonologLevel $level = MonologLevel::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->lumber = \is_string($lumber) ? new Lumber($lumber) : $lumber;
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }
        if ($this->processors !== []) {
            $record = $this->processRecord($record);
        }
        $this->lumber->record(
            self::level($record->level),
            $record->message,
            array_replace($record->extra, $record->context),
            ['channel' => $record->channel],
        );

        return $this->bubble === false;
    }

    /**
     * The client records go to.
     */
    public function getLumber(): Lumber
    {
        return $this->lumber;
    }

    /**
     * Monolog ends a logging session: send what's held.
     */
    public function close(): void
    {
        $this->lumber->flush();
        parent::close();
    }

    /**
     * Symfony (between Messenger's messages, and requests under a long-running runtime) and other long-running hosts reset
     * loggers between units of work: send what the last one logged.
     */
    public function reset(): void
    {
        $this->lumber->flush();
        parent::reset();
        $this->resetProcessors();
    }

    /**
     * Lumber's level for a Monolog level.
     */
    public static function level(MonologLevel $level): Level
    {
        return match ($level) {
            MonologLevel::Debug => Level::Debug,
            MonologLevel::Info, MonologLevel::Notice => Level::Info,
            MonologLevel::Warning => Level::Warn,
            MonologLevel::Error => Level::Error,
            MonologLevel::Critical, MonologLevel::Alert, MonologLevel::Emergency => Level::Fatal,
        };
    }
}
