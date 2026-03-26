<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ConversationLifecycleControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testConversationLifecycleRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConversationLifecycleReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('active', $data);
        $this->assertArrayHasKey('about_to_timeout', $data);
        $this->assertArrayHasKey('completed_today', $data);
        $this->assertArrayHasKey('reopened_today', $data);
        $this->assertArrayHasKey('by_scam_type', $data);
        $this->assertArrayHasKey('about_to_timeout_list', $data);
    }

    public function testConversationLifecycleActiveCountIsInteger(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsInt($data['active']);
        $this->assertIsInt($data['about_to_timeout']);
        $this->assertIsInt($data['completed_today']);
    }

    public function testConversationLifecycleReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testConversationLifecycleAboutToTimeoutListIsArray(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['about_to_timeout_list']);

        // Each item in the list should have expected fields
        foreach ($data['about_to_timeout_list'] as $item) {
            $this->assertArrayHasKey('conv_id', $item);
            $this->assertArrayHasKey('scam_type', $item);
            $this->assertArrayHasKey('persona', $item);
            $this->assertArrayHasKey('last_activity', $item);
            $this->assertArrayHasKey('timeout_hours', $item);
            $this->assertArrayHasKey('hours_remaining', $item);
        }
    }

    public function testConversationLifecycleByScamTypeIsObject(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        // by_scam_type is cast to (object), so it decodes as array
        $this->assertIsArray($data['by_scam_type']);
    }

    public function testConversationLifecycleByScamTypeValuesHaveCountAndPolicy(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['by_scam_type'] as $scamTypeCode => $value) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey('active', $value);
            $this->assertArrayHasKey('about_to_timeout', $value);
            $this->assertArrayHasKey('policy_timeout_hours', $value);
            $this->assertArrayHasKey('max_turns', $value);
            $this->assertIsInt($value['active']);
            $this->assertIsInt($value['about_to_timeout']);
            $this->assertIsInt($value['policy_timeout_hours']);
        }
    }

    public function testConversationLifecycleReopenedTodayIsInteger(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsInt($data['reopened_today']);
    }

    public function testConversationLifecycleWithAdminAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('active', $data);
        $this->assertArrayHasKey('about_to_timeout', $data);
    }

    public function testConversationLifecycleCountsAreNonNegative(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(0, $data['active']);
        $this->assertGreaterThanOrEqual(0, $data['about_to_timeout']);
        $this->assertGreaterThanOrEqual(0, $data['completed_today']);
        $this->assertGreaterThanOrEqual(0, $data['reopened_today']);
    }

    public function testConversationLifecycleAboutToTimeoutListItemsHaveHoursRemaining(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['about_to_timeout_list'] as $item) {
            $this->assertIsNumeric($item['hours_remaining']);
            $this->assertIsInt($item['timeout_hours']);
            $this->assertIsString($item['conv_id']);
            $this->assertIsString($item['scam_type']);
            $this->assertIsString($item['persona']);
        }
    }
}
