<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PaymentInstigationGuard;
use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * LLM-based judge (replaces the v0 regex implementation
 * committed in 0397fb60). The judge must:
 *
 * - Block when persona is first to mention payment-infrastructure topics.
 * - Pass when operator has already mentioned payment topics — in ANY language.
 * - Pass when outbound doesn't mention payment topics at all.
 * - On LLM errors / unparseable responses, fail in a RISK-SCOPED direction:
 *   fail CLOSED when the outbound draft itself contains payment-infrastructure
 *   tokens (deterministic backstop), fail OPEN when it does not (keep the
 *   payment-free common case resilient).
 *
 * These tests mock the LLM client to return controlled verdicts; we don't
 * test the LLM's actual classification quality here (that's e2e territory).
 * We test our parsing, our prompt assembly, our fail-open semantics, and
 * our EM-based inbound history fetching.
 */
final class PaymentInstigationGuardTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LLMClientInterface&MockObject $llm;
    private PaymentInstigationGuard $guard;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->llm = $this->createMock(LLMClientInterface::class);
        $this->guard = new PaymentInstigationGuard($this->em, $this->llm, new NullLogger());
    }

    /**
     * Configure the EM mock so the inbound-bodies query returns $bodies.
     *
     * @param list<string> $bodies
     */
    private function withInboundBodies(array $bodies): void
    {
        $rows = array_map(static fn (string $b): array => ['bodyText' => $b], $bodies);

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getArrayResult'])
            ->getMock();
        $query->method('getArrayResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    private function expectLlmVerdict(string $verdict): void
    {
        $this->llm->method('chat')->willReturn($verdict);
    }

    // ─── Block path ─────────────────────────────────────────────────────

    public function test_blocks_when_llm_returns_yes_persona_instigates(): void
    {
        $this->withInboundBodies(['Hello, we offer mobile app development services.']);
        $this->expectLlmVerdict('YES_PERSONA_INSTIGATES');

        $result = $this->guard->check(
            'Hi, could you please share your SWIFT/BIC code?',
            'conv-uuid-1'
        );

        $this->assertFalse($result['approved']);
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    // ─── Pass paths ─────────────────────────────────────────────────────

    public function test_passes_when_llm_returns_no_operator_already_mentioned(): void
    {
        $this->withInboundBodies(['Please send the wire transfer to our bank.']);
        $this->expectLlmVerdict('NO_OPERATOR_ALREADY_MENTIONED');

        $result = $this->guard->check(
            'Sure — what is your IBAN and beneficiary name?',
            'conv-uuid-2'
        );

        $this->assertTrue($result['approved']);
        $this->assertNull($result['reason']);
    }

    public function test_passes_when_llm_returns_no_outbound_does_not_mention_payment(): void
    {
        $this->withInboundBodies(['Tell me about your project.']);
        $this->expectLlmVerdict('NO_OUTBOUND_DOES_NOT_MENTION_PAYMENT');

        $result = $this->guard->check(
            'I am looking for a mobile app for my logistics company.',
            'conv-uuid-3'
        );

        $this->assertTrue($result['approved']);
    }

    public function test_short_circuits_to_pass_when_no_inbound_messages_at_all(): void
    {
        // Defensive: 0 inbound messages → trivial pass, NO LLM call.
        // This is the "persona replying to nothing" edge case.
        $this->withInboundBodies([]);
        $this->llm->expects($this->never())->method('chat');

        $result = $this->guard->check('Could you share your SWIFT?', 'conv-uuid-4');

        $this->assertTrue($result['approved']);
    }

    // ─── Verdict parsing ────────────────────────────────────────────────

    public function test_parses_verdict_with_surrounding_whitespace(): void
    {
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict("  YES_PERSONA_INSTIGATES  \n");

        $result = $this->guard->check('Need your SWIFT.', 'conv-uuid-5');

        $this->assertFalse($result['approved']);
    }

    public function test_parses_verdict_when_llm_wraps_in_quotes(): void
    {
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict('"NO_OPERATOR_ALREADY_MENTIONED"');

        $result = $this->guard->check('What is your SWIFT?', 'conv-uuid-6');

        $this->assertTrue($result['approved']);
    }

    public function test_parses_verdict_as_first_word_of_a_sentence(): void
    {
        // The model is told "no explanation" but sometimes adds one.
        // Accept the verdict if it's at least the first recognizable token.
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict('YES_PERSONA_INSTIGATES — the outbound mentions IBAN');

        $result = $this->guard->check('Send me your IBAN.', 'conv-uuid-7');

        $this->assertFalse($result['approved']);
    }

    public function test_is_case_insensitive_on_verdict_parsing(): void
    {
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict('yes_persona_instigates');

        $result = $this->guard->check('Need your SWIFT.', 'conv-uuid-8');

        $this->assertFalse($result['approved']);
    }

    // ─── Fail-open paths ────────────────────────────────────────────────

    public function test_fails_closed_when_llm_throws_and_outbound_has_payment_tokens(): void
    {
        // The security-critical case: the judge is unavailable AND the draft
        // asks for payment infrastructure. Letting it through (old fail-open)
        // is exactly what a scammer inducing a timeout would exploit.
        $this->withInboundBodies(['Initial pitch.']);
        $this->llm->method('chat')->willThrowException(new \RuntimeException('OpenAI 503'));

        $result = $this->guard->check('Need your SWIFT.', 'conv-uuid-9');

        $this->assertFalse($result['approved'], 'fail closed when risky content present + judge down');
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_fails_closed_on_unparseable_verdict_when_outbound_has_payment_tokens(): void
    {
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict('I think maybe the persona is asking, but I cannot tell.');

        $result = $this->guard->check('Need your SWIFT.', 'conv-uuid-10');

        $this->assertFalse($result['approved']);
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_fails_closed_on_empty_response_when_outbound_has_payment_tokens(): void
    {
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict('');

        $result = $this->guard->check('Need your SWIFT.', 'conv-uuid-11');

        $this->assertFalse($result['approved']);
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_fails_open_when_llm_throws_and_outbound_has_no_payment_tokens(): void
    {
        // Payment-free draft + judge down → nothing risky to instigate, so keep
        // the pipeline resilient (fail open). This is the common case.
        $this->withInboundBodies(['Initial pitch.']);
        $this->llm->method('chat')->willThrowException(new \RuntimeException('OpenAI 503'));

        $result = $this->guard->check(
            'What is your timeline and who will I be working with on this?',
            'conv-uuid-9b'
        );

        $this->assertTrue($result['approved'], 'fail open when no risky content present');
        $this->assertNull($result['reason']);
    }

    public function test_fails_open_on_unparseable_verdict_when_outbound_has_no_payment_tokens(): void
    {
        $this->withInboundBodies(['Initial pitch.']);
        $this->expectLlmVerdict('some rambling non-token answer');

        $result = $this->guard->check('Could you tell me more about the process?', 'conv-uuid-10b');

        $this->assertTrue($result['approved']);
        $this->assertNull($result['reason']);
    }

    public function test_fails_closed_when_outbound_uses_a_multilingual_payment_token(): void
    {
        // Deterministic backstop must catch obvious non-English tokens too.
        $this->withInboundBodies(['Initial pitch.']);
        $this->llm->method('chat')->willThrowException(new \RuntimeException('LLM down'));

        $result = $this->guard->check('Pouvez-vous me donner votre IBAN pour le virement ?', 'conv-uuid-fr-fail');

        $this->assertFalse($result['approved']);
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_fails_closed_on_accented_german_payment_token(): void
    {
        // Locks the /u flag: the accented "Überweisung" must match, not only
        // the accent-free form.
        $this->withInboundBodies(['Initial pitch.']);
        $this->llm->method('chat')->willThrowException(new \RuntimeException('LLM down'));

        $result = $this->guard->check('Bitte senden Sie mir die Details für die Überweisung.', 'conv-uuid-de-fail');

        $this->assertFalse($result['approved']);
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    // ─── Multilingual behaviour (verdict-mock-based; real LLM judges in prod) ─

    public function test_passes_when_operator_mentions_payment_in_french(): void
    {
        // The judge LLM understands FR — when operator said "virement",
        // the verdict is NO_OPERATOR_ALREADY_MENTIONED.
        $this->withInboundBodies([
            'Bonjour, pour le virement bancaire merci de m\'envoyer un mail.',
        ]);
        $this->expectLlmVerdict('NO_OPERATOR_ALREADY_MENTIONED');

        $result = $this->guard->check(
            'Could you confirm the SWIFT and IBAN for the transfer?',
            'conv-uuid-fr'
        );

        $this->assertTrue($result['approved']);
    }

    public function test_passes_when_operator_mentions_payment_in_hindi_neft(): void
    {
        // Hindi/Indian English: "NEFT" and "RTGS" are Indian banking
        // protocols. A real operator from India will mention these instead
        // of SWIFT in domestic context.
        $this->withInboundBodies([
            'Sir please send NEFT to our account.',
        ]);
        $this->expectLlmVerdict('NO_OPERATOR_ALREADY_MENTIONED');

        $result = $this->guard->check(
            'OK what is your IFSC and account number?',
            'conv-uuid-hi'
        );

        $this->assertTrue($result['approved']);
    }

    // ─── Anchoring state (inbound-only judge, shared with PromptBuilder) ─
    //
    // isPaymentTopicAnchored() answers only "did the operator raise
    // payment-infrastructure topics?" — computable before any draft
    // exists, evaluated once per generation and consumed by the prompt's
    // stage objectives. Failure semantics are the OPPOSITE of check():
    // fail CLOSED (false) so an LLM outage yields conservative,
    // money-free prompt guidance instead of instigation-prone objectives.

    public function test_anchored_true_when_llm_says_operator_mentioned(): void
    {
        $this->withInboundBodies(['Please wire the funds to our IBAN FR76...']);
        $this->expectLlmVerdict('OPERATOR_MENTIONED');

        $this->assertTrue($this->guard->isPaymentTopicAnchored('conv-anchored'));
    }

    public function test_anchored_false_when_llm_says_operator_not_mentioned(): void
    {
        $this->withInboundBodies(['We build great mobile apps, want a call?']);
        $this->expectLlmVerdict('OPERATOR_NOT_MENTIONED');

        $this->assertFalse($this->guard->isPaymentTopicAnchored('conv-not-anchored'));
    }

    public function test_anchored_false_without_llm_call_on_empty_inbound_history(): void
    {
        $this->withInboundBodies([]);
        $this->llm->expects($this->never())->method('chat');

        $this->assertFalse($this->guard->isPaymentTopicAnchored('conv-empty'));
    }

    public function test_anchored_fails_closed_on_llm_error(): void
    {
        $this->withInboundBodies(['Some inbound message.']);
        $this->llm->method('chat')->willThrowException(new \RuntimeException('LLM down'));

        $this->assertFalse($this->guard->isPaymentTopicAnchored('conv-llm-down'));
    }

    public function test_anchored_fails_closed_on_unparseable_verdict(): void
    {
        $this->withInboundBodies(['Some inbound message.']);
        $this->expectLlmVerdict('I think the operator probably mentioned it?');

        $this->assertFalse($this->guard->isPaymentTopicAnchored('conv-unparseable'));
    }

    public function test_anchored_verdict_tolerates_quotes_and_punctuation(): void
    {
        $this->withInboundBodies(['Please remit payment via bank transfer.']);
        $this->expectLlmVerdict('"OPERATOR_MENTIONED".');

        $this->assertTrue($this->guard->isPaymentTopicAnchored('conv-quoted'));
    }
}
