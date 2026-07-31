<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Clustering;

use App\Domain\Clustering\Service\ClusterStixIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for deterministic STIX ID generation for clusters.
 * Written FIRST (TDD red) — ClusterStixIdGenerator class does not exist yet.
 */
final class ClusterStixIdGeneratorTest extends TestCase
{
    private ClusterStixIdGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ClusterStixIdGenerator();
    }

    public function testSameSetSameUuid(): void
    {
        $id1 = $this->generator->generate(['FR7630006000011234567890189', '+33612345678']);
        $id2 = $this->generator->generate(['FR7630006000011234567890189', '+33612345678']);

        $this->assertSame($id1, $id2);
    }

    public function testSameSetDifferentOrderSameUuid(): void
    {
        $id1 = $this->generator->generate(['aaa', 'bbb', 'ccc']);
        $id2 = $this->generator->generate(['ccc', 'aaa', 'bbb']);

        $this->assertSame($id1, $id2);
    }

    public function testDifferentSetDifferentUuid(): void
    {
        $id1 = $this->generator->generate(['value_a']);
        $id2 = $this->generator->generate(['value_b']);

        $this->assertNotSame($id1, $id2);
    }

    public function testFormatStartsWithThreatActor(): void
    {
        $id = $this->generator->generate(['test_value']);

        $this->assertStringStartsWith('threat-actor--', $id);
    }

    public function testFormatContainsValidUuid(): void
    {
        $id = $this->generator->generate(['test_value']);
        $uuid = str_replace('threat-actor--', '', $id);

        // UUID v5 format: 8-4-4-4-12 hex chars
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'Should be a valid UUID v5'
        );
    }

    public function testEmptyInputThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator->generate([]);
    }

    public function testSingleValueWorks(): void
    {
        $id = $this->generator->generate(['single_value']);

        $this->assertStringStartsWith('threat-actor--', $id);
        $this->assertGreaterThan(14, strlen($id)); // "threat-actor--" + UUID
    }

    public function testDuplicateValuesDeduped(): void
    {
        $id1 = $this->generator->generate(['aaa', 'bbb']);
        $id2 = $this->generator->generate(['aaa', 'bbb', 'aaa']);

        $this->assertSame($id1, $id2);
    }
}
