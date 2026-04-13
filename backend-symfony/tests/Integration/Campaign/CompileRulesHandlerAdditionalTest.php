<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CompileRulesHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Additional integration tests for CompileRulesHandler.
 *
 * Covers:
 * - Exception message format for missing campaign
 * - Exception message format for missing profile
 * - Examples with positive and negative entries
 * - Handler is an instance of the correct class
 */
final class CompileRulesHandlerAdditionalTest extends KernelTestCase
{
    private CompileRulesHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CompileRulesHandler::class);
    }

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
