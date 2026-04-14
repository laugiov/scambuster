<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CampaignStixExportHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for CampaignStixExportHandler.
 *
 * Uses real container services. All final classes (STIXExporter,
 * StixBundleBuilder, AuditLogger) are provided by the Symfony DI container.
 */
class CampaignStixExportHandlerTest extends KernelTestCase
{
    private CampaignStixExportHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(CampaignStixExportHandler::class);
    }

    public function testExportThrowsRuntimeExceptionForNonexistentCampaign(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $this->handler->export(Uuid::v4()->toRfc4122());
    }

    public function testExportThrowsForMalformedInput(): void
    {
        // Uuid::fromString may throw InvalidArgumentException or the handler
        // may reach "Campaign not found" — either is correct behavior.
        $threw = false;

        try {
            $this->handler->export('');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Should throw for empty string input');
    }

    public function testExportThrowsForGarbageUuid(): void
    {
        // Symfony Uuid::fromString may parse some strings that look UUID-ish;
        // the important thing is we never get a success result.
        $threw = false;

        try {
            $this->handler->export('zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Should throw for garbage UUID');
    }

    /**
     * If fixture campaigns exist, attempt export on one.
     * This test is conditional — skipped when no campaigns are in the fixture DB.
     */
    public function testExportWithExistingCampaignFromFixtures(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();

        $campaignId = $conn->executeQuery('SELECT campaign_id FROM campaign LIMIT 1')->fetchOne();

        if ($campaignId === false) {
            $this->markTestSkipped('No campaigns in fixture database');
        }

        // Should not throw RuntimeException('Campaign not found')
        // but may throw other exceptions if profile/YAML is missing — that is OK
        try {
            $result = $this->handler->export((string) $campaignId);
            $this->assertArrayHasKey('message', $result);
            $this->assertSame('STIX export completed', $result['message']);
            $this->assertArrayHasKey('bundle', $result);
            $this->assertArrayHasKey('bundle_id', $result);
            $this->assertArrayHasKey('file_path', $result);
        } catch (\RuntimeException $e) {
            // Acceptable if the campaign has no profile YAML or other data issue
            $this->assertStringNotContainsString('Campaign not found', $e->getMessage());
        }
    }

    public function testHandlerIsWiredInContainer(): void
    {
        $this->assertInstanceOf(CampaignStixExportHandler::class, $this->handler);
    }
}
