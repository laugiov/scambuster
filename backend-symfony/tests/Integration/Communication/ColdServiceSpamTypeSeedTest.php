<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ScamTypeManager;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The COLD_SERVICE_SPAM scam type must be seeded (reference fixtures /
 * migration) as an active type with its taxonomy mapping and linked to
 * the personas a B2B service pitch plausibly reaches, so live
 * classification can both route to it and assign a fitting persona.
 */
class ColdServiceSpamTypeSeedTest extends KernelTestCase
{
    private ScamTypeManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = static::getContainer()->get(ScamTypeManager::class);
    }

    public function testTypeIsSeededAndActive(): void
    {
        $type = $this->manager->findByCode('COLD_SERVICE_SPAM');

        $this->assertInstanceOf(ScamType::class, $type);
        $this->assertTrue($type->isActive());
        $this->assertSame('Cold Service Spam / Fake Vendor', $type->getLabel());
        $this->assertSame('rsit:fraud="scam"', $type->getMispTaxonomy());
        $this->assertNotNull($type->getDescription());
        $this->assertStringContainsStringIgnoringCase('unsolicited', (string) $type->getDescription());
    }

    public function testTypeIsListedByManager(): void
    {
        $this->assertContains('COLD_SERVICE_SPAM', $this->manager->getAllCodes());
    }

    public function testTypeIsLinkedToB2bServicePersonas(): void
    {
        $type = $this->manager->findByCode('COLD_SERVICE_SPAM');
        $this->assertInstanceOf(ScamType::class, $type);

        $linked = array_map(
            static fn ($p): string => $p->getPersonaCode(),
            $type->getPersonas()->toArray()
        );

        foreach (['small_business_owner', 'freelance_cautious', 'accountant_meticulous', 'entrepreneur_rushed'] as $expected) {
            $this->assertContains($expected, $linked, "COLD_SERVICE_SPAM must be linked to {$expected}");
        }
    }
}
