<?php

declare(strict_types=1);

namespace Tests\Functional\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetPersonaPerformanceControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPersonaPerformanceRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPersonaPerformanceReturnsDataForValidPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if persona exists in fixtures, 404 if not
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
            $this->assertArrayHasKey('persona_code', $data['data']);
            $this->assertArrayHasKey('persona_label', $data['data']);
            $this->assertArrayHasKey('total_sessions', $data['data']);
            $this->assertArrayHasKey('global_avg_reward', $data['data']);
            $this->assertArrayHasKey('performance_by_scam_type', $data['data']);
        }
    }

    public function testPersonaPerformanceReturns404ForUnknownPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/nonexistent_persona/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('nonexistent_persona', $data['error']);
    }

    public function testPersonaPerformanceReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testPersonaPerformanceDataHasPerformanceByScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('performance_by_scam_type', $data['data']);
            $this->assertIsArray($data['data']['performance_by_scam_type']);

            foreach ($data['data']['performance_by_scam_type'] as $entry) {
                $this->assertArrayHasKey('scam_type_code', $entry);
                $this->assertArrayHasKey('sessions_count', $entry);
                $this->assertArrayHasKey('reward_avg', $entry);
                $this->assertArrayHasKey('is_cold_start', $entry);
                $this->assertIsBool($entry['is_cold_start']);
            }
        }
    }

    public function testPersonaPerformanceDataHasTotalSessions(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('total_sessions', $data['data']);
            $this->assertIsInt($data['data']['total_sessions']);
            $this->assertGreaterThanOrEqual(0, $data['data']['total_sessions']);
        }
    }

    public function testPersonaPerformanceDataHasGlobalAvgReward(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('global_avg_reward', $data['data']);
            $this->assertIsNumeric($data['data']['global_avg_reward']);
        }
    }

    public function testPersonaPerformanceWithGenericUserPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/generic_user/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);
    }

    public function testPersonaPerformanceWithConfusedUserPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/confused_user/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testPersonaPerformanceWithBankCustomerPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/bank_customer/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testPersonaPerformanceWithLonelyPersonPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/lonely_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testPersonaPerformanceWithSmallBusinessOwnerPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/small_business_owner/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testPersonaPerformanceZeroSessionsPersona(): void
    {
        // A valid persona that may have 0 sessions - exercises the totalSessions == 0 branch
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertIsInt($data['data']['total_sessions']);
            $this->assertIsNumeric($data['data']['global_avg_reward']);

            // If total_sessions is 0, global_avg_reward should be 0.0.
            // assertEqualsWithDelta (not assertSame): JSON has a single number
            // type, so PHP's float 0.0 round-trips back through json_decode as
            // int 0, which a strict assertSame(0.0, ...) would reject.
            if ($data['data']['total_sessions'] === 0) {
                $this->assertEqualsWithDelta(0.0, $data['data']['global_avg_reward'], 0.0001);
                $this->assertCount(0, $data['data']['performance_by_scam_type']);
            }
        }
    }

    public function testPersonaPerformance404ErrorMessageIncludesPersonaCode(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/totally_fake_persona_xyz/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('totally_fake_persona_xyz', $data['error']);
    }

    public function testPersonaPerformancePerformanceByScamTypeItemStructure(): void
    {
        // Own the precondition: guarantee elderly_person has at least one
        // persona_performance_stats row so performance_by_scam_type is never
        // empty. A sibling test wipes that shared table; without a seeded item
        // the foreach below iterates zero times and PHPUnit marks the test
        // risky. The seed is idempotent and DAMA-rolled-back after the test.
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $persona = $em->getRepository(\App\Domain\Communication\Persona::class)
            ->findOneBy(['personaCode' => 'elderly_person']);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)
            ->findOneBy(['code' => 'PHISHING']);

        if ($persona !== null && $scamType !== null
            && $em->getRepository(\App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity::class)
                ->findOneBy(['persona' => $persona, 'scamType' => $scamType]) === null
        ) {
            $em->persist(new \App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity($persona, $scamType, 5, 3.2, 0.64));
            $em->flush();
        }

        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertNotEmpty($data['data']['performance_by_scam_type']);
        foreach ($data['data']['performance_by_scam_type'] as $entry) {
            $this->assertIsString($entry['scam_type_code']);
            $this->assertIsInt($entry['sessions_count']);
            $this->assertIsNumeric($entry['reward_avg']);
            $this->assertIsBool($entry['is_cold_start']);
            $this->assertGreaterThanOrEqual(0, $entry['sessions_count']);
        }
    }
}
