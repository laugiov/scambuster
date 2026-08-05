<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\EphemeralPromptOverride;
use App\Application\LLM\Prompt\PromptOverrideSource;
use App\Application\LLM\Prompt\PromptProvider;
use App\Infrastructure\Prompt\CompositePromptOverrideSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end lock for the GUARD candidate-injection seam: PromptProvider wired with the real
 * CompositePromptOverrideSource([ephemeral, db]). Proves an unsaved candidate wins over a saved
 * override, that resolution is unchanged when the ephemeral holder is empty, and — critically —
 * that a candidate still has to satisfy the required-placeholder contract (a broken candidate
 * falls through, it cannot blind the model to data it needs).
 */
final class PromptProviderEphemeralSeamTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scambuster_seam_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $p) {
            @unlink($p);
        }
        @rmdir($this->dir);
    }

    private function db(?string $body): PromptOverrideSource
    {
        return new class($body) implements PromptOverrideSource {
            public function __construct(private ?string $body)
            {
            }

            public function get(string $key): ?string
            {
                return $this->body;
            }
        };
    }

    private function provider(EphemeralPromptOverride $ephemeral, ?string $dbBody): PromptProvider
    {
        $composite = new CompositePromptOverrideSource([$ephemeral, $this->db($dbBody)]);

        return new PromptProvider($this->dir, new NullLogger(), $composite);
    }

    public function testEmptyEphemeralLeavesTheSavedOverrideWinning(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $provider = $this->provider($ephemeral, 'SAVED {{X}}');

        self::assertSame('SAVED v', $provider->resolve('reward_judge', ['{{X}}' => 'v'], 'DEFAULT {{X}}', ['{{X}}']));
    }

    public function testValidCandidateWinsOverTheSavedOverride(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $ephemeral->set('reward_judge', 'CANDIDATE {{X}}');
        $provider = $this->provider($ephemeral, 'SAVED {{X}}');

        self::assertSame('CANDIDATE v', $provider->resolve('reward_judge', ['{{X}}' => 'v'], 'DEFAULT {{X}}', ['{{X}}']));
    }

    public function testInvalidCandidateNeverWinsAndDegradesSafely(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        // A candidate dropping the required {{X}} must NEVER win — the required-placeholder
        // contract holds for it too. The composite collapses ephemeral+DB into one resolution
        // layer, so an invalid candidate degrades to the shipped default (not the saved DB
        // body). This edge is defensive only: the API pre-validates a candidate (422) before it
        // can ever be set here, so a broken candidate never reaches the resolver in practice.
        $ephemeral->set('reward_judge', 'CANDIDATE without token');
        $provider = $this->provider($ephemeral, 'SAVED {{X}}');

        self::assertSame('DEFAULT v', $provider->resolve('reward_judge', ['{{X}}' => 'v'], 'DEFAULT {{X}}', ['{{X}}']));
    }

    public function testCandidateForOneKeyDoesNotAffectAnother(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $ephemeral->set('reward_judge', 'CANDIDATE {{X}}');
        $provider = $this->provider($ephemeral, null); // no DB override

        // The candidate is scoped to reward_judge; another key still resolves to its default.
        self::assertSame('DEFAULT v', $provider->resolve('contextual_enrichment', ['{{X}}' => 'v'], 'DEFAULT {{X}}', ['{{X}}']));
    }
}
