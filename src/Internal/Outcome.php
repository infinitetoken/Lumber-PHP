<?php

declare(strict_types=1);

namespace InfiniteToken\Lumber\Internal;

/**
 * What to do with a log once POST /logs answers (Lumber-Kit's LogDeliveryOutcome).
 *
 * @internal
 */
enum Outcome: string
{
    /** Lumber has it. */
    case Sent = 'sent';

    /** No answer, or one that may change (a 5xx): try again after a wait. */
    case Retry = 'retry';

    /** Refused (a 4xx, such as an invalid API key): sending it again won't help. */
    case Drop = 'drop';

    /** The team has sent its plan's logs for the month (a 429): send nothing until Retry-After. */
    case Pause = 'pause';
}
