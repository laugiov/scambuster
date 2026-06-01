<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ClassificationResult;
use App\Application\Communication\ConversationHandler;
use App\Application\Communication\PersonaManager;
use App\Application\Communication\ScamClassificationHandler;
use App\Application\Communication\ScamTypeManager;
use App\Application\LLM\ScamClassifier;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScamClassificationHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScamClassificationHandler $handler;
    private ScamClassifier $scamClassifier;
    private PersonaManager $personaManager;
    private ScamTypeManager $scamTypeManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->scamClassifier = $this->createMock(ScamClassifier::class);
        $this->personaManager = $container->get(PersonaManager::class);
        $this->scamTypeManager = $container->get(ScamTypeManager::class);
        $conversationHandler = $container->get(ConversationHandler::class);
        $logger = $container->get('monolog.logger.llm');

        $this->handler = new ScamClassificationHandler(
            $this->em,
            $this->scamClassifier,
            $this->personaManager,
            $this->scamTypeManager,
            $conversationHandler,
            $logger
        );
    }

    public function testItThrowsExceptionWhenConversationNotFound(): void
    {
        $this->expectException(\Exception::class); // Can be RuntimeException or ConversionException

        $this->handler->classifyConversation('00000000-0000-0000-0000-000000000000');
    }

    public function testItClassifiesExistingScamType(): void
    {
        // Create a test conversation with messages
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation, 'No conversation found in fixtures');

        $convId = $conversation->getConvId();

        // Mock classification result for existing type
        $classificationResult = new ClassificationResult(
            scamTypeCode: 'phishing',
            confidence: 0.92,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Classic phishing pattern detected'
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $result = $this->handler->classifyConversation($convId);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame('phishing', $result->scamTypeCode);
        $this->assertSame(0.92, $result->confidence);
        $this->assertFalse($result->isNewType);

        // Verify conversation was updated
        // Note: The code normalizes to uppercase, so PHISHING in database
        $this->em->refresh($conversation);
        $this->assertSame('PHISHING', $conversation->getScamType()->getCode());
    }

    public function testItCreatesNewScamTypeWithPersona(): void
    {
        // Create a test conversation
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        // Count existing scam types and personas
        $initialScamTypeCount = count($this->scamTypeManager->getAll());
        $initialPersonaCount = count($this->personaManager->getAllActive());

        // Mock classification result for new type with persona
        $uniqueSuffix = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6); // Random letters only
        $personaData = [
            'persona_code' => 'test_inv_' . $uniqueSuffix,
            'persona_label' => 'Test Crypto Investor',
            'persona_tone' => 'Enthusiastic but naive about investments',
            'system_prompt' => 'You are a person interested in cryptocurrency. You ask questions about returns but are easily convinced by promises. You are curious but not experienced in detecting scams. Minimum 100 chars.',
            'label_en' => 'Test Crypto Scam',
            'label_fr' => 'Test Arnaque Crypto',
        ];

        $classificationResult = new ClassificationResult(
            scamTypeCode: 'test_cry_' . $uniqueSuffix,
            confidence: 0.87,
            isNewType: true,
            isNewPersona: true,
            personaCode: $personaData['persona_code'],
            reasoning: 'New crypto scam pattern detected',
            personaData: $personaData
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $result = $this->handler->classifyConversation($convId);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertTrue($result->isNewType);
        $this->assertTrue($result->isNewPersona);

        // Verify new persona was created
        $newPersona = $this->personaManager->findByCode($personaData['persona_code']);
        $this->assertInstanceOf(Persona::class, $newPersona);
        $this->assertSame($personaData['persona_label'], $newPersona->getPersonaLabel());
        $this->assertSame('llm_auto', $newPersona->getCreatedBy());

        // Verify new scam type was created and linked to persona
        $newScamType = $this->scamTypeManager->findByCode($result->scamTypeCode);
        $this->assertInstanceOf(ScamType::class, $newScamType);
        // Sprint 3: Single label field, not labelEn/labelFr
        $expectedLabel = $personaData['label_fr'] ?? $personaData['label_en'];
        $this->assertSame($expectedLabel, $newScamType->getLabel());
        $this->assertCount(1, $newScamType->getPersonas());
        $this->assertSame($newPersona->getPersonaId(), $newScamType->getPersonas()->first()->getPersonaId());

        // Verify conversation was updated
        $this->em->refresh($conversation);
        // Note: ScamTypeManager normalizes codes to uppercase
        $this->assertSame(strtoupper($result->scamTypeCode), $conversation->getScamType()->getCode());

        // Verify counts increased
        $this->assertCount($initialScamTypeCount + 1, $this->scamTypeManager->getAll());
        $this->assertCount($initialPersonaCount + 1, $this->personaManager->getAllActive());
    }

    /**
     * Spec 095 Fix #2 — Updated: confidence threshold lowered from 0.75 to 0.55.
     * Confidence 0.40 is now the boundary case for rejection (below 0.55).
     * Confidence 0.65 (the previous test value) is now ACCEPTED — see
     * testItAcceptsConfidence065_Fix02_PreviouslyRejected below.
     *
     * See: specs/095-pipeline-audit/fix-02-lower-confidence-threshold/spec.md
     */
    public function testItThrowsExceptionWhenConfidenceTooLow(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        // Mock classification result with low confidence (below new 0.55 threshold)
        $classificationResult = new ClassificationResult(
            scamTypeCode: 'phishing',
            confidence: 0.40, // Below new threshold of 0.55 (Spec 095 Fix #2)
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Uncertain classification'
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Classification confidence too low: 0.4');

        $this->handler->classifyConversation($convId);
    }

    /**
     * Spec 095 Fix #2 — confidence=0.65 (which was rejected under the old
     * 0.75 threshold) MUST NOW be accepted under the new 0.55 threshold.
     * This unblocks hybrid scams (Wikipedia/grant/invoice composites) that
     * naturally elicit moderate confidence.
     *
     * See: specs/095-pipeline-audit/fix-02-lower-confidence-threshold/spec.md
     */
    public function testItAcceptsConfidence065_Fix02_PreviouslyRejected(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        // Need an existing scam type so updateConversationScamType() doesn't fail
        $existingScamType = $this->scamTypeManager->getAll()[0] ?? null;
        $this->assertNotNull($existingScamType, 'Need at least one scam type in fixtures');

        $classificationResult = new ClassificationResult(
            scamTypeCode: $existingScamType->getCode(),
            confidence: 0.65, // ABOVE new 0.55 threshold — must be accepted now
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Hybrid scam pattern, moderate confidence'
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $result = $this->handler->classifyConversation($convId);

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame(0.65, $result->confidence);
        $this->assertSame($existingScamType->getCode(), $result->scamTypeCode);
    }

    public function testItReusesExistingPersonaWhenCreatingNewScamType(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        // Find an existing persona
        $existingPersona = $this->personaManager->getAllActive()[0] ?? null;
        $this->assertNotNull($existingPersona, 'No persona found in fixtures');

        $personaData = [
            'persona_code' => $existingPersona->getPersonaCode(), // Use existing persona code
            'persona_label' => $existingPersona->getPersonaLabel(),
            'persona_tone' => $existingPersona->getPersonaTone(),
            'system_prompt' => $existingPersona->getSystemPrompt(),
            'label_en' => 'Test New Scam Type',
            'label_fr' => 'Test Nouveau Type Arnaque',
        ];

        $uniqueSuffix = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6);
        $classificationResult = new ClassificationResult(
            scamTypeCode: 'test_scam_' . $uniqueSuffix,
            confidence: 0.89,
            isNewType: true,
            isNewPersona: true, // Marked as new but code already exists
            personaCode: $personaData['persona_code'],
            reasoning: 'New scam type with existing persona',
            personaData: $personaData
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $initialPersonaCount = count($this->personaManager->getAllActive());

        $result = $this->handler->classifyConversation($convId);

        // Verify persona count didn't increase (reused existing)
        $this->assertCount($initialPersonaCount, $this->personaManager->getAllActive());

        // Verify new scam type was created and linked to existing persona
        $newScamType = $this->scamTypeManager->findByCode($result->scamTypeCode);
        $this->assertInstanceOf(ScamType::class, $newScamType);
        $this->assertCount(1, $newScamType->getPersonas());
        $this->assertSame($existingPersona->getPersonaId(), $newScamType->getPersonas()->first()->getPersonaId());
    }

    public function testItThrowsExceptionWhenClassificationReturnsNull(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LLM classification failed');

        $this->handler->classifyConversation($convId);
    }

    public function testItHandlesTransactionRollbackOnError(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        $personaData = [
            'persona_code' => 'test_rollback_' . time(),
            'persona_label' => 'Test Rollback Persona',
            'persona_tone' => 'Test tone',
            'system_prompt' => 'Test system prompt that is long enough to pass validation because it needs at least 100 characters to be valid',
            'label_en' => 'Test Rollback',
            'label_fr' => 'Test Rollback FR',
        ];

        // Use invalid scam_type_code to trigger error
        $classificationResult = new ClassificationResult(
            scamTypeCode: '', // Invalid empty code
            confidence: 0.85,
            isNewType: true,
            isNewPersona: true,
            personaCode: $personaData['persona_code'],
            reasoning: 'Test rollback',
            personaData: $personaData
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $initialPersonaCount = count($this->personaManager->getAllActive());

        try {
            $this->handler->classifyConversation($convId);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // Expected exception
        }

        // Verify transaction was rolled back - no new persona created
        $this->assertCount($initialPersonaCount, $this->personaManager->getAllActive());
        $this->assertNull($this->personaManager->findByCode($personaData['persona_code']));
    }

    // ================================================================== //
    //  Merged from ScamClassificationHandlerAdditionalTest
    // ================================================================== //

    public function testManualClassifyConversationWithExistingScamType(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        // Find an existing scam type
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $this->assertNotNull($scamType);

        $result = $this->handler->manualClassifyConversation($convId, $scamType->getCode());

        $this->assertArrayHasKey('scam_type_code', $result);
        $this->assertArrayHasKey('scam_type_label', $result);
        $this->assertSame($scamType->getCode(), $result['scam_type_code']);
    }

    public function testManualClassifyConversationWithPersonaCode(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $this->assertNotNull($scamType);

        $persona = $this->personaManager->getAllActive()[0] ?? null;
        $this->assertNotNull($persona, 'No active persona in fixtures');

        $result = $this->handler->manualClassifyConversation($convId, $scamType->getCode(), $persona->getPersonaCode());

        $this->assertArrayHasKey('persona_code', $result);
        $this->assertSame($persona->getPersonaCode(), $result['persona_code']);
    }

    public function testManualClassifyConversationThrowsForNonexistentConversation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');

        $this->handler->manualClassifyConversation(
            '00000000-0000-0000-0000-000000000000',
            'PHISHING'
        );
    }

    public function testManualClassifyConversationThrowsForNonexistentScamType(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scam type not found');

        $this->handler->manualClassifyConversation($conversation->getConvId(), 'NONEXISTENT_SCAM_TYPE');
    }

    public function testManualClassifyConversationThrowsForNonexistentPersona(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);

        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $this->assertNotNull($scamType);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Persona not found');

        $this->handler->manualClassifyConversation($conversation->getConvId(), $scamType->getCode(), 'nonexistent_persona');
    }

    public function testAutoClassifyConversationSkipsAlreadyClassified(): void
    {
        // Find a conversation that is NOT UNKNOWN
        $conversations = $this->em->getRepository(Conversation::class)->findAll();
        $classifiedConv = null;

        foreach ($conversations as $conv) {
            if (strtoupper($conv->getScamType()->getCode()) !== 'UNKNOWN') {
                $classifiedConv = $conv;
                break;
            }
        }

        if ($classifiedConv === null) {
            $this->markTestSkipped('No pre-classified conversation in fixtures');
        }

        // LLM should NOT be called
        $this->scamClassifier->expects($this->never())->method('classify');

        $result = $this->handler->autoClassifyConversation($classifiedConv->getConvId());

        $this->assertSame($classifiedConv->getScamType()->getCode(), $result['scam_type_code']);
        $this->assertSame(1.0, $result['confidence']);
        $this->assertFalse($result['is_new_scam_type']);
        $this->assertFalse($result['is_new_persona']);
    }

    public function testAutoClassifyConversationForceOverridesSkip(): void
    {
        $conversations = $this->em->getRepository(Conversation::class)->findAll();
        $classifiedConv = null;

        foreach ($conversations as $conv) {
            if (strtoupper($conv->getScamType()->getCode()) !== 'UNKNOWN') {
                $classifiedConv = $conv;
                break;
            }
        }

        if ($classifiedConv === null) {
            $this->markTestSkipped('No pre-classified conversation in fixtures');
        }

        // With force=true, LLM SHOULD be called
        $classificationResult = new ClassificationResult(
            scamTypeCode: 'phishing',
            confidence: 0.95,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Forced reclassification'
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $result = $this->handler->autoClassifyConversation($classifiedConv->getConvId(), true);

        $this->assertSame(0.95, $result['confidence']);
    }

    public function testAutoClassifyConversationThrowsForNonexistentConversation(): void
    {
        $this->expectException(\Exception::class);

        $this->handler->autoClassifyConversation('00000000-0000-0000-0000-000000000000');
    }

    public function testAutoClassifyConversationThrowsWhenLlmReturnsNull(): void
    {
        // Find UNKNOWN conversation
        $conversations = $this->em->getRepository(Conversation::class)->findAll();
        $unknownConv = null;

        foreach ($conversations as $conv) {
            if (strtoupper($conv->getScamType()->getCode()) === 'UNKNOWN') {
                $unknownConv = $conv;
                break;
            }
        }

        if ($unknownConv === null) {
            $this->markTestSkipped('No UNKNOWN conversation in fixtures');
        }

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LLM classification failed');

        $this->handler->autoClassifyConversation($unknownConv->getConvId());
    }

    public function testClassifyConversationCreatesNewTypeWithoutPersona(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        $uniqueSuffix = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6);
        $classificationResult = new ClassificationResult(
            scamTypeCode: 'test_nop_' . $uniqueSuffix,
            confidence: 0.88,
            isNewType: true,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'New type detected without persona',
        );

        $this->scamClassifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($classificationResult);

        $result = $this->handler->classifyConversation($convId);

        $this->assertTrue($result->isNewType);
        $this->assertFalse($result->isNewPersona);

        // Verify the new scam type exists
        $newScamType = $this->scamTypeManager->findByCode($result->scamTypeCode);
        $this->assertNotNull($newScamType);
    }
}
