<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication\Exception;

use App\Application\Communication\Exception\MarkAsSentConflictException;
use PHPUnit\Framework\TestCase;

/**
 * Spec 082 T02 — typed exception so MarkReplySentController can map the
 * "conflict on /sent — different provider_msg_id than what we already
 * recorded" case to HTTP 400 without string-matching on the message.
 */
final class MarkAsSentConflictExceptionTest extends TestCase
{
    public function test_extends_runtime_exception(): void
    {
        $e = new MarkAsSentConflictException('msg-1', 'X', 'Y');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function test_constructor_stores_all_three_ids(): void
    {
        $e = new MarkAsSentConflictException(
            msgId: '11111111-1111-1111-1111-111111111111',
            expectedProviderMsgId: 'existing-id-X',
            actualProviderMsgId: 'requested-id-Y',
        );

        self::assertSame('11111111-1111-1111-1111-111111111111', $e->getMsgId());
        self::assertSame('existing-id-X', $e->getExpectedProviderMsgId());
        self::assertSame('requested-id-Y', $e->getActualProviderMsgId());
    }

    public function test_default_message_contains_all_three_ids(): void
    {
        $e = new MarkAsSentConflictException(
            msgId: 'msg-42',
            expectedProviderMsgId: 'stored-abc',
            actualProviderMsgId: 'requested-xyz',
        );

        $msg = $e->getMessage();
        self::assertStringContainsString('msg-42', $msg);
        self::assertStringContainsString('stored-abc', $msg);
        self::assertStringContainsString('requested-xyz', $msg);
    }
}
