<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Resolve;

/**
 * A yes-or-no question that a release's own requirements may not
 * answer.
 *
 * A boolean has room for two of these three, which is how a release the
 * data could not read came to count as a release that said no.
 */
enum Answer
{
    case Yes;
    case No;

    /** The requirement could not be read, so there is no answer. */
    case Unread;

    public static function of(bool $value): self
    {
        return $value ? self::Yes : self::No;
    }
}
