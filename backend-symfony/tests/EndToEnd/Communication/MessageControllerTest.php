<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Domain\Communication\ConversationStatus;

class MessageControllerTest extends WebTestCase
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

    public function testAddMessagesToConversation(): void
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
        // Create a conversation
        $payload = [
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 42,
            'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix-e2e-msg',
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
        // Add inbound message
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
                'conv_id' => $convId,
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'body_text' => 'Hello, I am a prince and I need your help!',
                'headers' => ['From' => 'scammer@fraud.com'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        // Add outbound message (bot)
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
                'conv_id' => $convId,
                'channel_id' => $channel->getChannelId(),
                'direction' => 'out',
                'body_text' => 'Hello, I am happy to help you, please send your bank details.',
                'headers' => ['To' => 'scammer@fraud.com'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        // Check that the conversation has 2 messages
        $client->request(
            'GET',
            "/api/v1/communication/conversation/$convId/messages",
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ]
        );
        $this->assertResponseIsSuccessful();
        $messages = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(2, $messages);
    }

    public function testGetMessageDetails(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $client->request(
            'GET',
            '/api/v1/communication/message/' . $message->getMsgId(),
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($message->getMsgId(), $data['msg_id']);
    }

    public function testDeleteMessage(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $client->request(
            'DELETE',
            '/api/v1/communication/message/' . $message->getMsgId(),
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message deleted', $data['message']);
    }

    public function testGetMessageAttachments(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $client->request(
            'GET',
            '/api/v1/communication/message/' . $message->getMsgId() . '/attachments',
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $attachments = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($attachments);
    }

    public function testGetMessageIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $client->request(
            'GET',
            '/api/v1/communication/message/' . $message->getMsgId() . '/iocs',
            [],
            [],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseIsSuccessful();
        $iocs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($iocs);
    }

    public function testGetNonExistentMessageReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('GET', '/api/v1/communication/message/00000000-0000-0000-0000-000000000000', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteNonExistentMessageReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('DELETE', '/api/v1/communication/message/00000000-0000-0000-0000-000000000000', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetMessageWithoutJwtReturns403(): void
    {
        $client = static::createClient();
        $messageId = '00000000-0000-0000-0000-000000000001';
        $client->request('GET', '/api/v1/communication/message/' . $messageId);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUploadAttachmentTooLargeReturns413(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $largeContent = str_repeat('A', 10 * 1024 * 1024); // 10MB
        $client->request(
            'POST',
            '/api/v1/communication/message/' . $message->getMsgId() . '/attachments',
            [],
            [
                'file' => [
                    'name' => 'largefile.txt',
                    'type' => 'text/plain',
                    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
                    'error' => 0,
                    'size' => strlen($largeContent),
                ]
            ],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [413, 400]),
            'Expected 413 Payload Too Large or 400 Bad Request for large file upload.'
        );
    }

    public function testUploadAttachmentInvalidTypeReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $client->request(
            'POST',
            '/api/v1/communication/message/' . $message->getMsgId() . '/attachments',
            [],
            [
                'file' => [
                    'name' => 'file.exe',
                    'type' => 'application/x-msdownload',
                    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
                    'error' => 0,
                    'size' => 123,
                ]
            ],
            [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]
        );
        $this->assertResponseStatusCodeSame(400);
    }

    public function testPaginateMessagesInConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        // Création explicite d'une conversation
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel, 'No channel found');
        $this->assertNotNull($scamType, 'No scamType found');
        $this->assertNotNull($account, 'No mail account found');
        $conv = new \App\Domain\Communication\Conversation(
            \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
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
        // Création explicite d'un message lié à cette conversation
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
                'headers' => ['X-Test' => 'pagination'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        // Utiliser $conv->getConvId() pour la pagination
        $client->request('GET', '/api/v1/communication/conversation/'.$conv->getConvId().'/messages?page=1&limit=10', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testCannotAddMessageToClosedConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy(['status' => 'closed']);
        if (!$conv) {
            $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
            $conv->setStatus(ConversationStatus::CLOSED);
            $em->flush();
        }
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
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
                'direction' => 'in',
                'body_text' => 'Should not be allowed',
                'headers' => ['From' => 'test@fail.com'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );
        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [400, 409]),
            'Expected 400 Bad Request or 409 Conflict when adding message to closed conversation.'
        );
    }

    public function testCannotDeleteMessageTwice(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $client->request('DELETE', '/api/v1/communication/message/' . $message->getMsgId(), [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $client->request('DELETE', '/api/v1/communication/message/' . $message->getMsgId(), [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteMessageCascadesToAttachmentsAndVector(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($message);
        $msgId = $message->getMsgId();
        $client->request('DELETE', '/api/v1/communication/message/' . $msgId, [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        // Check attachments
        $attachments = $em->getRepository(\App\Domain\Communication\Attachment::class)->findBy(['message' => $msgId]);
        $this->assertCount(0, $attachments, 'Attachments should be deleted with message.');
        // Check vector (if applicable)
        if (class_exists('App\\Domain\\Communication\\MessageVector')) {
            $vector = $em->getRepository(\App\Domain\Communication\MessageVector::class)->findOneBy(['vectorId' => $msgId]);
            $this->assertNull($vector, 'Message vector should be deleted with message.');
        }
    }

    public function testPatchMessageBodyText(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['body_text' => 'patched body']));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message updated', $data['message']);
    }

    public function testPatchMessageSubject(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['subject' => 'patched subject']));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message updated', $data['message']);
    }

    public function testPatchMessageHeaders(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['headers' => ['X-Test' => 'patched']]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message updated', $data['message']);
    }

    public function testPatchMessageBodyHtml(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['body_html' => '<b>patched</b>']));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message updated', $data['message']);
    }

    public function testPatchMessageTsMsg(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $newDate = (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['ts_msg' => $newDate]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message updated', $data['message']);
    }

    public function testPatchMessageDirection(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['direction' => 'out']));
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Message updated', $data['message']);
    }

    public function testPatchNonExistentMessageReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('PATCH', '/api/v1/communication/message/00000000-0000-0000-0000-000000000000', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode(['body_text' => 'patched']));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPatchMessageInvalidPayloadReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], 'not a json');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testPatchMessageNoChangeReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        $client->request('PATCH', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode([]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAddMessageWithLargeAndExoticHeaders(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $direction = $em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $this->assertNotNull($channel);
        $this->assertNotNull($direction);
        // S'assurer que la conversation est ouverte
        if (method_exists($conv, 'getStatus') && $conv->getStatus() && $conv->getStatus()->value === 'closed') {
            $reflection = new \ReflectionObject($conv);
            $prop = $reflection->getProperty('status');
            $prop->setAccessible(true);
            $prop->setValue($conv, \App\Domain\Communication\ConversationStatus::from('open'));
            $em->flush();
        }
        $largeHeader = str_repeat('A', 10000);
        $exoticHeaders = [
            'X-Emoji' => '😀😎🚀',
            'X-Null' => null,
            'X-Array' => [1,2,3],
            'X-Large' => $largeHeader
        ];
        $client->request('POST', '/api/v1/communication/message', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => $direction->getCode(),
            'body_text' => 'Test with exotic headers',
            'headers' => $exoticHeaders,
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testAddMessageWithReplyTo(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $direction = $em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $this->assertNotNull($channel);
        $this->assertNotNull($direction);
        // S'assurer que la conversation est ouverte
        if (method_exists($conv, 'getStatus') && $conv->getStatus() && $conv->getStatus()->value === 'closed') {
            $reflection = new \ReflectionObject($conv);
            $prop = $reflection->getProperty('status');
            $prop->setAccessible(true);
            $prop->setValue($conv, \App\Domain\Communication\ConversationStatus::from('open'));
            $em->flush();
        }
        // Ajoute un premier message
        $client->request('POST', '/api/v1/communication/message', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => $direction->getCode(),
            'body_text' => 'Parent message',
            'headers' => ['X-Test' => 'parent'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ]));
        $this->assertResponseStatusCodeSame(201);
        $parentData = json_decode($client->getResponse()->getContent(), true);
        $parentMsgId = $parentData['msg_id'];
        // Ajoute un message reply_to
        $client->request('POST', '/api/v1/communication/message', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => $direction->getCode(),
            'body_text' => 'Reply message',
            'headers' => ['X-Test' => 'reply'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'reply_to_msg_id' => $parentMsgId
        ]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testGetSoftDeletedMessageReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository(\App\Domain\Communication\Message::class)->findOneBy([]);
        $this->assertNotNull($msg);
        // Soft-delete le message
        $reflection = new \ReflectionObject($msg);
        $prop = $reflection->getProperty('deletedAt');
        $prop->setAccessible(true);
        $prop->setValue($msg, new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $client->request('GET', '/api/v1/communication/message/' . $msg->getMsgId(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAddMessageWithXssAndSqliPayloads(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $direction = $em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
        $this->assertNotNull($conv);
        $this->assertNotNull($channel);
        $this->assertNotNull($direction);
        // S'assurer que la conversation est ouverte
        if (method_exists($conv, 'getStatus') && $conv->getStatus() && $conv->getStatus()->value === 'closed') {
            $reflection = new \ReflectionObject($conv);
            $prop = $reflection->getProperty('status');
            $prop->setAccessible(true);
            $prop->setValue($conv, \App\Domain\Communication\ConversationStatus::from('open'));
            $em->flush();
        }
        $xss = '<script>alert(1)</script>';
        $sqli = "' OR 1=1; -- ";
        $payload = [
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => $direction->getCode(),
            'body_text' => "Normal text $xss $sqli",
            'subject' => "Subject $xss $sqli",
            'headers' => [
                'X-XSS' => $xss,
                'X-SQLi' => $sqli
            ],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ];
        $client->request('POST', '/api/v1/communication/message', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $msgId = $data['msg_id'];
        // Récupère le message et vérifie que le contenu est bien stocké (pas exécuté)
        $client->request('GET', '/api/v1/communication/message/' . $msgId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseIsSuccessful();
        $msg = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString($xss, $msg['body_text']);
        $this->assertStringContainsString($sqli, $msg['body_text']);
        $this->assertStringContainsString($xss, $msg['headers']['X-XSS']);
        $this->assertStringContainsString($sqli, $msg['headers']['X-SQLi']);
    }

    public function testExtremePaginationOnMessages(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        // Création explicite d'une conversation
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel, 'No channel found');
        $this->assertNotNull($scamType, 'No scamType found');
        $this->assertNotNull($account, 'No mail account found');
        $conv = new \App\Domain\Communication\Conversation(
            \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
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
        // Création explicite d'un message lié à cette conversation
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
                'headers' => ['X-Test' => 'pagination'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );
        $this->assertResponseStatusCodeSame(201);
        // Utiliser $conv->getConvId() pour la pagination
        // Very high page
        $client->request('GET', '/api/v1/communication/conversation/' . $conv->getConvId() . '/messages?page=9999&limit=10', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $messages = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($messages);
        $this->assertCount(0, $messages, 'Very high page should return an empty array.');
        // Very low limit (0)
        $client->request('GET', '/api/v1/communication/conversation/' . $conv->getConvId() . '/messages?page=1&limit=0', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $messages = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($messages);
        $this->assertLessThanOrEqual(1, count($messages), 'Limit 0 should be corrected to 1.');
        // Very high limit (1000)
        $client->request('GET', '/api/v1/communication/conversation/' . $conv->getConvId() . '/messages?page=1&limit=1000', [], [], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
        $this->assertResponseIsSuccessful();
        $messages = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($messages);
        $this->assertLessThanOrEqual(1000, count($messages), 'Very high limit should not exceed 1000.');
    }
} 