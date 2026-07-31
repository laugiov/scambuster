<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scammer engagement endpoint smoke tests.
 *
 * Bias-specific integration tests (the heart of the spec) live in
 * Tests\Integration\Monitoring\ScammerEngagementCalculatorTest. This
 * file covers contract: auth, structure, params, scam_type filter.
 */
final class ScammerEngagementControllerTest extends WebTestCase
{
    private const ENDPOINT = '/api/v1/monitoring/analytics/scammer-engagement';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testEndpointRequiresAuth_096C1(): void
    {
        $this->client->request('GET', self::ENDPOINT);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturnsExpectedStructure_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT);

        $this->assertArrayHasKey('global', $data);
        $this->assertArrayHasKey('by_scam_type', $data);
        $this->assertArrayHasKey('params', $data);
        $this->assertArrayHasKey('methodology_note', $data);

        $this->assertArrayHasKey('observable', $data['global']);
        $this->assertArrayHasKey('responded', $data['global']);
        $this->assertArrayHasKey('rate_pct', $data['global']);

        $this->assertIsArray($data['by_scam_type']);

        $this->assertArrayHasKey('censoring_hours', $data['params']);
        $this->assertArrayHasKey('scam_type_filter', $data['params']);
        $this->assertArrayHasKey('noise_subject_patterns', $data['params']);
        $this->assertArrayHasKey('noise_sender_patterns', $data['params']);
        $this->assertArrayHasKey('honeypot_addresses', $data['params']);
    }

    public function testCensoringHoursDefaultsTo96_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT);
        $this->assertSame(96, $data['params']['censoring_hours']);
    }

    public function testCensoringHoursParamHonored_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT . '?censoring_hours=48');
        $this->assertSame(48, $data['params']['censoring_hours']);
    }

    public function testCensoringHoursParamClampedToReasonableRange_096C1(): void
    {
        // Negative or absurd values must not break the endpoint
        $data = $this->authenticatedGet(self::ENDPOINT . '?censoring_hours=-10');
        $this->assertGreaterThanOrEqual(0, $data['params']['censoring_hours']);

        $data = $this->authenticatedGet(self::ENDPOINT . '?censoring_hours=999999');
        $this->assertLessThanOrEqual(8760, $data['params']['censoring_hours']); // 1 year max
    }

    public function testFiltersByScamTypeWhenProvided_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT . '?scam_type=INVOICE_FRAUD');

        $this->assertSame('INVOICE_FRAUD', $data['params']['scam_type_filter']);
        // When scam_type is provided, by_scam_type contains 0 or 1 entry (the filter)
        $this->assertLessThanOrEqual(1, \count($data['by_scam_type']));
        if (\count($data['by_scam_type']) === 1) {
            $this->assertSame('INVOICE_FRAUD', $data['by_scam_type'][0]['scam_type']);
        }
    }

    public function testRateIsBetween0And100_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT);
        $rate = $data['global']['rate_pct'];
        $this->assertGreaterThanOrEqual(0, $rate);
        $this->assertLessThanOrEqual(100, $rate);
    }

    public function testByScamTypeEntriesHaveExpectedKeys_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT);

        if (\count($data['by_scam_type']) > 0) {
            $first = $data['by_scam_type'][0];
            $this->assertArrayHasKey('scam_type', $first);
            $this->assertArrayHasKey('observable', $first);
            $this->assertArrayHasKey('responded', $first);
            $this->assertArrayHasKey('rate_pct', $first);
        }
    }

    public function testByScamTypeSortedByObservableDesc_096C1(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT);

        if (\count($data['by_scam_type']) > 1) {
            $prev = PHP_INT_MAX;
            foreach ($data['by_scam_type'] as $row) {
                $this->assertLessThanOrEqual($prev, $row['observable']);
                $prev = $row['observable'];
            }
        }
    }

    // === period filter combines with scam_type ===

    public function testPeriodParamIsEchoedInResponse_096C2b(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT . '?period=30d');
        $this->assertSame('30d', $data['params']['period']);
    }

    public function testPeriodDefaultsToAll_096C2b(): void
    {
        $data = $this->authenticatedGet(self::ENDPOINT);
        $this->assertSame('all', $data['params']['period']);
    }

    public function testPeriodFilterReducesOrEqualsBaseline_096C2b(): void
    {
        $baseline = $this->authenticatedGet(self::ENDPOINT);
        $filtered = $this->authenticatedGet(self::ENDPOINT . '?period=7d');
        // 7-day window NEVER has more observable senders than the full dataset
        $this->assertLessThanOrEqual(
            $baseline['global']['observable'],
            $filtered['global']['observable'],
        );
    }

    public function testPeriodAndScamTypeCombined_096C2b(): void
    {
        // both filters must apply together (AND, not OR).
        $data = $this->authenticatedGet(self::ENDPOINT . '?period=30d&scam_type=PHISHING');
        $this->assertSame('30d', $data['params']['period']);
        $this->assertSame('PHISHING', $data['params']['scam_type_filter']);
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticatedGet(string $url): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertIsArray($data);

        return $data;
    }
}
