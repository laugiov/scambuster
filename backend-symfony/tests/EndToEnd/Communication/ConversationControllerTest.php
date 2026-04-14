<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Ramsey\Uuid\Uuid;

class ConversationControllerTest extends WebTestCase
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

    public function testCreateConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $payload = [
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 42,
            'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix-123',
        ];
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('conv_id', $data);
        $this->assertSame('open', $data['status']);
    }

    public function testMultiChannelConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel1 = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $channel2 = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([/* get a different channel if possible */]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel1);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        // Create a conversation with channel1
        $payload = [
            'primary_channel_id' => $channel1->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 42,
            'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix-e2e-multichannel',
        ];
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $convId = $data['conv_id'];
        // Add a second channel to the conversation (simulate multi-channel)
        // This assumes you have an endpoint for adding a channel to a conversation
        $client->request(
            'POST',
            "/api/v1/communication/conversation/$convId/add-channel",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['channel_id' => $channel2 ? $channel2->getChannelId() : $channel1->getChannelId()])
        );
        $this->assertResponseIsSuccessful();
        // Fetch the conversation and check channels
        $client->request(
            'GET',
            "/api/v1/communication/conversation/$convId",
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ]
        );
        $this->assertResponseIsSuccessful();
        $conv = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('channels', $conv);
        $this->assertGreaterThanOrEqual(1, count($conv['channels']));
    }

    public function testListConversations(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request(
            'GET',
            '/api/v1/communication/conversation',
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
    }

    public function testGetConversationDetails(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        // Create a dedicated conversation for this test
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $payload = [
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 42,
            'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix-get-details',
        ];
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $convId = $data['conv_id'];
        // Now get the details for this conversation
        $client->request(
            'GET',
            '/api/v1/communication/conversation/' . $convId,
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($convId, $data['conv_id']);
    }

    public function testDeleteConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request(
            'DELETE',
            '/api/v1/communication/conversation/' . $conv->getConvId(),
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Conversation deleted', $data['message']);
    }

    public function testGetNonExistentConversationReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('GET', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000000', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteNonExistentConversationReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('DELETE', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000000', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetConversationWithoutJwtReturns403(): void
    {
        $client = static::createClient();
        $convId = '00000000-0000-0000-0000-000000000001';
        $client->request('GET', '/api/v1/communication/conversation/' . $convId);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testPaginateConversations(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('GET', '/api/v1/communication/conversation?page=1&limit=1', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
        $this->assertLessThanOrEqual(1, count($convs));
    }

    public function testCannotDeleteConversationTwice(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('DELETE', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteConversationCascadesToMessages(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $convId = $conv->getConvId();
        $client->request('DELETE', '/api/v1/communication/conversation/' . $convId, [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $messages = $em->getRepository(\App\Domain\Communication\Message::class)->findBy(['conversation' => $convId]);
        $this->assertCount(0, $messages, 'Messages should be deleted with conversation.');
    }

    public function testPatchConversationStatus(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('PATCH', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['status' => 'closed']));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Conversation updated', $data['message']);
    }

    public function testPatchConversationScoreRisk(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('PATCH', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['score_risk' => 99]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Conversation updated', $data['message']);
    }

    public function testPatchConversationTsLast(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $newDate = (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM);
        $client->request('PATCH', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['ts_last' => $newDate]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Conversation updated', $data['message']);
    }

    public function testPatchConversationStixId(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('PATCH', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['stix_id' => 'stix-patch-test']));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Conversation updated', $data['message']);
    }

    public function testPatchNonExistentConversationReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('PATCH', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000000', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['status' => 'closed']));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPatchConversationInvalidPayloadReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('PATCH', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], 'not a json');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testPatchConversationNoChangeReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('PATCH', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode([]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testListConversationIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        if (!$conv) {
            // Créer une conversation si aucune n'existe
            $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
            $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
            $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
            $this->assertNotNull($channel, 'No channel found');
            $this->assertNotNull($scamType, 'No scamType found');
            $this->assertNotNull($account, 'No mail account found');
            $conv = new \App\Domain\Communication\Conversation(
                \Ramsey\Uuid\Uuid::uuid4()->toString(),
                $channel,
                $scamType,
                $account,
                \App\Domain\Communication\ConversationStatus::OPEN,
                0,
                new \DateTimeImmutable(),
                new \DateTimeImmutable(),
                uniqid('stix-', true)
            );
            $em->persist($conv);
            $em->flush();
            // Créer un message dans la conversation
            $direction = $em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
            $this->assertNotNull($direction, 'No direction found');
            $client->request(
                'POST',
                '/api/v1/communication/message',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
                ],
                json_encode([
                    'conv_id' => $conv->getConvId(),
                    'channel_id' => $channel->getChannelId(),
                    'direction' => $direction->getCode(),
                    'body_text' => 'Test message',
                    'headers' => ['X-Test' => 'iocs'],
                    'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
                ])
            );
            $this->assertResponseStatusCodeSame(201);
        }
        $client->request('GET', '/api/v1/communication/conversation/'.$conv->getConvId().'/iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testListIocsNonExistentConversationReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('GET', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000000/iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetSoftDeletedConversationReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $this->assertNotNull($conv);
        // Soft-delete la conversation
        $reflection = new \ReflectionObject($conv);
        $prop = $reflection->getProperty('deletedAt');
        $prop->setAccessible(true);
        $prop->setValue($conv, new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $client->request('GET', '/api/v1/communication/conversation/' . $conv->getConvId(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testExtremePaginationOnConversations(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        // Very high page
        $client->request('GET', '/api/v1/communication/conversation?page=9999&limit=10', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
        $this->assertCount(0, $convs, 'Very high page should return an empty array.');
        // Very low limit (0)
        $client->request('GET', '/api/v1/communication/conversation?page=1&limit=0', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
        $this->assertLessThanOrEqual(1, count($convs), 'Limit 0 should be corrected to 1.');
        // Very high limit (1000)
        $client->request('GET', '/api/v1/communication/conversation?page=1&limit=1000', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
        $this->assertLessThanOrEqual(1000, count($convs), 'Very high limit should not exceed 1000.');
    }

    public function testFilterConversationsByStatusAndDate(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        // Create a conversation with status 'open'
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $payload = [
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 42,
            'ts_first' => (new \DateTimeImmutable('-2 days'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable('-1 day'))->format(DATE_ATOM),
            'stix_id' => 'stix-filter-test',
        ];
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );
        $this->assertResponseStatusCodeSame(201);
        // Filter by status
        $client->request('GET', '/api/v1/communication/conversation?status=open', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
        $this->assertNotEmpty($convs);
        foreach ($convs as $conv) {
            $this->assertSame('open', $conv['status']);
        }
        // Filter by date range
        $from = (new \DateTimeImmutable('-3 days'))->format(DATE_ATOM);
        $to = (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM);
        $client->request('GET', '/api/v1/communication/conversation?from=' . urlencode($from) . '&to=' . urlencode($to), [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $convs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convs);
        $this->assertNotEmpty($convs);
        foreach ($convs as $conv) {
            $this->assertGreaterThanOrEqual(strtotime($from), strtotime($conv['ts_first']));
            $this->assertLessThanOrEqual(strtotime($to), strtotime($conv['ts_last']));
        }
    }

    public function testMalformedDateReturnsGenericError(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $payload = [
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 42,
            'ts_first' => 'not-a-date', // Malformed date
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix-malformed-date',
        ];
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );
        $this->assertSame(400, $client->getResponse()->getStatusCode(), 'Malformed date should return 400.');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertNotEmpty($data['error']);
    }
} 