<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CompileRulesHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for CompileRulesHandler.
 *
 * Uses real container services. Since CompileRulesHandler, CampaignRepository,
 * and RuleCompiler are all final classes, they cannot be mocked — we test
 * via the Symfony DI container instead.
 */
class CompileRulesHandlerTest extends KernelTestCase
{
    private CompileRulesHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CompileRulesHandler::class);
    }

    public function testHandlerIsWiredInContainer(): void
    {
        $this->assertInstanceOf(CompileRulesHandler::class, $this->handler);
    }

    public function testHandleThrowsForNonexistentCampaign(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $this->handler->handle(Uuid::v4());
    }

    public function testHandleThrowsForCampaignWithoutProfile(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();

        // Find a campaign with no profile_yaml
        $campaignId = $conn->executeQuery(
            'SELECT campaign_id FROM campaign WHERE profile_yaml IS NULL LIMIT 1'
        )->fetchOne();

        if ($campaignId === false) {
            $this->markTestSkipped('No campaign without profile in fixture database');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign has no profile');

        $this->handler->handle(Uuid::fromString((string) $campaignId));
    }

    /**
     * If a campaign with profile exists, attempt compilation.
     * This test is conditional and may be skipped in environments without LLM access.
     */
    public function testHandleWithProfiledCampaign(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();

        $campaignId = $conn->executeQuery(
            'SELECT campaign_id FROM campaign WHERE profile_yaml IS NOT NULL LIMIT 1'
        )->fetchOne();

        if ($campaignId === false) {
            $this->markTestSkipped('No campaign with profile_yaml in fixture database');
        }

        // The handler calls RuleCompiler which calls LLM — this may fail
        // in test environments without API keys, which is acceptable.
        try {
            $result = $this->handler->handle(Uuid::fromString((string) $campaignId));

            $this->assertArrayHasKey('rules_dsl', $result);
            $this->assertArrayHasKey('rules_count', $result);
            $this->assertArrayHasKey('attempts', $result);
            $this->assertArrayHasKey('rule_ids', $result);
            $this->assertIsString($result['rules_dsl']);
            $this->assertIsInt($result['rules_count']);
            $this->assertIsArray($result['rule_ids']);
        } catch (\RuntimeException $e) {
            // LLM call failure is acceptable in test env
            $this->assertStringContainsString('compilation failed', strtolower($e->getMessage()));
        }
    }

    public function testHandleAcceptsEmptyExamples(): void
    {
        // Verify the method signature works with default examples
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $this->handler->handle(Uuid::v4(), ['pos' => [], 'neg' => []]);
    }

    // ================================================================== //
    //  Merged from CompileRulesHandlerAdditionalTest
    // ================================================================== //

    public function testNotFoundMessageContainsUuid(): void
    {
        $uuid = Uuid::v4();

        try {
            $this->handler->handle($uuid);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Campaign not found', $e->getMessage());
            $this->assertStringContainsString($uuid->toRfc4122(), $e->getMessage());
        }
    }

    public function testNoProfileMessageContainsCampaignId(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();

        $campaignId = $conn->executeQuery(
            'SELECT campaign_id FROM campaign WHERE profile_yaml IS NULL LIMIT 1'
        )->fetchOne();

        if ($campaignId === false) {
            $this->markTestSkipped('No campaign without profile in fixture database');
        }

        try {
            $this->handler->handle(Uuid::fromString((string) $campaignId));
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Campaign has no profile', $e->getMessage());
            $this->assertStringContainsString((string) $campaignId, $e->getMessage());
        }
    }

    public function testHandleWithExamplesStillThrowsForMissingCampaign(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $examples = [
            'pos' => [['subject' => 'Urgent wire', 'body' => 'Send money now']],
            'neg' => [['subject' => 'Newsletter', 'body' => 'Weekly digest']],
        ];

        $this->handler->handle(Uuid::v4(), $examples);
    }

    public function testHandlerReturnsCorrectInstanceType(): void
    {
        $this->assertInstanceOf(CompileRulesHandler::class, $this->handler);
    }
}
