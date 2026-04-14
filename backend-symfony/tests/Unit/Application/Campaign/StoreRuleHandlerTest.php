<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\StoreRuleHandler;
use App\Domain\CampaignRadar\Campaign;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class StoreRuleHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private StoreRuleHandler $handler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->handler = new StoreRuleHandler($this->em, new NullLogger());
    }

    public function testThrowsWhenCampaignNotFound(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $this->handler->handle(
            Uuid::v4(),
            'RULE test { match("example") }',
            ['sql' => 'SELECT 1', 'params' => []]
        );
    }

    public function testStoresRuleSuccessfully(): void
    {
        $campaignId = Uuid::v4();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getCampaignId')->willReturn($campaignId);

        $this->em->method('find')
            ->with(Campaign::class, $campaignId)
            ->willReturn($campaign);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $this->handler->handle(
            $campaignId,
            'RULE test_rule { match("example") }',
            ['sql' => 'SELECT 1', 'params' => ['foo' => 'bar']]
        );

        $this->assertArrayHasKey('rule_id', $result);
        $this->assertSame($campaignId->toRfc4122(), $result['campaign_id']);
        $this->assertSame('shadow', $result['status']);
        $this->assertTrue($result['enabled']);
    }
}
