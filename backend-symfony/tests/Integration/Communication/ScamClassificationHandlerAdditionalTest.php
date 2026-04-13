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
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Additional integration tests for ScamClassificationHandler.
 *
 * Covers manualClassifyConversation, autoClassifyConversation,
 * and edge cases not covered by existing ScamClassificationHandlerTest.
 */
final class ScamClassificationHandlerAdditionalTest extends KernelTestCase
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

    // ------------------------------------------------------------------ //
    //  manualClassifyConversation
    // ------------------------------------------------------------------ //

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

    // ------------------------------------------------------------------ //
    //  autoClassifyConversation
    // ------------------------------------------------------------------ //

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

    // ------------------------------------------------------------------ //
    //  classifyConversation — new type without persona
    // ------------------------------------------------------------------ //

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
