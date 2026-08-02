<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration test for IOC enrichment PATCH endpoint
 *
 * Tests the new PATCH /api/v1/iocs/{obs_id}/enrich endpoint
 * that allows n8n workflows to update enrichment data for IOCs.
 */
final class IocEnrichmentTest extends WebTestCase
{
    private $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Authenticate and get token
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'user@example.com',
                'password' => 'Un1que$trongPassword2024',
            ])
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->token = $data['access_token'];
    }

    public function testPatchIocEnrichment_InvalidObsId(): void
    {
        $enrichmentData = [
            'enrichment' => [
                'urlscan' => [
                    'status' => 'completed',
                    'verdict' => 'clean',
                ],
            ],
        ];

        $this->client->request(
            'PATCH',
            '/api/v1/iocs/00000000-0000-0000-0000-000000000000/enrich',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ],
            json_encode($enrichmentData)
        );

        // Assert 404 for non-existent IOC
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testPatchIocEnrichment_MissingEnrichmentField(): void
    {
        $this->client->request(
            'PATCH',
            '/api/v1/iocs/00000000-0000-0000-0000-000000000000/enrich',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ],
            json_encode(['invalid' => 'data'])
        );

        // Assert 400 for missing enrichment field
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('enrichment', $responseData['error']);
    }
}
