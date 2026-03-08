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

    public function testItThrowsExceptionWhenConfidenceTooLow(): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy([]);
        $this->assertNotNull($conversation);
        $convId = $conversation->getConvId();

        // Mock classification result with low confidence
        $classificationResult = new ClassificationResult(
            scamTypeCode: 'phishing',
            confidence: 0.65, // Below threshold of 0.75
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
        $this->expectExceptionMessage('Classification confidence too low: 0.65');

        $this->handler->classifyConversation($convId);
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
}
