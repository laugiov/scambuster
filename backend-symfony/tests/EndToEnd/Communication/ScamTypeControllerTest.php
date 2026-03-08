<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ScamTypeControllerTest extends WebTestCase
{
    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    public function testListScamTypesReturnsAllTypes(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request('GET', '/api/v1/communication/scam-types', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        // Check structure of first scam type (Sprint 3 structure)
        $this->assertArrayHasKey('code', $data[0]);
        $this->assertArrayHasKey('label', $data[0]);

        // Check that we have the expected scam types (Sprint 3 codes)
        $codes = array_column($data, 'code');
        $this->assertContains('unknown', $codes);
        $this->assertContains('PHISH_CREDENTIALS', $codes);
        $this->assertContains('INVOICE_FRAUD', $codes);
    }

    public function testScamTypesHaveCorrectPersonaMapping(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request('GET', '/api/v1/communication/scam-types', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        // Verify all scam types have required fields
        foreach ($data as $scamType) {
            $this->assertArrayHasKey('code', $scamType);
            $this->assertArrayHasKey('label', $scamType);
            $this->assertIsString($scamType['code']);
            $this->assertIsString($scamType['label']);
        }
    }
}
