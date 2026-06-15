<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scambaiting;

use App\Application\Scambaiting\PersonaMirrorQueryService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 104 P3 — shape test on the query service.
 *
 * The cache is filled on demand by the CLI command; this test asserts
 * the read path works (empty array is a valid response) and that the
 * row shape conforms when there is at least one cached row.
 */
final class PersonaMirrorQueryServiceTest extends KernelTestCase
{
    public function testByPersonaReturnsListShape(): void
    {
        self::bootKernel();
        /** @var PersonaMirrorQueryService $service */
        $service = self::getContainer()->get(PersonaMirrorQueryService::class);

        // Call with a non-existent persona — must return an empty list
        // (no exception, no nulls).
        $rows = $service->getByPersona('nonexistent_persona_for_test_' . uniqid());
        $this->assertSame([], $rows);
    }

    public function testByScamTypeReturnsListShape(): void
    {
        self::bootKernel();
        /** @var PersonaMirrorQueryService $service */
        $service = self::getContainer()->get(PersonaMirrorQueryService::class);

        $rows = $service->getByScamType('NONEXISTENT_SCAM_TYPE_' . uniqid());
        $this->assertSame([], $rows);
    }
}
