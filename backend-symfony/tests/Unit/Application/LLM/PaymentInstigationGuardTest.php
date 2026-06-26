<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PaymentInstigationGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 116 — PaymentInstigationGuard blocks persona outbounds that mention
 * payment-infrastructure (SWIFT/BIC/IBAN/bank account/wire transfer/IFSC/
 * beneficiary/etc.) BEFORE the operator has mentioned any of those topics
 * in the inbound history.
 *
 * Once the operator has mentioned ANY payment-infrastructure keyword in
 * ANY inbound (direction=1) message body, the gate opens permanently for
 * that conversation — the persona can then freely follow up. This preserves
 * full IOC-extraction capability after operator-initiated escalation.
 */
final class PaymentInstigationGuardTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PaymentInstigationGuard $guard;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->guard = new PaymentInstigationGuard($this->em, new NullLogger());
    }

    /**
     * Helper: configure the EM mock to return $inboundBodies as the result
     * of the SELECT m.bodyText FROM Message m WHERE ... query.
     *
     * @param list<string> $inboundBodies
     */
    private function withInboundBodies(array $inboundBodies): void
    {
        $rows = array_map(static fn (string $body): array => ['bodyText' => $body], $inboundBodies);

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getArrayResult'])
            ->getMock();
        $query->method('getArrayResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        // QueryBuilder is fluent — every method returns $this.
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    public function test_blocks_outbound_swift_mention_when_conv_has_no_payment_in_inbound(): void
    {
        $this->withInboundBodies([
            'Hello, I am Anshu from Quantum IT. We can develop your mobile app for a great price. When would be a good time to discuss?',
        ]);

        $result = $this->guard->check(
            'Hi Anshu, thanks for the offer. Could you please share your SWIFT/BIC code so I can validate the wire transfer?',
            'conv-uuid-1'
        );

        $this->assertFalse($result['approved'], 'persona is first to mention SWIFT/wire transfer — must block');
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_passes_outbound_swift_mention_when_operator_already_mentioned_wire_transfer(): void
    {
        $this->withInboundBodies([
            'Hello, I am Anshu from Quantum IT. Initial pitch with no banking words.',
            'For the wire transfer, please send to our account. I will share details soon.',
        ]);

        $result = $this->guard->check(
            'Sure Anshu, please share the SWIFT/BIC code and IBAN so I can prepare the transfer.',
            'conv-uuid-2'
        );

        $this->assertTrue($result['approved'], 'operator mentioned "wire transfer" — gate is open');
        $this->assertNull($result['reason']);
    }

    public function test_passes_outbound_iban_mention_when_operator_mentioned_bank_account(): void
    {
        $this->withInboundBodies([
            'Hello, we accept bank account payments. Let me know if you want to proceed.',
        ]);

        $result = $this->guard->check(
            'OK — please send me the IBAN and beneficiary name on the account.',
            'conv-uuid-3'
        );

        $this->assertTrue($result['approved']);
    }

    public function test_is_case_insensitive(): void
    {
        // Outbound mentions SWIFT in upper case; inbound mentions swift lower.
        // Both should be detected by the case-insensitive regex.
        $this->withInboundBodies([
            'Our swift code is available on request.',
        ]);

        $result = $this->guard->check(
            'Please share the SWIFT code now.',
            'conv-uuid-4'
        );

        $this->assertTrue($result['approved'], 'lowercase "swift" in inbound must anchor the conv');
    }

    public function test_passes_when_outbound_does_not_mention_payment_at_all(): void
    {
        // Pure inert outbound — no payment-infrastructure words. Should
        // pass regardless of inbound history (early-exit fast path).
        $this->withInboundBodies([]);

        $result = $this->guard->check(
            'Hi Anshu, thanks for reaching out. Can you tell me more about your team size and past clients?',
            'conv-uuid-5'
        );

        $this->assertTrue($result['approved']);
        $this->assertNull($result['reason']);
    }

    public function test_blocks_when_outbound_mentions_bank_account_but_inbound_is_silent(): void
    {
        $this->withInboundBodies([
            'Hi, we have great experience with web development.',
            'Our team is in Noida, India.',
        ]);

        $result = $this->guard->check(
            'Could you share the bank account where I should send the payment?',
            'conv-uuid-6'
        );

        $this->assertFalse($result['approved']);
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_does_not_match_substring_collisions(): void
    {
        // "swifty" is a substring of swift — \b word boundary must prevent
        // false positive. Same for "ibanez" containing iban.
        $this->withInboundBodies([
            'My favourite musician is Taylor Swifty and I play an Ibanez guitar.',
        ]);

        $result = $this->guard->check(
            'Tell me more about your musical interests — do you compose?',
            'conv-uuid-7'
        );

        // Outbound doesn't mention payment at all, so this is the trivial
        // pass path. But importantly, the inbound "Swifty" and "Ibanez"
        // must NOT trigger anchoring either — that would be a false-positive
        // open gate. We can't directly test "anchoring did not happen" from
        // an inert outbound, so test the contrapositive in the next test.
        $this->assertTrue($result['approved']);
    }

    public function test_does_not_open_gate_on_substring_collisions_in_inbound(): void
    {
        // Inbound has "swifty" but NOT a real "swift" keyword — the persona
        // outbound mentioning SWIFT must still be blocked (gate stays shut).
        $this->withInboundBodies([
            'Taylor Swifty is touring. The Ibanez RG550 is a great guitar.',
        ]);

        $result = $this->guard->check(
            'Could you please share your SWIFT code?',
            'conv-uuid-8'
        );

        $this->assertFalse($result['approved'], '"swifty" must not anchor for "swift" — \b boundary required');
        $this->assertSame('payment_instigation_blocked', $result['reason']);
    }

    public function test_anchors_on_multi_word_phrases(): void
    {
        // "send the money" is a multi-word anchor — verify it matches with
        // configurable whitespace.
        $this->withInboundBodies([
            'You can send the money to me as soon as you are ready.',
        ]);

        $result = $this->guard->check(
            'Got it. Please share your IBAN and the SWIFT code for the bank.',
            'conv-uuid-9'
        );

        $this->assertTrue($result['approved'], '"send the money" in inbound opens the gate for SWIFT/IBAN outbound');
    }
}
