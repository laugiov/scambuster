<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\DSLTranspiler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DSLTranspilerTest extends TestCase
{
    private DSLTranspiler $transpiler;

    protected function setUp(): void
    {
        $this->transpiler = new DSLTranspiler(new NullLogger());
    }

    public function testTranspileSimhashPredicate(): void
    {
        $dsl = 'RULE test { WHERE subject.simhash≈"avis important" ±15% ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertArrayHasKey('tests', $result);

        $this->assertStringContainsString("similarity(subject, :p0) >= 0.85", $result['sql']);
        $this->assertArrayHasKey('p0', $result['params']);
        $this->assertEquals('avis important', $result['params']['p0']);
    }

    public function testTranspileContainsAnyPredicate(): void
    {
        $dsl = 'RULE test { WHERE body.containsAny ["urgent","vérifier"] ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertStringContainsString("body_text ILIKE ANY(ARRAY[:p0,:p1])", $result['sql']);
        $this->assertArrayHasKey('p0', $result['params']);
        $this->assertArrayHasKey('p1', $result['params']);
        $this->assertEquals('%urgent%', $result['params']['p0']);
        $this->assertEquals('%vérifier%', $result['params']['p1']);
    }

    public function testTranspileDomainAgePredicate(): void
    {
        $dsl = 'RULE test { WHERE url.domain.age < 14d ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertStringContainsString("(headers->'url_meta'->>'age_days')::int < :p0", $result['sql']);
        $this->assertArrayHasKey('p0', $result['params']);
        $this->assertEquals(14, $result['params']['p0']);
    }

    public function testTranspileSenderFuzzyPredicate(): void
    {
        $dsl = 'RULE test { WHERE sender.display_name.fuzzy ∈ {"Support","Admin"} ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertStringContainsString("similarity(headers->>'from_display', :p0) >= 0.7", $result['sql']);
        $this->assertArrayHasKey('p0', $result['params']);
        $this->assertEquals('Support', $result['params']['p0']);
    }

    public function testTranspileDkimPredicate(): void
    {
        $dsl = 'RULE test { WHERE dkim.pass ∈ {false, null} ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertStringContainsString("(headers->'auth'->>'dkim')::bool IS NOT TRUE", $result['sql']);
    }

    public function testTranspileSpfPredicate(): void
    {
        $dsl = 'RULE test { WHERE spf.pass ∈ {false, null} ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertStringContainsString("(headers->'auth'->>'spf')::bool IS NOT TRUE", $result['sql']);
    }

    public function testTranspileMultiplePredicates(): void
    {
        $dsl = 'RULE test { WHERE subject.simhash≈"urgent" ±10% AND body.containsAny ["cliquer"] AND dkim.pass ∈ {false, null} ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        $this->assertStringContainsString("similarity(subject", $result['sql']);
        $this->assertStringContainsString("body_text ILIKE", $result['sql']);
        $this->assertStringContainsString("dkim", $result['sql']);
        $this->assertStringContainsString(" AND ", $result['sql']);

        // Verify that the parameters are present
        $this->assertArrayHasKey('p0', $result['params']); // simhash value
        $this->assertArrayHasKey('p1', $result['params']); // containsAny value
        $this->assertEquals('urgent', $result['params']['p0']);
        $this->assertEquals('%cliquer%', $result['params']['p1']);
    }

    public function testTranspileThrowsOnMissingWhereClause(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WHERE clause not found');

        $dsl = 'RULE test { ACTION tag="test" }';
        $this->transpiler->transpile($dsl);
    }

    public function testTranspileThrowsOnUnsupportedPredicate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported predicate');

        $dsl = 'RULE test { WHERE unsupported.predicate > 5 ACTION tag="test" }';
        $this->transpiler->transpile($dsl);
    }

    public function testTranspileReturnsSQLWithLimit(): void
    {
        $dsl = 'RULE test { WHERE subject.simhash≈"test" ±10% ACTION tag="test" }';

        $result = $this->transpiler->transpile($dsl);

        // Verify that the SQL contains ORDER BY and LIMIT
        $this->assertStringContainsString("ORDER BY ts_msg DESC", $result['sql']);
        $this->assertStringContainsString("LIMIT 100", $result['sql']);
    }
}
