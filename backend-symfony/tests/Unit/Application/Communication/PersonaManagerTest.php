<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\PersonaManager;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PersonaManagerTest extends TestCase
{
    private EntityManagerInterface $em;
    private PersonaManager $personaManager;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->personaManager = new PersonaManager($this->em);
    }

    public function testAssignRandomPersonaReturnsPersonaWhenAvailable(): void
    {
        // Arrange: Create mock personas
        $persona1 = $this->createMockPersona('senior_trusting', 'Retraité confiant');
        $persona2 = $this->createMockPersona('tech_newbie', 'Novice informatique');
        $persona3 = $this->createMockPersona('entrepreneur_rushed', 'Entrepreneur pressé');

        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getPersonas')
            ->willReturn(new ArrayCollection([$persona1, $persona2, $persona3]));

        // Act: Call assignRandomPersona multiple times
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $selectedPersona = $this->personaManager->assignRandomPersona($scamType);
            $this->assertInstanceOf(Persona::class, $selectedPersona);
            $results[] = $selectedPersona->getPersonaCode();
        }

        // Assert: Verify at least 2 different personas were selected (proves randomness)
        $uniquePersonas = array_unique($results);
        $this->assertGreaterThanOrEqual(2, count($uniquePersonas),
            'Random selection should return at least 2 different personas over 20 iterations'
        );
    }

    public function testAssignRandomPersonaReturnsNullWhenNoPersonas(): void
    {
        // Arrange: ScamType with no personas
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getPersonas')
            ->willReturn(new ArrayCollection([]));

        // Act
        $result = $this->personaManager->assignRandomPersona($scamType);

        // Assert
        $this->assertNull($result, 'Should return null when no personas are available');
    }

    public function testAssignRandomPersonaWithSinglePersona(): void
    {
        // Arrange: ScamType with only one persona
        $persona = $this->createMockPersona('generic_user', 'Utilisateur générique');

        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getPersonas')
            ->willReturn(new ArrayCollection([$persona]));

        // Act: Call multiple times
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $selectedPersona = $this->personaManager->assignRandomPersona($scamType);
            $results[] = $selectedPersona;
        }

        // Assert: Should always return the same single persona
        $this->assertCount(5, $results);
        foreach ($results as $result) {
            $this->assertSame($persona, $result);
            $this->assertSame('generic_user', $result->getPersonaCode());
        }
    }

    public function testAssignRandomPersonaDistribution(): void
    {
        // Arrange: 5 personas with equal probability
        $personas = [
            $this->createMockPersona('persona_1', 'Persona 1'),
            $this->createMockPersona('persona_2', 'Persona 2'),
            $this->createMockPersona('persona_3', 'Persona 3'),
            $this->createMockPersona('persona_4', 'Persona 4'),
            $this->createMockPersona('persona_5', 'Persona 5'),
        ];

        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getPersonas')
            ->willReturn(new ArrayCollection($personas));

        // Act: Select 100 times to verify distribution
        $distribution = [];
        for ($i = 0; $i < 100; $i++) {
            $selectedPersona = $this->personaManager->assignRandomPersona($scamType);
            $code = $selectedPersona->getPersonaCode();
            $distribution[$code] = ($distribution[$code] ?? 0) + 1;
        }

        // Assert: Each persona should be selected at least once in 100 iterations
        $this->assertCount(5, $distribution, 'All 5 personas should have been selected');

        // Verify each persona was selected (no 0 counts)
        foreach ($distribution as $count) {
            $this->assertGreaterThan(0, $count, 'Each persona should be selected at least once');
        }
    }

    public function testGetSystemPromptFormatsCorrectly(): void
    {
        // Arrange
        $persona = $this->createMockPersona(
            'senior_trusting',
            'Retraité confiant envers les autorités',
            'Poli, formel, un peu désuet',
            'Tu es un retraité de 70 ans...'
        );

        // Act
        $result = $this->personaManager->getSystemPrompt($persona);

        // Assert
        $this->assertStringContainsString('PERSONA: Retraité confiant envers les autorités', $result);
        $this->assertStringContainsString('Ton: Poli, formel, un peu désuet', $result);
        $this->assertStringContainsString('Tu es un retraité de 70 ans...', $result);
        $this->assertStringContainsString('Incarne ce persona de manière cohérente', $result);
    }

    public function testGetSystemPromptHandlesSpecialCharacters(): void
    {
        // Arrange: Persona with special characters
        $persona = $this->createMockPersona(
            'test_persona',
            'Test "Persona" avec \'quotes\'',
            'Ton: émotif & expressif',
            'Instructions avec €, £, $, ©, ®, ™'
        );

        // Act
        $result = $this->personaManager->getSystemPrompt($persona);

        // Assert: Special characters should be preserved
        $this->assertStringContainsString('Test "Persona" avec \'quotes\'', $result);
        $this->assertStringContainsString('émotif & expressif', $result);
        $this->assertStringContainsString('€, £, $, ©, ®, ™', $result);
    }

    /**
     * Helper method to create mock Persona
     */
    private function createMockPersona(
        string $code,
        string $label,
        string $tone = 'Professional',
        string $systemPrompt = 'Default instructions'
    ): Persona {
        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn($code);
        $persona->method('getPersonaLabel')->willReturn($label);
        $persona->method('getPersonaTone')->willReturn($tone);
        $persona->method('getSystemPrompt')->willReturn($systemPrompt);

        return $persona;
    }
}
