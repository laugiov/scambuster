<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ClassificationResult;
use App\Application\Communication\ConversationHandler;
use App\Application\Communication\PersonaManager;
use App\Application\Communication\ScamClassificationHandler;
use App\Application\Communication\ScamTypeManager;
use App\Application\LLM\ScamClassifier;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ScamClassificationHandler.
 * Tests that don't require QueryBuilder mocking (which is brittle).
 * Full integration paths are tested in integration tests.
 */
class ScamClassificationHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ScamClassifier&MockObject $scamClassifier;
    private PersonaManager&MockObject $personaManager;
    private ScamTypeManager&MockObject $scamTypeManager;
    private ConversationHandler&MockObject $conversationHandler;
    private ScamClassificationHandler $handler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->scamClassifier = $this->createMock(ScamClassifier::class);
        $this->personaManager = $this->createMock(PersonaManager::class);
        $this->scamTypeManager = $this->createMock(ScamTypeManager::class);
        $this->conversationHandler = $this->createMock(ConversationHandler::class);

        $this->handler = new ScamClassificationHandler(
            $this->em,
            $this->scamClassifier,
            $this->personaManager,
            $this->scamTypeManager,
            $this->conversationHandler,
            new NullLogger(),
        );
    }

    // --- classifyConversation tests ---

    public function test_classifyConversation_throws_when_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');
        $this->handler->classifyConversation('nonexistent-id');
    }

    // --- manualClassifyConversation tests ---

    public function test_manualClassifyConversation_throws_when_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');
        $this->handler->manualClassifyConversation('conv-123', 'PHISHING');
    }

    public function test_manualClassifyConversation_throws_when_deleted(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(new \DateTimeImmutable());

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');
        $this->handler->manualClassifyConversation('conv-123', 'PHISHING');
    }

    public function test_manualClassifyConversation_throws_when_scam_type_not_found(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(null);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->scamTypeManager->method('findByCode')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scam type not found');
        $this->handler->manualClassifyConversation('conv-123', 'NONEXISTENT');
    }

    public function test_manualClassifyConversation_throws_when_persona_not_found(): void
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn('PHISHING');
        $scamType->method('getLabel')->willReturn('Phishing');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(null);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->scamTypeManager->method('findByCode')->willReturn($scamType);
        $this->personaManager->method('findByCode')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Persona not found');
        $this->handler->manualClassifyConversation('conv-123', 'PHISHING', 'nonexistent_persona');
    }

    public function test_manualClassifyConversation_success_with_persona(): void
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn('PHISHING');
        $scamType->method('getLabel')->willReturn('Phishing');

        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn('elderly_person');
        $persona->method('getPersonaLabel')->willReturn('Elderly Person');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(null);
        $conversation->expects($this->once())->method('setScamType')->with($scamType);
        $conversation->expects($this->once())->method('setPersona')->with($persona);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->scamTypeManager->method('findByCode')->willReturn($scamType);
        $this->personaManager->method('findByCode')->willReturn($persona);
        $this->em->expects($this->once())->method('flush');

        $result = $this->handler->manualClassifyConversation('conv-123', 'PHISHING', 'elderly_person');
        $this->assertSame('PHISHING', $result['scam_type_code']);
        $this->assertSame('elderly_person', $result['persona_code']);
    }

    public function test_manualClassifyConversation_auto_assigns_persona_when_no_code(): void
    {
        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn('generic_user');
        $persona->method('getPersonaLabel')->willReturn('Generic User');

        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn('PHISHING');
        $scamType->method('getLabel')->willReturn('Phishing');
        $scamType->method('getPersonas')->willReturn(new ArrayCollection([$persona]));

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(null);
        $conversation->expects($this->once())->method('setScamType');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->scamTypeManager->method('findByCode')->willReturn($scamType);
        $this->personaManager->method('assignRandomPersona')->willReturn($persona);

        $result = $this->handler->manualClassifyConversation('conv-123', 'PHISHING');
        $this->assertSame('generic_user', $result['persona_code']);
    }

    // --- autoClassifyConversation tests ---

    public function test_autoClassifyConversation_throws_when_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');
        $this->handler->autoClassifyConversation('conv-123');
    }

    public function test_autoClassifyConversation_skips_already_classified(): void
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn('PHISHING');
        $scamType->method('getLabel')->willReturn('Phishing');

        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn('generic_user');
        $persona->method('getPersonaLabel')->willReturn('Generic');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(null);
        $conversation->method('getScamType')->willReturn($scamType);
        $conversation->method('getPersona')->willReturn($persona);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        // Should NOT call classify
        $this->scamClassifier->expects($this->never())->method('classify');

        $result = $this->handler->autoClassifyConversation('conv-123');
        $this->assertSame('PHISHING', $result['scam_type_code']);
        $this->assertSame(1.0, $result['confidence']);
        $this->assertFalse($result['is_new_scam_type']);
    }

    public function test_autoClassifyConversation_throws_when_deleted(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getDeletedAt')->willReturn(new \DateTimeImmutable());

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');
        $this->handler->autoClassifyConversation('conv-123');
    }

    // --- ClassificationResult tests ---

    public function test_classification_result_should_apply(): void
    {
        $result = new ClassificationResult('PHISHING', 0.9, false, false, 'generic', 'reason');
        $this->assertTrue($result->shouldApply(0.75));
        $this->assertTrue($result->shouldApply(0.9));
        $this->assertFalse($result->shouldApply(0.95));
    }

    /**
     * Spec 095 Fix #2 — Default threshold lowered from 0.75 to 0.55.
     * shouldApply() with no argument MUST use 0.55 as the new boundary.
     *
     * See: specs/095-pipeline-audit/fix-02-lower-confidence-threshold/spec.md
     */
    public function test_should_apply_default_threshold_is_055(): void
    {
        // Confidence 0.60 is now above the default threshold (0.55) — accepted
        $accepted = new ClassificationResult('PHISHING', 0.60, false, false, 'generic', 'reason');
        $this->assertTrue($accepted->shouldApply(), 'shouldApply() with no arg must accept confidence=0.60 (above new default 0.55)');

        // Confidence 0.50 is below the new default threshold — rejected
        $rejected = new ClassificationResult('PHISHING', 0.50, false, false, 'generic', 'reason');
        $this->assertFalse($rejected->shouldApply(), 'shouldApply() with no arg must reject confidence=0.50 (below new default 0.55)');

        // Confidence exactly at the boundary (0.55) — accepted (>=)
        $boundary = new ClassificationResult('PHISHING', 0.55, false, false, 'generic', 'reason');
        $this->assertTrue($boundary->shouldApply(), 'shouldApply() with no arg must accept confidence=0.55 (exactly at boundary)');
    }

    public function test_classification_result_getters(): void
    {
        $personaData = ['persona_code' => 'test', 'persona_label' => 'Test'];
        $suggestedCodes = ['generic_user', 'elderly_person'];

        $result = new ClassificationResult(
            'PHISHING', 0.85, true, true, 'test', 'High confidence',
            personaData: $personaData,
            suggestedPersonaCodes: $suggestedCodes,
            detectedLanguage: 'fr',
        );

        $this->assertSame($personaData, $result->getPersonaData());
        $this->assertSame($suggestedCodes, $result->getSuggestedPersonaCodes());
        $this->assertSame('fr', $result->detectedLanguage);
    }
}
