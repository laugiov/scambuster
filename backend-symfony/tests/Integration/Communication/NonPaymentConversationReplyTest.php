<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\ReplyHandler;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\ReplyOrchestrator;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end regression for the fallback storm on non-payment
 * conversations: a scammer running a B2B pitch (app development, SEO)
 * that has never mentioned money must receive a real reply, not the
 * fallback placeholder.
 *
 * Production failure shape: the follow_up stage objective told the
 * persona to ask "where exactly the money will go", the payment
 * instigation guard (correctly) blocked the resulting draft, the same
 * objective was rebuilt on every retry, and after 3 deterministic
 * strikes the fallback shipped. This test drives the real pipeline
 * (ReplyHandler context -> ReplyOrchestrator -> RetryCoordinator ->
 * PromptBuilder + guards) with only the LLM client substituted.
 */
class NonPaymentConversationReplyTest extends KernelTestCase
{
    private ReplyHandler $replyHandler;
    private ReplyOrchestrator $replyOrchestrator;
    private ConversationHandler $conversationHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    /** @var \ArrayObject<string, mixed> */
    private \ArrayObject $spy;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Shared mutable holder — the LLM stub records into it and the
        // test reads it back (by-reference scalars into an anon class do
        // not survive reliably).
        $this->spy = new \ArrayObject(['generator_prompts' => [], 'anchoring_calls' => 0]);

        // Non-monetary but IOC-eliciting: targets url + email channels so
        // the IOC-likelihood gate passes without any payment wording.
        $generatedReply = 'Thanks for the details about your development team and the projects you have delivered so far. '
            . 'Could you send me a link to your website showing those examples, and let me know which '
            . 'email address I should use to request the company references you mentioned earlier?';

        $llmStub = new class($this->spy, $generatedReply) implements LLMClientInterface {
            /** @param \ArrayObject<string, mixed> $spy */
            public function __construct(
                private \ArrayObject $spy,
                private string $generatedReply,
            ) {
            }

            public function chat(array $messages, array $options = []): string
            {
                $lastContent = (string) (end($messages)['content'] ?? '');

                if (str_contains($lastContent, 'Your verdict (one token only)')) {
                    if (str_contains($lastContent, 'OUTBOUND DRAFT')) {
                        return 'NO_OUTBOUND_DOES_NOT_MENTION_PAYMENT';
                    }
                    $this->spy['anchoring_calls'] = ((int) $this->spy['anchoring_calls']) + 1;

                    return 'OPERATOR_NOT_MENTIONED';
                }

                if (str_contains($lastContent, 'Score each dimension') || str_contains($lastContent, 'Text to validate')) {
                    return '{"naturalness":4,"persona_fit":4,"ti_value":4,"security_pass":true,"feedback":"good","fix_suggestion":""}';
                }

                // ConversationAnalyzer (anti-repetition) and the leak
                // detector both make LLM calls; neither should be treated
                // as a reply generation. Return their expected shapes.
                if (str_contains($lastContent, 'TEXT TO AUDIT')) {
                    return '{"leak":false,"reason":"","matched_terms":[]}';
                }

                if (str_contains($lastContent, 'expert analyst of anti-scam')
                    || str_contains($lastContent, 'strategic_suggestions')) {
                    return '{"repetitions":[],"tone_recommended":"neutral","strategic_suggestions":[]}';
                }

                // Reply generation: capture the prompt so the objective
                // contract can be asserted.
                /** @var list<string> $prompts */
                $prompts = $this->spy['generator_prompts'];
                $prompts[] = $lastContent;
                $this->spy['generator_prompts'] = $prompts;

                return $this->generatedReply;
            }
        };

        $container->set(LLMClientInterface::class, $llmStub);

        $this->replyHandler = $container->get(ReplyHandler::class);
        $this->replyOrchestrator = $container->get(ReplyOrchestrator::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    public function testNonPaymentPitchAtFollowUpGetsRealReplyWithoutMoneyObjective(): void
    {
        $convId = $this->createNonPaymentConversationAtFollowUpStage();

        $context = $this->replyHandler->getConversationContext($convId);
        $this->assertNotNull($context);
        $context['detected_language'] = 'en';

        /** @var string $personaCode */
        $personaCode = $context['persona'];
        $result = $this->replyOrchestrator->generate($context, $personaCode);

        $this->assertFalse((bool) $result['fallback_used'], 'A non-payment conversation must not ship the fallback placeholder');
        $this->assertTrue((bool) $result['approved']);
        $this->assertSame(1, $result['attempts'], 'The reply must pass on the first attempt — no guard/objective contradiction left');
        // Per-generation, not per-attempt: the real PaymentInstigationGuard
        // (only the LLM client is stubbed) judges anchoring exactly once
        // and reuses it across attempts. Under the old per-attempt shape
        // this would be 3.
        $this->assertSame(1, (int) $this->spy['anchoring_calls'], 'Anchoring must be judged exactly once per generation, never per attempt');

        /** @var list<string> $capturedPrompts */
        $capturedPrompts = $this->spy['generator_prompts'];
        $this->assertNotEmpty($capturedPrompts);
        $generatorPrompt = $capturedPrompts[0];
        $this->assertStringNotContainsString('where exactly the money will go', $generatorPrompt, 'Unanchored follow_up objective must not instruct a money ask');
        $this->assertStringContainsString('Do NOT bring up money', $generatorPrompt);
    }

    private function createNonPaymentConversationAtFollowUpStage(): string
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $in = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $out = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($in);
        $this->assertNotNull($out);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            65,
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable(),
            'non-payment-followup-' . bin2hex(random_bytes(4))
        );

        // 4 messages (3-5 = follow_up stage), all money-free: the classic
        // app-development pitch observed in production.
        $bodies = [
            ['dir' => $in, 'text' => 'Hi, we are a mobile app development agency and I noticed your business online. We have delivered many projects for companies like yours. Would you be interested in discussing a collaboration?'],
            ['dir' => $out, 'text' => 'Hello, thank you for reaching out. Could you tell me more about the kind of projects your team has delivered?'],
            ['dir' => $in, 'text' => 'Thank you for your response. I have several project references and a preliminary roadmap I would like to showcase. During a short Google Meet I can walk you through these examples in detail.'],
            ['dir' => $out, 'text' => 'That sounds interesting. Before we schedule anything, could you share some examples of specific projects you have completed?'],
        ];

        $ts = new \DateTimeImmutable('-2 days');

        foreach ($bodies as $i => $spec) {
            $msg = new Message(
                uuid_create(UUID_TYPE_RANDOM),
                $conv,
                $channel,
                $spec['dir'],
                'en',
                'Re: Mobile app development collaboration',
                $spec['text'],
                '<p>' . $spec['text'] . '</p>',
                [
                    'from' => $spec['dir'] === $in ? 'agency@pitch.test' : 'owner@example.test',
                    'to' => $spec['dir'] === $in ? 'owner@example.test' : 'agency@pitch.test',
                    'message_id' => '<np-' . $i . '-' . bin2hex(random_bytes(6)) . '@pitch.test>',
                    'subject' => 'Re: Mobile app development collaboration',
                ],
                bin2hex(random_bytes(32)),
                null,
                null,
                $ts->modify("+{$i} hours"),
                $ts->modify("+{$i} hours"),
                null
            );
            $this->em->persist($msg);
        }
        $this->em->flush();

        return $conv->getConvId();
    }
}
