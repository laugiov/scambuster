<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\PersonaManager;
use App\Application\Communication\ScamTypeManager;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ScamTypeManager
 *
 * Tests CRUD operations for ScamType entities: find, list, create, link/unlink personas.
 */
class ScamTypeManagerTest extends KernelTestCase
{
    private ScamTypeManager $manager;
    private PersonaManager $personaManager;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->manager = $container->get(ScamTypeManager::class);
        $this->personaManager = $container->get(PersonaManager::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    // ------------------------------------------------------------------ //
    //  getAll / getAllCodes
    // ------------------------------------------------------------------ //

    public function testGetAllReturnsNonEmptyArray(): void
    {
        $all = $this->manager->getAll();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
        $this->assertContainsOnlyInstancesOf(ScamType::class, $all);
    }

    public function testGetAllCodesReturnsStringArray(): void
    {
        $codes = $this->manager->getAllCodes();

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes);
        foreach ($codes as $code) {
            $this->assertIsString($code);
            // Codes are uppercase
            $this->assertSame(strtoupper($code), $code);
        }
    }

    public function testGetAllCodesIsSortedAlphabetically(): void
    {
        $codes = $this->manager->getAllCodes();
        $sorted = $codes;
        sort($sorted);

        $this->assertSame($sorted, $codes);
    }

    // ------------------------------------------------------------------ //
    //  findByCode
    // ------------------------------------------------------------------ //

    public function testFindByCodeReturnsExistingScamType(): void
    {
        $scamType = $this->manager->findByCode('UNKNOWN');

        $this->assertInstanceOf(ScamType::class, $scamType);
        $this->assertSame('UNKNOWN', $scamType->getCode());
    }

    public function testFindByCodeIsCaseInsensitive(): void
    {
        $upper = $this->manager->findByCode('PHISHING');
        $lower = $this->manager->findByCode('phishing');

        if ($upper !== null) {
            $this->assertNotNull($lower);
            $this->assertSame($upper->getScamTypeId(), $lower->getScamTypeId());
        } else {
            $this->assertNull($lower);
        }
    }

    public function testFindByCodeReturnsNullForNonExistent(): void
    {
        $result = $this->manager->findByCode('NONEXISTENT_' . bin2hex(random_bytes(4)));

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ //
    //  createScamType (without persona)
    // ------------------------------------------------------------------ //

    public function testCreateScamTypeCreatesNewEntry(): void
    {
        $code = 'TST_' . strtoupper(substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 6));

        $scamType = $this->manager->createScamType(
            scamTypeCode: $code,
            label: 'Test Create Label',
            description: 'Test description',
            mispTaxonomy: null,
            attckTechnique: null,
            active: true
        );

        $this->assertInstanceOf(ScamType::class, $scamType);
        $this->assertSame($code, $scamType->getCode());
        $this->assertSame('Test Create Label', $scamType->getLabel());
    }

    public function testCreateScamTypeNormalizesToUppercase(): void
    {
        $code = 'tst_' . substr(str_shuffle('abcdefghijklmnop'), 0, 6);

        $scamType = $this->manager->createScamType(
            scamTypeCode: $code,
            label: 'Lowercase test',
        );

        $this->assertSame(strtoupper($code), $scamType->getCode());
    }

    public function testCreateScamTypeThrowsForDuplicateCode(): void
    {
        $code = 'DUP_' . strtoupper(substr(str_shuffle('abcdefghijklmnopqrst'), 0, 6));

        $this->manager->createScamType(scamTypeCode: $code, label: 'First');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->manager->createScamType(scamTypeCode: $code, label: 'Second');
    }

    public function testCreateScamTypeThrowsForInvalidCodeFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid scam_type_code format');

        $this->manager->createScamType(scamTypeCode: 'ab', label: 'Too short');
    }

    public function testCreateScamTypeThrowsForCodeWithDigits(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid scam_type_code format');

        $this->manager->createScamType(scamTypeCode: 'TEST_123', label: 'Has digits');
    }

    // ------------------------------------------------------------------ //
    //  createScamTypeWithPersona
    // ------------------------------------------------------------------ //

    public function testCreateScamTypeWithPersonaLinksPersona(): void
    {
        $persona = $this->em->getRepository(Persona::class)->findOneBy([]);
        $this->assertNotNull($persona, 'Need at least one persona in fixtures');

        $code = 'WP_' . strtoupper(substr(str_shuffle('abcdefghijklmnopqrst'), 0, 6));

        $scamType = $this->manager->createScamTypeWithPersona(
            scamTypeCode: $code,
            label: 'With Persona',
            persona: $persona,
        );

        $this->assertInstanceOf(ScamType::class, $scamType);
        $this->assertCount(1, $scamType->getPersonas());
        $this->assertSame($persona->getPersonaId(), $scamType->getPersonas()->first()->getPersonaId());
    }

    // ------------------------------------------------------------------ //
    //  linkPersona / unlinkPersona
    // ------------------------------------------------------------------ //

    public function testLinkAndUnlinkPersona(): void
    {
        $code = 'LNK_' . strtoupper(substr(str_shuffle('abcdefghijklmnopqrst'), 0, 6));
        $scamType = $this->manager->createScamType(scamTypeCode: $code, label: 'Link test');

        $persona = $this->em->getRepository(Persona::class)->findOneBy([]);
        $this->assertNotNull($persona);

        $this->manager->linkPersona($scamType, $persona);

        $this->em->refresh($scamType);
        $this->assertTrue($scamType->getPersonas()->contains($persona));

        $this->manager->unlinkPersona($scamType, $persona);

        $this->em->refresh($scamType);
        $this->assertFalse($scamType->getPersonas()->contains($persona));
    }
}
