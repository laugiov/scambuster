<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\MessageHandler;
use App\Application\Communication\ReplyCadenceService;
use App\Application\Communication\ReplyCompositionService;
use App\Application\Communication\ReplyContextService;
use App\Application\Communication\ReplyHandler;
use App\Application\LLM\ReplyOrchestrator;
use App\Application\Monitoring\LlmCostHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use App\Application\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The three protective ceilings — replies per conversation per day, model calls
 * per hour, conversations engaged per day — were waived by the same flag that
 * waives the deliberate reply spacing. The automatic flow sets that flag on every
 * inbound, so the ceilings never ran on the path that generates almost all the
 * traffic.
 *
 * Two things are asserted here: that waiving the spacing no longer waives the
 * ceilings, and that a breach refuses a reply only under the 'enforce' mode —
 * the same warning|enforce idiom the LLM budget cap already uses.
 *
 * ReplyCadenceService is doubled rather than driven for real. Two of the three
 * limiters are keyed globally, so exhausting them would couple this test to
 * whatever else ran before it in the suite. Doubling makes the breach the only
 * variable, which is exactly what these assertions are about.
 */
final class RateLimitEnforcementModeTest extends KernelTestCase
{
    private const BREACH = 'max replies per conversation per day';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    /**
     * @param string|null $breach what checkRateLimits() reports; null means "within limits"
     */
    private function makeReplyHandler(string $mode, ?string $breach): ReplyHandler
    {
        $cadence = $this->createMock(ReplyCadenceService::class);
        $cadence->method('isKillSwitchActive')->willReturn(false);
        $cadence->method('checkCadence')->willReturn(true);
        $cadence->method('checkRateLimits')->willReturn($breach);
        $cadence->method('checkSafelist')->willReturn(true);

        return $this->handlerWithCadence($cadence, $mode);
    }

    private function handlerWithCadence(ReplyCadenceService $cadence, string $mode): ReplyHandler
    {
        $container = self::getContainer();

        return new ReplyHandler(
            $this->em,
            $container->get(MessageHandler::class),
            $container->get(ReplyOrchestrator::class),
            $container->get(ReplyContextService::class),
            $cadence,
            $container->get(ReplyCompositionService::class),
            $container->get(LoggerInterface::class),
            $container->get(AuditLogger::class),
            $container->get(LlmCostHandler::class),
            'warning',
            null,
            null,
            $mode,
        );
    }

    /**
     * @return array{conv_id: string, msg_id: string}
     */
    private function createConversationWithInbound(): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findBy([], ['scamTypeId' => 'ASC'], 1)[0] ?? null;
        $account = $this->em->getRepository(MailAccount::class)->findBy([], ['accountId' => 'ASC'], 1)[0] ?? null;
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        self::assertNotNull($channel);
        self::assertNotNull($scamType);
        self::assertNotNull($account);
        self::assertNotNull($direction);

        $conversationHandler = self::getContainer()->get(ConversationHandler::class);
        $conv = $conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-ratelimit-' . bin2hex(random_bytes(4)),
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'fr',
            'Rate limit mode test',
            'Please send your bank details immediately!',
            null,
            ['from' => 'scammer@evil.test', 'to' => 'victim@example.test'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null,
        );
        $this->em->persist($message);
        $this->em->flush();

        return ['conv_id' => $conv->getConvId(), 'msg_id' => $msgId];
    }

    /**
     * The defect itself: the automatic flow waives the spacing, and that used to
     * skip the ceiling check entirely. The ceilings must now be consulted on
     * exactly that path.
     */
    public function test_ceilings_are_consulted_even_when_the_spacing_waiver_is_set(): void
    {
        $data = $this->createConversationWithInbound();

        $cadence = $this->createMock(ReplyCadenceService::class);
        $cadence->method('isKillSwitchActive')->willReturn(false);
        $cadence->method('checkCadence')->willReturn(true);
        $cadence->method('checkSafelist')->willReturn(true);
        $cadence->expects(self::once())
            ->method('checkRateLimits')
            ->with($data['conv_id'])
            ->willReturn(null);

        $handler = $this->handlerWithCadence($cadence, 'warning');

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], true, 'auto_draft_on_inbound');
        } catch (\Throwable) {
            // Downstream generation may fail; the expectation above is the assertion.
        }
    }

    /**
     * The spacing waiver must keep working — a honeypot that makes a target wait
     * six hours for an answer loses the engagement and reads as automation.
     */
    public function test_spacing_waiver_still_applies_on_the_automatic_flow(): void
    {
        $data = $this->createConversationWithInbound();

        $cadence = $this->createMock(ReplyCadenceService::class);
        $cadence->method('isKillSwitchActive')->willReturn(false);
        $cadence->method('checkSafelist')->willReturn(true);
        $cadence->method('checkRateLimits')->willReturn(null);
        // Spacing is not met, yet the waiver must carry the reply past it.
        $cadence->method('checkCadence')->willReturn(false);

        $handler = $this->handlerWithCadence($cadence, 'warning');

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], true, 'auto_draft_on_inbound');
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('Cadence limit not met', $e->getMessage());
        }
    }

    public function test_breach_under_warning_mode_records_but_still_replies(): void
    {
        $data = $this->createConversationWithInbound();
        $handler = $this->makeReplyHandler('warning', self::BREACH);

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], true, 'auto_draft_on_inbound');
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            self::assertStringNotContainsString(
                'Rate limit exceeded',
                $e->getMessage(),
                'Observation mode must never refuse a reply'
            );
        }
    }

    public function test_breach_under_enforce_mode_refuses_and_names_the_ceiling(): void
    {
        $data = $this->createConversationWithInbound();
        $handler = $this->makeReplyHandler('enforce', self::BREACH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded: ' . self::BREACH);

        $handler->generateReply($data['conv_id'], $data['msg_id'], true, 'auto_draft_on_inbound');
    }

    /**
     * An unrecognised mode must resolve to observation. Enforcement is the
     * behaviour that refuses traffic, so it must never be reached by accident.
     */
    public function test_unrecognised_mode_resolves_to_observation(): void
    {
        $data = $this->createConversationWithInbound();
        $handler = $this->makeReplyHandler('nonsense', self::BREACH);

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], true, 'auto_draft_on_inbound');
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('Rate limit exceeded', $e->getMessage());
        }
    }

    /**
     * The operator escape hatch: an explicit ceiling waiver must still bypass the
     * ceilings even under enforcement, preserving today's full-override capability.
     */
    public function test_explicit_ceiling_waiver_bypasses_enforcement(): void
    {
        $data = $this->createConversationWithInbound();

        $cadence = $this->createMock(ReplyCadenceService::class);
        $cadence->method('isKillSwitchActive')->willReturn(false);
        $cadence->method('checkCadence')->willReturn(true);
        $cadence->method('checkSafelist')->willReturn(true);
        $cadence->expects(self::never())->method('checkRateLimits');

        $handler = $this->handlerWithCadence($cadence, 'enforce');

        try {
            $handler->generateReply($data['conv_id'], $data['msg_id'], true, 'operator-override', true);
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            self::assertStringNotContainsString('Rate limit exceeded', $e->getMessage());
        }
    }

    /**
     * Deploying this feature must refuse nothing: the shipped configuration has to
     * resolve to observation, not enforcement.
     */
    public function test_shipped_default_configuration_is_observation(): void
    {
        self::assertSame(
            'warning',
            self::getContainer()->getParameter('scambuster.rate_limit_enforcement_mode'),
            'The default must never be enforcement'
        );
    }
}
