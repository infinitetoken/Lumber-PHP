<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Tests\Support;

use InfiniteToken\Lumber\Internal\Answer;
use InfiniteToken\Lumber\Internal\Transport;

/**
 * Answers each post with the next scripted answer (201 once they run out), keeping what was posted.
 */
final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $bodies = [];

    /** @var list<float> */
    public array $timeouts = [];

    public int $resets = 0;

    /** @var list<Answer|\Closure(): Answer> */
    private array $answers;

    /**
     * @param Answer|\Closure(): Answer ...$answers
     */
    public function __construct(Answer|\Closure ...$answers)
    {
        $this->answers = array_values($answers);
    }

    /**
     * @param Answer|\Closure(): Answer ...$answers
     */
    public function answer(Answer|\Closure ...$answers): void
    {
        array_push($this->answers, ...array_values($answers));
    }

    public function post(string $body, float $timeout): Answer
    {
        $this->bodies[] = $body;
        $this->timeouts[] = $timeout;
        $answer = array_shift($this->answers) ?? new Answer(201);

        return $answer instanceof \Closure ? $answer() : $answer;
    }

    public function reset(): void
    {
        ++$this->resets;
    }

    /**
     * The posted bodies, decoded, with JSON objects as stdClass.
     *
     * @return list<\stdClass>
     */
    public function sent(): array
    {
        return array_map(static fn(string $body): \stdClass => json_decode($body, false, 512, \JSON_THROW_ON_ERROR), $this->bodies);
    }
}
