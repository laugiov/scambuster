<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\UI\Console\LoadDemoDataCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the demo per-message stimulus derivation.
 *
 * The demo has no LLM key, so ioc_context.stimulus_type is seeded
 * deterministically. This pins the contract that the value (a) is PASSIVE on
 * first contact, (b) varies within a conversation by turn position along a
 * plausible per-scam arc, and (c) is a pure function of its inputs — no
 * randomness, so a purge/reseed reproduces exactly.
 */
final class LoadDemoDataStimulusTest extends TestCase
{
    /** The seven closed stimulus values the demo may emit. */
    private const CLOSED_SET = [
        'PASSIVE', 'TRUST_BUILDING', 'DIRECT_REQUEST', 'DOCUMENT_REQUEST',
        'PAYMENT_INITIATION', 'URGENCY_PRESSURE', 'UNKNOWN',
    ];

    public function testFirstContactIsAlwaysPassiveRegardlessOfScamOrTurn(): void
    {
        foreach (['INVOICE_FRAUD', 'ROMANCE', 'PHISHING', 'TECH_SUPPORT', 'UNKNOWN_GENRE'] as $scam) {
            foreach ([1, 3, 7, 12] as $turn) {
                self::assertSame(
                    'PASSIVE',
                    LoadDemoDataCommand::deriveDemoStimulus($scam, $turn, false),
                    sprintf('A revelation with no preceding outbound must be PASSIVE (%s turn %d)', $scam, $turn),
                );
            }
        }
    }

    public function testStimulusVariesByTurnWithinAConversation(): void
    {
        // Invoice-fraud arc: early -> mid -> late -> closing.
        self::assertSame('DIRECT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 3, true));
        self::assertSame('DOCUMENT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 5, true));
        self::assertSame('PAYMENT_INITIATION', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 7, true));
        self::assertSame('URGENCY_PRESSURE', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 9, true));

        // The four turns above are not all the same value — this is the whole
        // point of the change (no constant per scam type).
        $values = [
            LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 3, true),
            LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 5, true),
            LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 7, true),
        ];
        self::assertCount(3, array_unique($values), 'Stimulus must differ across early/mid/late turns');
    }

    public function testTurnBucketBoundaries(): void
    {
        // early bucket: turns 2 and 3 share the arc's first slot.
        self::assertSame('DIRECT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 2, true));
        self::assertSame('DIRECT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 3, true));
        // mid bucket: turns 4 and 5.
        self::assertSame('DOCUMENT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 4, true));
        self::assertSame('DOCUMENT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 5, true));
        // closing bucket: any turn >= 8.
        self::assertSame('URGENCY_PRESSURE', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 8, true));
        self::assertSame('URGENCY_PRESSURE', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 20, true));
    }

    public function testArcDiffersByScamType(): void
    {
        // Same turn, different genres yield different stimuli.
        self::assertSame('TRUST_BUILDING', LoadDemoDataCommand::deriveDemoStimulus('INVESTMENT', 3, true));
        self::assertSame('DIRECT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 3, true));
        self::assertSame('URGENCY_PRESSURE', LoadDemoDataCommand::deriveDemoStimulus('TECH_SUPPORT', 3, true));
    }

    public function testUnknownAppearsForPhishingMidAndTechClosing(): void
    {
        self::assertSame('UNKNOWN', LoadDemoDataCommand::deriveDemoStimulus('PHISHING', 5, true));
        self::assertSame('UNKNOWN', LoadDemoDataCommand::deriveDemoStimulus('PHISH_CREDENTIALS', 5, true));
        self::assertSame('UNKNOWN', LoadDemoDataCommand::deriveDemoStimulus('TECH_SUPPORT', 9, true));
    }

    public function testUnknownScamTypeFallsBackToDefaultArc(): void
    {
        self::assertSame('DIRECT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('SOME_NEW_GENRE', 3, true));
        self::assertSame('DOCUMENT_REQUEST', LoadDemoDataCommand::deriveDemoStimulus('SOME_NEW_GENRE', 5, true));
        self::assertSame('PAYMENT_INITIATION', LoadDemoDataCommand::deriveDemoStimulus('SOME_NEW_GENRE', 7, true));
    }

    public function testDerivationIsDeterministic(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame('PAYMENT_INITIATION', LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 7, true));
        }
    }

    public function testEveryEmittedValueIsInTheClosedSetAndAllSevenAreReachable(): void
    {
        $scams = [
            'INVOICE_FRAUD', 'CEO_FRAUD', 'ADVANCE_FEE_419', 'LOTTERY', 'ROMANCE',
            'CHARITY', 'INVESTMENT', 'TECH_SUPPORT', 'JOB_OFFER', 'PHISHING',
            'PHISH_CREDENTIALS', 'PHISH_MALWARE', 'COLD_SERVICE_SPAM',
        ];
        $seen = [];

        foreach ($scams as $scam) {
            // first contact + all four buckets
            $seen[LoadDemoDataCommand::deriveDemoStimulus($scam, 1, false)] = true;
            foreach ([3, 5, 7, 9] as $turn) {
                $value = LoadDemoDataCommand::deriveDemoStimulus($scam, $turn, true);
                self::assertContains($value, self::CLOSED_SET, sprintf('Emitted %s is outside the closed set', $value));
                $seen[$value] = true;
            }
        }

        foreach (self::CLOSED_SET as $value) {
            self::assertArrayHasKey($value, $seen, sprintf('Value %s is unreachable across the demo scam set', $value));
        }
    }
}
