<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\DSLTranspiler;
use App\Application\Campaign\StoreRuleHandler;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class StoreRuleHandlerTest extends TestCase
{
    private const VALID_DSL = 'RULE r { WHERE subject.simhash≈"urgent payment" ±15% ACTION tag="x" }';

    private EntityManagerInterface&MockObject $em;
    private StoreRuleHandler $handler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        // Real transpiler: the whole point of the fix is that the SQL comes from
        // it, not from the caller.
        $this->handler = new StoreRuleHandler($this->em, new NullLogger(), new DSLTranspiler(new NullLogger()));
    }

    public function testThrowsWhenCampaignNotFound(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $this->handler->handle(Uuid::v4(), self::VALID_DSL);
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

        $result = $this->handler->handle($campaignId, self::VALID_DSL);

        $this->assertArrayHasKey('rule_id', $result);
        $this->assertSame($campaignId->toRfc4122(), $result['campaign_id']);
        $this->assertSame('shadow', $result['status']);
        $this->assertTrue($result['enabled']);
    }

    /**
     * The stored SQL is the SERVER transpilation of the DSL — a read-only SELECT —
     * regardless of anything a caller might wish to inject.
     */
    public function testStoresServerTranspiledSqlNotArbitrarySql(): void
    {
        $campaignId = Uuid::v4();
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getCampaignId')->willReturn($campaignId);
        $this->em->method('find')->willReturn($campaign);

        $captured = null;
        $this->em->method('persist')->willReturnCallback(
            static function (object $rule) use (&$captured): void {
                if ($rule instanceof CampaignRule) {
                    $captured = $rule;
                }
            }
        );

        $this->handler->handle($campaignId, self::VALID_DSL);

        self::assertInstanceOf(CampaignRule::class, $captured);
        $compiled = $captured->getCompiledData();
        self::assertIsArray($compiled);
        self::assertStringContainsStringIgnoringCase('SELECT', (string) $compiled['sql']);
        self::assertStringContainsStringIgnoringCase('FROM message', (string) $compiled['sql']);
        self::assertStringNotContainsStringIgnoringCase('DELETE', (string) $compiled['sql']);
        // Params are the server's bound params, keyed p0..pN.
        self::assertArrayHasKey('p0', $compiled['params']);
    }

    public function testRejectsMalformedDslWithInvalidArgument(): void
    {
        $campaignId = Uuid::v4();
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getCampaignId')->willReturn($campaignId);
        $this->em->method('find')->willReturn($campaign);

        $this->expectException(\InvalidArgumentException::class);

        $this->handler->handle($campaignId, 'this is not a valid rule DSL');
    }
}
