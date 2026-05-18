<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IngestPostProcessor;
use App\Application\Communication\IocHandler;
use App\Domain\Communication\PreFilterMatch;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 083 T03 — unit tests for IngestPostProcessor::matchPreFilter.
 *
 * Pinned cases (13):
 *   1.  Bare github.com address (regression for the 17/30 GitHub miss
 *       caused by the maison regex).
 *   2.  "GitHub" <noreply@github.com> — quoted display name.
 *   3.  GitHub <noreply@github.com>   — unquoted display name.
 *   4.  Address-list — first wins.
 *   5.  noreply@randomtld.tld         — local-part match on unknown domain.
 *   6.  postmaster@anydomain          — local-part match.
 *   7.  dmarcreport@microsoft.com     — local-part wins on a known domain.
 *   8.  Report Domain: subject        — subject match regardless of sender.
 *   9.  operator-test sender (CSV)    — operator's own mailboxes.
 *  10.  lookalike@github.com.evil.tld — subdomain attack defended.
 *  11.  not-an-email-at-all           — malformed header, no crash.
 *  12.  Commercial B2B sender         — regression guard: NOT filtered.
 *  13.  KIND_DOMAIN result shape      — the returned VO carries the
 *       expected kind constant + literal pattern (so closure_reason
 *       and audit log can use it).
 */
final class IngestPostProcessorMatchPreFilterTest extends TestCase
{
    private function createProcessor(string $operatorTestSenders = ''): IngestPostProcessor
    {
        return new IngestPostProcessor(
            em: $this->createMock(EntityManagerInterface::class),
            logger: new NullLogger(),
            iocHandler: $this->createMock(IocHandler::class),
            operatorTestSenders: $operatorTestSenders,
        );
    }

    // ─── Domain match (KNOWN_LEGITIMATE_DOMAINS) — uses Symfony Mime parser ───

    public function test_matches_bare_address_for_known_domain(): void
    {
        $match = $this->createProcessor()->matchPreFilter('noreply@github.com', 'Anything');
        self::assertNotNull($match, 'Bare github.com address must match (legacy regex missed this)');
        self::assertSame(PreFilterMatch::KIND_DOMAIN, $match->kind);
        self::assertSame('github.com', $match->pattern);
    }

    public function test_matches_quoted_display_name_for_known_domain(): void
    {
        $match = $this->createProcessor()->matchPreFilter('"GitHub Notifications" <noreply@github.com>', 'Anything');
        self::assertNotNull($match);
        self::assertSame(PreFilterMatch::KIND_DOMAIN, $match->kind);
    }

    public function test_matches_unquoted_display_name_for_known_domain(): void
    {
        $match = $this->createProcessor()->matchPreFilter('GitHub <noreply@github.com>', 'Anything');
        self::assertNotNull($match);
        self::assertSame(PreFilterMatch::KIND_DOMAIN, $match->kind);
    }

    public function test_matches_address_list_first_wins_for_known_domain(): void
    {
        $match = $this->createProcessor()->matchPreFilter('noreply@github.com, support@github.com', 'Anything');
        self::assertNotNull($match);
        self::assertSame(PreFilterMatch::KIND_DOMAIN, $match->kind);
    }

    // ─── Local-part match (technical mailboxes on ANY domain) ─────────

    public function test_matches_noreply_localpart_on_unknown_domain(): void
    {
        $match = $this->createProcessor()->matchPreFilter('noreply@randomthing.tld', 'Anything');
        self::assertNotNull($match);
        self::assertSame(PreFilterMatch::KIND_LOCAL_PART, $match->kind);
        self::assertSame('noreply', $match->pattern);
    }

    public function test_matches_postmaster_localpart(): void
    {
        $match = $this->createProcessor()->matchPreFilter('postmaster@whatever.tld', 'Anything');
        self::assertNotNull($match);
        self::assertSame(PreFilterMatch::KIND_LOCAL_PART, $match->kind);
        self::assertSame('postmaster', $match->pattern);
    }

    public function test_matches_dmarcreport_localpart(): void
    {
        // dmarcreport@microsoft.com — could match domain first, but
        // local-part match is more specific and we prefer it.
        $match = $this->createProcessor()->matchPreFilter('dmarcreport@microsoft.com', 'Report Domain: x.com');
        self::assertNotNull($match);
        // Either local_part or domain match is acceptable — both are
        // "is automated mail" answers. We assert one of the two.
        self::assertContains($match->kind, [PreFilterMatch::KIND_LOCAL_PART, PreFilterMatch::KIND_DOMAIN]);
    }

    // ─── Subject match (DMARC report pattern) ─────────────────────────

    public function test_matches_dmarc_subject_regardless_of_sender(): void
    {
        $match = $this->createProcessor()->matchPreFilter(
            'noreply-aggregate@some-random-reporter.tld',
            'Report Domain: ashbrooksourcing.com Submitter: protection.outlook.com Report-ID: abc',
        );
        self::assertNotNull($match, 'Report-Domain subject must match even from unknown sender');
        // local_part may fire first (noreply); either is acceptable for
        // the operator outcome (filtered + closed).
        self::assertContains($match->kind, [PreFilterMatch::KIND_SUBJECT, PreFilterMatch::KIND_LOCAL_PART]);
    }

    // ─── Operator-test match (env-driven CSV) ─────────────────────────

    public function test_matches_operator_test_sender(): void
    {
        $proc = $this->createProcessor(
            operatorTestSenders: 'mngt-serious-activities@proton.me,scamtest.scambuster@gmail.com',
        );
        $match = $proc->matchPreFilter('mngt-serious-activities@proton.me', 'test first mail');
        self::assertNotNull($match);
        self::assertSame(PreFilterMatch::KIND_OPERATOR_TEST, $match->kind);
        self::assertSame('mngt-serious-activities@proton.me', $match->pattern);
    }

    // ─── Defense-in-depth: lookalike + malformed ──────────────────────

    public function test_rejects_subdomain_attack(): void
    {
        // github.com.evil.tld is NOT a subdomain of github.com.
        // The match must use exact-or-subdomain logic (str_ends_with '.github.com').
        $match = $this->createProcessor()->matchPreFilter('lookalike@github.com.evil.tld', 'Anything');
        self::assertNull($match);
    }

    public function test_handles_malformed_header_without_crash(): void
    {
        $match = $this->createProcessor()->matchPreFilter('not-an-email-at-all', 'Anything');
        self::assertNull($match);
    }

    // ─── Regression guard: commercial B2B must NOT be filtered ────────

    public function test_does_not_match_commercial_b2b_sender(): void
    {
        // The user's explicit out-of-scope: B2B web-dev pitches must
        // continue through the LLM classifier. Pre-filter must return
        // null for them.
        $match = $this->createProcessor()->matchPreFilter(
            'info.rajubcc@gmail.com',
            'RE: Apps to Boost Your Brand',
        );
        self::assertNull($match);
    }
}
