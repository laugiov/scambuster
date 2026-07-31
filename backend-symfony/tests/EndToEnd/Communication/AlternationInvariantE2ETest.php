<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the alternation invariant.
 *
 * Replays the exact 2026-05-11 bc13093d-… incident over the public HTTP surface:
 *   1. ingest one inbound,
 *   2. POST /reply/generate twice in succession,
 *   3. assert exactly one outbound exists in DB and the 2nd response carries
 *      duplicate_skipped=true with the SAME msg_id as the 1st.
 *
 * The whole flow uses real JWT and the same controllers n8n calls in production.
 */
class AlternationInvariantE2ETest extends WebTestCase
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

    /**
     * @return array{conv_id: string, msg_id: string}
     */
    private function seedConversationWithInbound($client, string $jwt): array
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode([
                'primary_channel_id' => $channel->getChannelId(),
                'scam_type_id' => $scamType->getScamTypeId(),
                'account_id' => $account->getAccountId(),
                'status' => 'open',
                'score_risk' => 75,
                'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-alt-e2e-' . bin2hex(random_bytes(4)),
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

        $client->request(
            'POST',
            '/api/v1/communication/message',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode([
                'conv_id' => $convId,
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'lang_detect' => 'en',
                'subject' => 'Hello from scammer',
                'body_text' => 'Please send funds.',
                'body_html' => '<p>Please send funds.</p>',
                'headers' => [
                    'from' => 'scammer@evil.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<inb-' . bin2hex(random_bytes(8)) . '@evil.test>',
                ],
                'composite_hash' => bin2hex(random_bytes(32)),
                'ts_msg' => (new \DateTimeImmutable('-30 minutes'))->format(DATE_ATOM),
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return ['conv_id' => $convId, 'msg_id' => $msgData['msg_id']];
    }

    private function countOutbounds(string $convId, $client): int
    {
        $em = $client->getContainer()->get('doctrine')->getManager();

        return (int) $em->createQueryBuilder()
            ->select('COUNT(m.msgId)')
            ->from(\App\Domain\Communication\Message::class, 'm')
            ->join('m.direction', 'd')
            ->where('m.conversation = :convId')
            ->andWhere('d.code = :out')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->setParameter('out', 'out')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function testDoubleGenerateOverHttpProducesSingleOutboundWithDuplicateSkippedFlag(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $seed = $this->seedConversationWithInbound($client, $jwt);

        // First /reply/generate — should create a brand-new outbound (HTTP 201).
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode([
                'conv_id' => $seed['conv_id'],
                'last_msg_id' => $seed['msg_id'],
                'force' => true,  // n8n always sends force=true in production
                'reason' => 'auto_draft_on_inbound',
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $first = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $first);
        $this->assertSame(1, $this->countOutbounds($seed['conv_id'], $client));

        // Second /reply/generate — Verrou A must suppress and return the SAME msg_id.
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode([
                'conv_id' => $seed['conv_id'],
                'last_msg_id' => $seed['msg_id'],
                'force' => true,
                'reason' => 'auto_draft_on_inbound',
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        $second = json_decode($client->getResponse()->getContent(), true);

        // Same msg_id, full success-shape payload, and the duplicate_skipped flag set.
        $this->assertSame($first['msg_id'], $second['msg_id']);
        $this->assertSame($first['conv_id'], $second['conv_id']);
        $this->assertSame($first['subject'], $second['subject']);
        $this->assertArrayHasKey('draft', $second);
        $this->assertArrayHasKey('text', $second['draft']);
        $this->assertArrayHasKey('html', $second['draft']);
        $this->assertArrayHasKey('meta', $second);
        $this->assertTrue((bool) ($second['meta']['duplicate_skipped'] ?? false));

        // Only one outbound persisted in DB.
        $this->assertSame(1, $this->countOutbounds($seed['conv_id'], $client));
    }
}
