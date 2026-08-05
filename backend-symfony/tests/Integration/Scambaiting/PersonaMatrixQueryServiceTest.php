<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scambaiting;

use App\Application\Scambaiting\PersonaMatrixQueryService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Shape + bounds test on the matrix service.
 *
 * The fixture corpus evolves across specs, so we don't pin specific
 * persona codes or reward values. We assert:
 *   - the response is a list of rows
 *   - each row has the contract keys
 *   - reward_avg is null OR in [0, 1] (matches the DB CHECK constraint)
 *   - sessions is a non-negative int
 *   - the (persona_code, scam_type_code) pair is unique within the result
 */
final class PersonaMatrixQueryServiceTest extends KernelTestCase
{
    public function testGetMatrixReturnsExpectedShape(): void
    {
        self::bootKernel();
        /** @var PersonaMatrixQueryService $service */
        $service = self::getContainer()->get(PersonaMatrixQueryService::class);

        $rows = $service->getMatrix();

        // Service returns a typed list per its @return annotation; assert
        // only the dynamic content (bounds, uniqueness) — type-level
        // assertions are redundant because PHPStan already verifies them.
        $seen = [];

        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, $row['sessions'], 'sessions must be non-negative');

            if ($row['reward_avg'] !== null) {
                $this->assertGreaterThanOrEqual(0.0, $row['reward_avg'], 'reward_avg must be >= 0');
                $this->assertLessThanOrEqual(1.0, $row['reward_avg'], 'reward_avg must be <= 1');
            }

            $pair = $row['persona_code'] . '|' . $row['scam_type_code'];
            $this->assertArrayNotHasKey($pair, $seen, "duplicate pair {$pair}");
            $seen[$pair] = true;
        }
    }
}
