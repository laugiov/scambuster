<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\AuditHmacChainer;
use PHPUnit\Framework\TestCase;

/**
 * AuditHmacChainer unit tests.
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

    public function test_invalid_key_disables_chainer(): void
    {
        $chainer = new AuditHmacChainer('too-short');
        $this->assertFalse($chainer->isEnabled());
        $this->assertSame('', $chainer->compute('', ['event' => 'test']));
    }

    public function test_empty_key_disables_chainer(): void
    {
        $chainer = new AuditHmacChainer('');
        $this->assertFalse($chainer->isEnabled());
    }

    public function test_invalid_key_disables_chainer_in_non_prod_without_throwing(): void
    {
        $chainer = new AuditHmacChainer('too-short', 'dev');
        $this->assertFalse($chainer->isEnabled());
    }

    public function test_invalid_key_throws_in_prod(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUDIT_HMAC_KEY is required in production');

        new AuditHmacChainer('too-short', 'prod');
    }

    public function test_valid_key_is_enabled_in_prod(): void
    {
        $chainer = new AuditHmacChainer(bin2hex(random_bytes(32)), 'prod');
        $this->assertTrue($chainer->isEnabled());
    }
}
