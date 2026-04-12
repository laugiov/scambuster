<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\AuditHmacChainer;
use PHPUnit\Framework\TestCase;

/**
 * Spec 065f — Phase 1 — AuditHmacChainer unit tests.
 */
final class AuditHmacChainerTest extends TestCase
{
    private function makeChainer(): AuditHmacChainer
    {
        return new AuditHmacChainer(bin2hex(random_bytes(32)));
    }

    public function test_compute_is_deterministic_for_same_input(): void
    {
        $key = bin2hex(random_bytes(32));
        $chainer = new AuditHmacChainer($key);
        $row = ['event_type' => 'AUTH_SUCCESS', 'actor_id' => 'test@example.com'];

        $hmac1 = $chainer->compute('', $row);
        $hmac2 = $chainer->compute('', $row);

        $this->assertSame($hmac1, $hmac2);
    }

    public function test_compute_differs_for_different_prev_hmac(): void
    {
        $chainer = $this->makeChainer();
        $row = ['event_type' => 'AUTH_SUCCESS', 'actor_id' => 'test@example.com'];

        $hmac1 = $chainer->compute('', $row);
        $hmac2 = $chainer->compute('different_prev', $row);

        $this->assertNotSame($hmac1, $hmac2);
    }

    public function test_compute_differs_for_different_row(): void
    {
        $chainer = $this->makeChainer();

        $hmac1 = $chainer->compute('', ['event_type' => 'AUTH_SUCCESS']);
        $hmac2 = $chainer->compute('', ['event_type' => 'AUTH_FAILURE']);

        $this->assertNotSame($hmac1, $hmac2);
    }

    public function test_compute_handles_empty_prev_hmac_first_row(): void
    {
        $chainer = $this->makeChainer();
        $row = ['event_type' => 'AUTH_SUCCESS'];

        // Must not throw on empty prev_hmac (first row in chain)
        $hmac = $chainer->compute('', $row);

        $this->assertSame(32, strlen($hmac)); // SHA256 = 32 bytes raw
    }

    public function test_compute_handles_unicode_strings_in_row(): void
    {
        $chainer = $this->makeChainer();
        $row = ['actor_id' => 'utilisateur@réseau.fr', 'details' => 'données sensibles'];

        $hmac = $chainer->compute('', $row);
        $this->assertSame(32, strlen($hmac));
    }

    public function test_constructor_throws_on_invalid_key_length(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUDIT_HMAC_KEY');
        new AuditHmacChainer('too-short');
    }
}
