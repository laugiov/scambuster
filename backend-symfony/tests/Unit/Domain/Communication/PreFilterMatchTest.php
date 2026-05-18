<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\PreFilterMatch;
use PHPUnit\Framework\TestCase;

/**
 * Spec 083 T02 — typed VO that lets IngestPostProcessor::matchPreFilter
 * tell the caller WHICH pattern fired (kind + literal pattern) so the
 * caller can build a descriptive closure_reason + audit log entry
 * without re-running the matching.
 */
final class PreFilterMatchTest extends TestCase
{
    public function test_constructor_stores_kind_and_pattern(): void
    {
        $match = new PreFilterMatch(kind: 'domain', pattern: 'github.com');

        self::assertSame('domain', $match->kind);
        self::assertSame('github.com', $match->pattern);
    }

    public function test_kind_constants_are_defined(): void
    {
        // The four kinds we accept — exhaustive enumeration. Callers
        // should use the constants instead of typing literals.
        self::assertSame('domain', PreFilterMatch::KIND_DOMAIN);
        self::assertSame('local_part', PreFilterMatch::KIND_LOCAL_PART);
        self::assertSame('subject', PreFilterMatch::KIND_SUBJECT);
        self::assertSame('operator_test', PreFilterMatch::KIND_OPERATOR_TEST);
    }
}
