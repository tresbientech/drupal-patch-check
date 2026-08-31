<?php

declare(strict_types=1);

namespace Tresbien\Drupatch\Resolve;

/**
 * Whether one release is a candidate.
 *
 * A release qualifies when it says it supports the target core and the
 * site's PHP satisfies what it asks for. Either question can go
 * unanswered, and an unanswered question is not a no: the release is
 * left out and the reason is carried, rather than one check guessing
 * that it qualifies and the other guessing that it does not.
 */
final class Eligibility
{
    private function __construct(
        /** Whether the release is a candidate. */
        public readonly bool $keep,
        /** Why it was left out despite nothing saying no; empty otherwise. */
        public readonly string $reason,
    ) {
    }

    /**
     * @param Answer $supportsTarget whether the release says it supports the target core
     * @param Answer $runnable       whether the site's PHP satisfies the release
     */
    public static function of(Answer $supportsTarget, Answer $runnable): self
    {
        if (Answer::Unread === $supportsTarget) {
            return new self(false, 'its drupal/core requirement could not be read');
        }
        if (Answer::Unread === $runnable) {
            return new self(false, 'its php requirement could not be read');
        }

        return new self(Answer::Yes === $supportsTarget && Answer::Yes === $runnable, '');
    }
}
