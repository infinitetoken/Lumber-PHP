<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * Sends one log to POST /logs.
 *
 * @internal
 */
interface Transport
{
    /**
     * Posts one log's body, waiting `$timeout` seconds at most for the answer. Never throws: no answer is a null status.
     */
    public function post(string $body, float $timeout): Answer;

    /**
     * Lets go of the connection, as a forked child must: it can't share its parent's.
     */
    public function reset(): void;
}
