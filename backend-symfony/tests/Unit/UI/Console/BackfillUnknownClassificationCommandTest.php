<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\Application\Communication\ClassificationResult;
use App\Application\Communication\ScamClassificationHandler;
use App\UI\Console\BackfillUnknownClassificationCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The backfill command must be a safe, bounded re-classification tool:
 * preview-by-default (zero writes), applying only with --apply, and only
 * ever touching the scope the handler returns. These tests drive the
 * command against a mocked handler so the orchestration contract is
 * pinned without a DB or an LLM.
 */
final class BackfillUnknownClassificationCommandTest extends TestCase
{
    private ScamClassificationHandler&MockObject $handler;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ScamClassificationHandler::class);
        $app = new Application();
        $app->addCommand(new BackfillUnknownClassificationCommand($this->handler));
        $this->tester = new CommandTester($app->find('scambuster:classify:backfill-unknown'));
    }

    private function classification(string $code, float $confidence): ClassificationResult
    {
        return new ClassificationResult($code, $confidence, false, false, null, 'test');
    }

    public function testDryRunPreviewsAndNeverPersists(): void
    {
        $this->handler->method('findRecentUnknownConversationIds')->willReturn(['c1', 'c2']);
        $this->handler->method('previewClassifyConversation')->willReturnMap([
            ['c1', $this->classification('COLD_SERVICE_SPAM', 0.82)],
            ['c2', $this->classification('PHISHING', 0.9)],
        ]);
        // The hard safety guarantee: default mode must never call the
        // persisting path.
        $this->handler->expects($this->never())->method('autoClassifyConversation');

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        $out = $this->tester->getDisplay();
        $this->assertStringContainsString('PREVIEW (no writes)', $out);
        $this->assertStringContainsString('UNKNOWN → COLD_SERVICE_SPAM', $out);
        $this->assertStringContainsString('UNKNOWN → PHISHING', $out);
        $this->assertStringContainsString('Would reclassify', $out);
    }

    public function testApplyPersistsOnlyForNonUnknownProposals(): void
    {
        $this->handler->method('findRecentUnknownConversationIds')->willReturn(['c1', 'c2']);
        // In apply mode the command uses the persisting path; the handler
        // itself keeps a conv UNKNOWN when the proposal is UNKNOWN/below
        // threshold (returned here for c2).
        $this->handler->expects($this->exactly(2))->method('autoClassifyConversation')
            ->willReturnCallback(fn (string $id): array => [
                'scam_type_code' => $id === 'c1' ? 'COLD_SERVICE_SPAM' : 'UNKNOWN',
                'scam_type_label' => 'x',
                'persona_code' => null,
                'persona_label' => null,
                'confidence' => $id === 'c1' ? 0.82 : 0.4,
                'is_new_scam_type' => false,
                'is_new_persona' => false,
                'secondary_types' => null,
            ]);
        $this->handler->expects($this->never())->method('previewClassifyConversation');

        $this->tester->execute(['--apply' => true]);
        $this->tester->assertCommandIsSuccessful();

        $out = $this->tester->getDisplay();
        $this->assertStringContainsString('APPLY (writes)', $out);
        $this->assertStringContainsString('applied c1 — UNKNOWN → COLD_SERVICE_SPAM', $out);
        $this->assertStringContainsString('c2 — stays UNKNOWN', $out);
        $this->assertStringContainsString('Reclassified', $out);
    }

    public function testStillUnknownIsReportedAsNoChange(): void
    {
        $this->handler->method('findRecentUnknownConversationIds')->willReturn(['c1']);
        $this->handler->method('previewClassifyConversation')->willReturn($this->classification('UNKNOWN', 0.6));

        $this->tester->execute([]);

        $out = $this->tester->getDisplay();
        $this->assertStringContainsString('stays UNKNOWN', $out);
        $this->assertStringNotContainsString('would set', $out);
    }

    public function testEmptyInboundConversationIsSkipped(): void
    {
        $this->handler->method('findRecentUnknownConversationIds')->willReturn(['c1']);
        $this->handler->method('previewClassifyConversation')->willReturn(null);

        $this->tester->execute([]);

        $out = $this->tester->getDisplay();
        $this->assertStringContainsString('no messages', $out);
        $this->assertStringContainsString('Skipped (no messages)', $out);
    }

    public function testClassifierFailureOnOneConvDoesNotAbortRun(): void
    {
        $this->handler->method('findRecentUnknownConversationIds')->willReturn(['c1', 'c2']);
        $this->handler->method('previewClassifyConversation')->willReturnCallback(
            function (string $id): ClassificationResult {
                if ($id === 'c1') {
                    throw new \RuntimeException('LLM down');
                }

                return $this->classification('COLD_SERVICE_SPAM', 0.8);
            }
        );

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();

        $out = $this->tester->getDisplay();
        $this->assertStringContainsString('fail', $out);
        $this->assertStringContainsString('LLM down', $out);
        // The run still processed c2 after c1 failed.
        $this->assertStringContainsString('UNKNOWN → COLD_SERVICE_SPAM', $out);
    }

    public function testEmptyScopeIsANoOp(): void
    {
        $this->handler->method('findRecentUnknownConversationIds')->willReturn([]);
        $this->handler->expects($this->never())->method('previewClassifyConversation');
        $this->handler->expects($this->never())->method('autoClassifyConversation');

        $this->tester->execute([]);
        $this->tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Nothing to do', $this->tester->getDisplay());
    }

    public function testDaysOptionIsForwardedToScope(): void
    {
        $this->handler->expects($this->once())->method('findRecentUnknownConversationIds')
            ->with(7, null)->willReturn([]);

        $this->tester->execute(['--days' => '7']);
        $this->tester->assertCommandIsSuccessful();
    }
}
