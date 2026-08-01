<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Prompt;

use App\Application\Guard\CanaryAvailability;
use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Application\Prompt\PromptBodyValidator;
use App\Application\Prompt\PromptOverrideHandler;
use App\Domain\Prompt\PromptOverride;
use App\Domain\Prompt\PromptOverrideRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PromptOverrideHandlerTest extends TestCase
{
    /**
     * @param list<PromptOverride> $stored
     */
    private function handler(array $stored = [], bool $llmConfigured = true): PromptOverrideHandler
    {
        $byKey = [];

        foreach ($stored as $o) {
            $byKey[$o->getPromptKey()] = $o;
        }

        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findAll')->willReturn($stored);
        $repo->method('findByKey')->willReturnCallback(static fn (string $k): ?PromptOverride => $byKey[$k] ?? null);

        return new PromptOverrideHandler($repo, new PromptBodyValidator(), new CanaryAvailability('openai', $llmConfigured ? 'sk-live-testkey' : '', ''));
    }

    // ─── list / get (catalog × overrides) ──────────────────────────────

    public function testListReturnsOneRowPerCatalogKey(): void
    {
        $rows = $this->handler()->list();
        $keys = array_column($rows, 'key');

        self::assertContains('contextual_enrichment', $keys);
        self::assertContains('reward_judge', $keys);
        self::assertSame(count($keys), count(array_unique($keys)));
    }

    public function testRowReportsCanaryAvailableWhenLlmIsConfigured(): void
    {
        $row = $this->rowFor($this->handler()->list(), 'persona_style_rules');

        self::assertTrue($row['canary_available'], 'canary_available must be true when an LLM key is configured');
    }

    public function testRowReportsCanaryUnavailableWhenNoLlmKey(): void
    {
        // e.g. the public demo: no LLM key → a validation job could only hang/fail, so the UI
        // must be told the canary is unavailable and hide the "Validate" action.
        $row = $this->rowFor($this->handler([], llmConfigured: false)->list(), 'persona_style_rules');

        self::assertFalse($row['canary_available']);
        // The static capability is unchanged — only runtime availability differs.
        self::assertTrue($row['canary_validatable']);
    }

    public function testListMarksNoOverrideAsDefault(): void
    {
        $row = $this->rowFor($this->handler()->list(), 'reward_judge');

        self::assertFalse($row['has_override']);
        self::assertFalse($row['active']);
        self::assertTrue($row['valid']);
        self::assertNull($row['body']);
    }

    public function testListMarksValidEnabledOverrideActive(): void
    {
        $rows = $this->handler([new PromptOverride('reward_judge', 'CUSTOM', true, 'me@x')])->list();
        $row = $this->rowFor($rows, 'reward_judge');

        self::assertTrue($row['has_override']);
        self::assertTrue($row['enabled']);
        self::assertTrue($row['valid']);
        self::assertTrue($row['active']);
        self::assertSame('CUSTOM', $row['body']);
        self::assertSame('me@x', $row['updated_by']);
    }

    public function testListMarksDisabledOverrideInactiveButValid(): void
    {
        $rows = $this->handler([new PromptOverride('reward_judge', 'CUSTOM', false)])->list();
        $row = $this->rowFor($rows, 'reward_judge');

        self::assertTrue($row['has_override']);
        self::assertFalse($row['enabled']);
        self::assertFalse($row['active']);
    }

    public function testListFlagsOverrideMissingRequiredPlaceholderAsInvalid(): void
    {
        // contextual_enrichment requires {{SCAM_TYPE}} etc.
        $rows = $this->handler([new PromptOverride('contextual_enrichment', 'no tokens', true)])->list();
        $row = $this->rowFor($rows, 'contextual_enrichment');

        self::assertFalse($row['valid']);
        self::assertFalse($row['active'], 'an invalid override is never active');
        self::assertContains('{{SCAM_TYPE}}', $row['missing_placeholders']);
    }

    public function testGetUnknownKeyThrows(): void
    {
        $this->expectException(UnknownPromptKeyException::class);
        $this->handler()->get('does_not_exist');
    }

    // ─── read-only shipped-default reference ───────────────────────────

    public function testRowExposesTheShippedDefaultBody(): void
    {
        $row = $this->rowFor($this->handler()->list(), 'reward_judge');

        self::assertArrayHasKey('default_body', $row);
        self::assertNotSame('', $row['default_body']);
        self::assertSame(PromptCatalog::defaultBody('reward_judge'), $row['default_body']);
    }

    public function testDefaultBodyIsTheShippedDefaultEvenWithAnOverride(): void
    {
        // The reference an operator sees is always the shipped default — never their own
        // override body — so they can compare and revert to it.
        $rows = $this->handler([new PromptOverride('reward_judge', 'MY CUSTOM RUBRIC', true)])->list();
        $row = $this->rowFor($rows, 'reward_judge');

        self::assertSame('MY CUSTOM RUBRIC', $row['body']);
        self::assertSame(PromptCatalog::defaultBody('reward_judge'), $row['default_body']);
        self::assertNotSame($row['body'], $row['default_body']);
    }

    // ─── upsert validation ─────────────────────────────────────────────

    public function testUpsertRejectsUnknownKey(): void
    {
        $this->expectException(UnknownPromptKeyException::class);
        $this->handler()->upsert('nope', 'x', true, null);
    }

    public function testUpsertRejectsEmptyBody(): void
    {
        $this->expectException(InvalidPromptOverrideException::class);
        $this->handler()->upsert('reward_judge', '   ', true, null);
    }

    public function testUpsertRejectsBodyMissingRequiredPlaceholder(): void
    {
        $this->expectException(InvalidPromptOverrideException::class);
        $this->handler()->upsert('contextual_enrichment', 'missing the tokens', true, null);
    }

    public function testUpsertRejectsOverlyLongBody(): void
    {
        $this->expectException(InvalidPromptOverrideException::class);
        // reward_judge has no required tokens, so length is the only failing rule.
        $this->handler()->upsert('reward_judge', str_repeat('a', 20001), true, null);
    }

    public function testUpsertAcceptsBodyAtTheLengthLimit(): void
    {
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findByKey')->willReturn(null);
        $repo->expects(self::once())->method('save');

        (new PromptOverrideHandler($repo, new PromptBodyValidator(), new CanaryAvailability('openai', 'sk-live-testkey', '')))->upsert('reward_judge', str_repeat('a', 20000), true, null);
    }

    public function testUpsertAcceptsBodyWithAllRequiredPlaceholders(): void
    {
        $body = 'scam={{SCAM_TYPE}} p={{PERSONA_CODE}} rt={{REVELATION_TURN}} tt={{TOTAL_TURNS}} '
            . 'ioc={{IOC_TYPES}} prev={{PREVIOUS_INBOUND}} stim={{STIMULUS_MESSAGE}} rev={{REVELATION_MESSAGE}}';

        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findByKey')->willReturn(null);
        $repo->expects(self::once())->method('save');

        (new PromptOverrideHandler($repo, new PromptBodyValidator(), new CanaryAvailability('openai', 'sk-live-testkey', '')))->upsert('contextual_enrichment', $body, true, 'me@x');
    }

    public function testUpsertUpdatesExistingRowInPlace(): void
    {
        $existing = new PromptOverride('reward_judge', 'OLD', true);
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findByKey')->willReturn($existing);
        $repo->expects(self::once())->method('save')->with($existing);

        (new PromptOverrideHandler($repo, new PromptBodyValidator(), new CanaryAvailability('openai', 'sk-live-testkey', '')))->upsert('reward_judge', 'NEW BODY', false, 'me@x');

        self::assertSame('NEW BODY', $existing->getBody());
        self::assertFalse($existing->isEnabled());
    }

    // ─── delete ────────────────────────────────────────────────────────

    public function testDeleteRemovesAnExistingOverride(): void
    {
        $existing = new PromptOverride('reward_judge', 'X', true);
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findByKey')->willReturn($existing);
        $repo->expects(self::once())->method('delete')->with($existing);

        self::assertTrue((new PromptOverrideHandler($repo, new PromptBodyValidator(), new CanaryAvailability('openai', 'sk-live-testkey', '')))->delete('reward_judge'));
    }

    public function testDeleteIsNoOpWhenNoOverride(): void
    {
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findByKey')->willReturn(null);
        $repo->expects(self::never())->method('delete');

        self::assertFalse((new PromptOverrideHandler($repo, new PromptBodyValidator(), new CanaryAvailability('openai', 'sk-live-testkey', '')))->delete('reward_judge'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, string $key): array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        self::fail("row for '{$key}' not found");
    }
}
