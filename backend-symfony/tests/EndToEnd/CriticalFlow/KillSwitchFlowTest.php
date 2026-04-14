<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

use App\Application\Communication\ReplyCadenceService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Flow 5: LLM kill switch — verify that admin can halt and resume reply generation.
 *
 * Verifies: kill switch read/toggle, reply generation succeeds when off,
 * reply generation blocked when on, and recovery after deactivation.
 */
final class KillSwitchFlowTest extends AbstractCriticalFlowTestCase
{
    private function clearKillSwitch(KernelBrowser $client): void
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = $client->getContainer()->get('cache.app');
        $cache->deleteItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
    }

    public function test_kill_switch_blocks_and_unblocks_reply_generation(): void
    {
        $client = static::createClient();
        $this->clearKillSwitch($client);

        $adminJwt = $this->getAdminJwt($client);
        $userJwt = $this->getJwt($client);

        // Step 1: Verify kill switch is inactive
        $client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt,
        ]);
        $this->assertResponseIsSuccessful();
        $ksData = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($ksData['active'], 'Kill switch should be inactive initially');

        // Step 2: Ingest an email (as regular user)
        $ingestResult = $this->ingestEmail(
            $client,
            $userJwt,
            'scammer-ks@evil.test',
            'Kill switch test email',
            'I need your help transferring funds urgently!',
        );
        $convId = $ingestResult['conv_id'];
        $msgId = $ingestResult['msg_id'];

        // Step 3: Generate reply (should work — kill switch off)
        $client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $userJwt,
        ], json_encode([
            'conv_id' => $convId,
            'last_msg_id' => $msgId,
            'force' => true,
            'reason' => 'killswitch_flow_test_before',
        ]));
        $this->assertResponseStatusCodeSame(201, 'Reply should succeed when kill switch is off');
        $replyBefore = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $replyBefore);

        // Wrap kill switch activation in try/finally to ensure cleanup even on failure.
        // Without this, a failing assertion leaves the switch ON and breaks subsequent tests.
        try {
            // Step 4: Activate kill switch
            $client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt,
            ], json_encode(['active' => true]));
            $this->assertResponseStatusCodeSame(200);
            $ksActive = json_decode($client->getResponse()->getContent(), true);
            $this->assertTrue($ksActive['active'], 'Kill switch should now be active');

            // Step 5: Verify kill switch is active via GET
            $client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt,
            ]);
            $ksVerify = json_decode($client->getResponse()->getContent(), true);
            $this->assertTrue($ksVerify['active']);

            // Step 6: Ingest another email for second attempt
            $ingestResult2 = $this->ingestEmail(
                $client,
                $userJwt,
                'scammer-ks2@evil.test',
                'Kill switch test email 2',
                'Another urgent request for funds!',
            );

            // Step 7: Try reply generation (should fail — kill switch on)
            $client->request('POST', '/api/v1/communication/reply/generate', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $userJwt,
            ], json_encode([
                'conv_id' => $ingestResult2['conv_id'],
                'last_msg_id' => $ingestResult2['msg_id'],
                'force' => true,
                'reason' => 'killswitch_flow_test_blocked',
            ]));
            $blockedStatus = $client->getResponse()->getStatusCode();
            $this->assertContains($blockedStatus, [400, 429, 503], 'Reply should be blocked when kill switch is on');
        } finally {
            // Step 8: Deactivate kill switch (always, even on failure)
            $this->clearKillSwitch($client);
        }

        // Step 9: Verify kill switch is off via API
        $client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $adminJwt,
        ], json_encode(['active' => false]));
        $this->assertResponseStatusCodeSame(200);
        $ksOff = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($ksOff['active'], 'Kill switch should now be inactive');

        // Step 10: Reply generation should work again
        $ingestResult3 = $this->ingestEmail(
            $client,
            $userJwt,
            'scammer-ks3@evil.test',
            'Kill switch test email 3',
            'Please help me with a transfer.',
        );

        $client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $userJwt,
        ], json_encode([
            'conv_id' => $ingestResult3['conv_id'],
            'last_msg_id' => $ingestResult3['msg_id'],
            'force' => true,
            'reason' => 'killswitch_flow_test_after',
        ]));
        $this->assertResponseStatusCodeSame(201, 'Reply should succeed after kill switch deactivated');
    }
}
