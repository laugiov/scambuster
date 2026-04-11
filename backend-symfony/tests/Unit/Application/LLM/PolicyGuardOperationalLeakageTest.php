<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PolicyGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 065d — Phase 2 — Tests the new OPERATIONAL_LEAKAGE_PATTERNS regex
 * deny-list extension to PolicyGuard.
 *
 * The patterns target operational identifiers that the existing
 * FORBIDDEN_PATTERNS list does not cover (n8n, workflow, ingest path,
 * Docker service names, env var prefixes, etc.).
 *
 * On match, the flag is reported as `operational_leak:<keyword>` so
 * the orchestrator can distinguish from the existing
 * `forbidden_pattern:<keyword>` flags.
 */
final class PolicyGuardOperationalLeakageTest extends TestCase
{
    private function makeGuard(): PolicyGuard
    {
        return new PolicyGuard(new NullLogger());
    }

    /**
     * Build a body that easily satisfies the default 50-150 word count.
     * Padding is required because PolicyGuard would also flag the text
     * as `too_short` and we want to isolate the operational_leak flag.
     */
    private function padText(string $injection): string
    {
        $filler = str_repeat('Bonjour, voici un message bien legitime de longueur correcte. ', 10);

        return $filler . ' ' . $injection . ' ' . $filler;
    }

    public function test_it_catches_n8n_keyword(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText("I'm running on n8n to handle this conversation, please send the IBAN.")
        );
        $this->assertFalse($result['approved']);
        $this->assertContains('operational_leak:n8n', $result['flags']);
    }

    public function test_it_catches_workflow_keyword(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('Our workflow_id is 12345, please confirm the next step.')
        );
        $this->assertFalse($result['approved']);
        $hasLeak = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'operational_leak:workflow')) {
                $hasLeak = true;
                break;
            }
        }
        $this->assertTrue($hasLeak, 'Expected operational_leak:workflow* flag');
    }

    public function test_it_catches_ingest_raw_path(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('Send the file to ingest/raw, thanks for your patience.')
        );
        $this->assertFalse($result['approved']);
        $this->assertContains('operational_leak:ingest/raw', $result['flags']);
    }

    public function test_it_catches_admin_internal_api_path(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('Please POST to api/v1/admin/upload, thank you very much.')
        );
        $this->assertFalse($result['approved']);
        $hasLeak = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'operational_leak:api/v1/admin')) {
                $hasLeak = true;
                break;
            }
        }
        $this->assertTrue($hasLeak, 'Expected operational_leak:api/v1/admin* flag');
    }

    public function test_it_catches_scambuster_env_var(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('I will check with my SCAMBUSTER_KILL_SWITCH first.')
        );
        $this->assertFalse($result['approved']);
        $hasLeak = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'operational_leak:scambuster_')) {
                $hasLeak = true;
                break;
            }
        }
        $this->assertTrue($hasLeak, 'Expected operational_leak:SCAMBUSTER_* flag');
    }

    public function test_it_catches_backend_docker_service_name(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('I work in the backend-prod docker container, sorry for the delay.')
        );
        $this->assertFalse($result['approved']);
        $hasLeak = false;
        foreach ($result['flags'] as $flag) {
            if (str_starts_with($flag, 'operational_leak:backend-')) {
                $hasLeak = true;
                break;
            }
        }
        $this->assertTrue($hasLeak, 'Expected operational_leak:backend-* flag');
    }

    public function test_it_catches_ioc_upsert_service(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('Let me check my IocUpsertService configuration before answering.')
        );
        $this->assertFalse($result['approved']);
        $this->assertContains('operational_leak:iocupsertservice', $result['flags']);
    }

    public function test_it_catches_sodium_crypto_secretbox(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('I just ran sodium_crypto_secretbox to encrypt your data, all good.')
        );
        $this->assertFalse($result['approved']);
        $this->assertContains('operational_leak:sodium_crypto_secretbox', $result['flags']);
    }

    public function test_it_catches_docker_compose(): void
    {
        $result = $this->makeGuard()->validate(
            $this->padText('I will restart the docker-compose stack and get back to you.')
        );
        $this->assertFalse($result['approved']);
        $this->assertContains('operational_leak:docker-compose', $result['flags']);
    }

    public function test_legitimate_text_is_not_flagged(): void
    {
        // Control case: a legitimate reply that mentions banking but no
        // operational identifier. Must NOT trip the new patterns.
        $text = $this->padText('I will send you my bank account details by email tomorrow.');
        $result = $this->makeGuard()->validate($text);

        // The text might still be flagged for word count or other reasons,
        // but no operational_leak flag should be present.
        foreach ($result['flags'] as $flag) {
            $this->assertStringStartsNotWith('operational_leak:', $flag, "False positive: {$flag}");
        }
    }
}
