<?php

declare(strict_types=1);

namespace App\Domain\Communication;

/**
 * Value object returned by IngestPostProcessor::matchPreFilter
 * when a known automated-mail pattern is detected on an incoming
 * message. Lets the caller log + close the conversation with a
 * descriptive reason ("which pattern fired") without re-running the
 * matching logic.
 *
 * Replaces the legacy boolean return of isLegitimateSender (which
 * forced callers to either re-grep the patterns for audit purposes
 * or carry side-channel state).
 */
final readonly class PreFilterMatch
{
    public const KIND_DOMAIN = 'domain';
    public const KIND_LOCAL_PART = 'local_part';
    public const KIND_SUBJECT = 'subject';
    public const KIND_OPERATOR_TEST = 'operator_test';

    public function __construct(
        public string $kind,
        public string $pattern,
    ) {
    }
}
