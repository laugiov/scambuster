<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;

class IngestControllerTest extends WebTestCase
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

    public function test_ingest_raw_e2e(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '44444444-4444-4444-4444-444444444444',
            'IMAP',
            'imap.example.com',
            'dummyhash3',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: E2E
From: "Tester" <foo@bar.com>
To: bar@foo.com
X-Custom: custom-value
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit

Hello E2E world!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 7.5, 'symbols' => ['E2E']],
            'score_risk' => 75
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Vérification du message en base
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');
        $this->assertSame('E2E', $message->getSubject());
        $this->assertSame('Hello E2E world!', trim($message->getBodyText()));
        $headers = $message->getHeaders();
        $this->assertSame('foo@bar.com', $headers['from'] ?? null);
        $this->assertSame('bar@foo.com', $headers['to'] ?? null);
        $this->assertSame('Thu, 23 May 2024 10:00:00 +0000', $headers['date'] ?? null);
        $this->assertSame($uniqueId . '@bar.com', $headers['message-id'] ?? null);
        $this->assertSame('custom-value', $headers['x-custom'] ?? null);
        // Nettoyage (supprimé, on laisse la base gérer comme dans les autres tests)
    }

    public function test_ingest_raw_with_exotic_headers(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '55555555-5555-5555-5555-555555555555',
            'IMAP',
            'imap.example.com',
            'dummyhash4',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Exotic headers
From: "Exotic" <exotic@bar.com>
To: bar@foo.com
Cc: cc@foo.com
Bcc: bcc@foo.com
Reply-To: reply@foo.com
X-Custom: custom-value
X-Emoji: =?UTF-8?Q?=F0=9F=98=8A?=
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit

Hello exotic headers!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 5.5, 'symbols' => ['EXOTIC']],
            'score_risk' => 55
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Vérification du message en base
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');
        $headers = $message->getHeaders();
        $this->assertSame('cc@foo.com', $headers['cc'] ?? null);
        $this->assertSame('bcc@foo.com', $headers['bcc'] ?? null);
        $this->assertSame('reply@foo.com', $headers['reply-to'] ?? null);
        $this->assertSame('custom-value', $headers['x-custom'] ?? null);
        $this->assertSame('😊', $headers['x-emoji'] ?? null);
    }

    public function test_ingest_raw_without_subject(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '66666666-6666-6666-6666-666666666666',
            'IMAP',
            'imap.example.com',
            'dummyhash5',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
From: "NoSubject" <nosubject@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

No subject here!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.1, 'symbols' => ['NOSUBJECT']],
            'score_risk' => 11
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertNull($message->getSubject());
        $this->assertSame('No subject here!', trim($message->getBodyText()));
    }

    public function test_ingest_raw_without_from(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '77777777-7777-7777-7777-777777777777',
            'IMAP',
            'imap.example.com',
            'dummyhash6',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: No From
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

No from header!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.2, 'symbols' => ['NOFROM']],
            'score_risk' => 12
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertNull($headers['from'] ?? null);
        $this->assertSame('No from header!', trim($message->getBodyText()));
    }

    public function test_ingest_raw_without_to(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '88888888-8888-8888-8888-888888888888',
            'IMAP',
            'imap.example.com',
            'dummyhash7',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: No To
From: "NoTo" <noto@bar.com>
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

No to header!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.3, 'symbols' => ['NOTO']],
            'score_risk' => 13
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertNull($headers['to'] ?? null);
        $this->assertSame('No to header!', trim($message->getBodyText()));
    }

    public function test_ingest_raw_without_date(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '99999999-9999-9999-9999-999999999999',
            'IMAP',
            'imap.example.com',
            'dummyhash8',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: No Date
From: "NoDate" <nodate@bar.com>
To: bar@foo.com
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

No date header!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.4, 'symbols' => ['NODATE']],
            'score_risk' => 14
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertNull($headers['date'] ?? null);
        $this->assertSame('No date header!', trim($message->getBodyText()));
    }

    public function test_ingest_raw_without_message_id(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '10101010-1010-1010-1010-101010101010',
            'IMAP',
            'imap.example.com',
            'dummyhash9',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: No Message-ID
From: "NoMsgId" <nomsgid@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

No message-id header!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.5, 'symbols' => ['NOMSGID']],
            'score_risk' => 15
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertNull($headers['message-id'] ?? null);
        $this->assertSame('No message-id header!', trim($message->getBodyText()));
    }

    public function test_ingest_raw_with_duplicated_headers(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111112-1111-1111-1111-111111111112',
            'IMAP',
            'imap.example.com',
            'dummyhash10',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Duplicated
Subject: Overwritten
From: "Dup" <dup@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Duplicated subject!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.1, 'symbols' => ['DUPLICATED']],
            'score_risk' => 21
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertSame('Duplicated', $message->getSubject());
        $this->assertSame('Duplicated subject!', $message->getBodyText());
    }

    public function test_ingest_raw_with_quoted_printable_body(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111113-1111-1111-1111-111111111113',
            'IMAP',
            'imap.example.com',
            'dummyhash11',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: QuotedPrintable
From: "QP" <qp@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

Hello=2C=20quoted-printable=20world=21
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.2, 'symbols' => ['QP']],
            'score_risk' => 22
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertSame('Hello, quoted-printable world!', trim($message->getBodyText()));
    }

    public function test_ingest_raw_with_html_only(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111114-1111-1111-1111-111111111114',
            'IMAP',
            'imap.example.com',
            'dummyhash12',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: HTML Only
From: "HTML" <html@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/html; charset=UTF-8

<html><body><b>Hello HTML only!</b></body></html>
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.3, 'symbols' => ['HTMLONLY']],
            'score_risk' => 23
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        // HTML is converted to text by convertHtmlToText in IngestHandler
        $this->assertSame('Hello HTML only!', $message->getBodyText());
        $this->assertStringContainsString('<b>Hello HTML only!</b>', $message->getBodyHtml());
    }

    public function test_ingest_raw_with_multipart(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111115-1111-1111-1111-111111111115',
            'IMAP',
            'imap.example.com',
            'dummyhash13',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $boundary = '----=_Part_12345_67890';
        $mailRaw = <<<MAIL
Subject: Multipart
From: "Multi" <multi@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="$boundary"

--$boundary
Content-Type: text/plain; charset=UTF-8

Hello multipart text!
--$boundary
Content-Type: text/html; charset=UTF-8

<b>Hello multipart HTML!</b>
--$boundary--
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.4, 'symbols' => ['MULTIPART']],
            'score_risk' => 24
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertSame('Hello multipart text!', trim($message->getBodyText()));
        $this->assertSame('<b>Hello multipart HTML!</b>', $message->getBodyHtml());
    }

    public function test_ingest_raw_with_nonstandard_line_endings(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111116-1111-1111-1111-111111111116',
            'IMAP',
            'imap.example.com',
            'dummyhash14',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        // Mélange \n et \r\n
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = "Subject: Nonstandard Line Endings\nFrom: 'NL' <nl@bar.com>\r\nTo: bar@foo.com\nDate: Thu, 23 May 2024 10:00:00 +0000\r\nMessage-ID: <{$uniqueId}@bar.com>\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\n\r\nBody with mixed line endings!\n";
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.5, 'symbols' => ['NL']],
            'score_risk' => 25
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString('Body with mixed line endings!', $message->getBodyText());
    }

    public function test_ingest_raw_with_corrupted_base64(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111117-1111-1111-1111-111111111117',
            'IMAP',
            'imap.example.com',
            'dummyhash15',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $payload = [
            'account_id' => $accountId,
            'raw_source' => '!!!not_base64!!!',
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.6, 'symbols' => ['CORRUPT']],
            'score_risk' => 26
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function test_ingest_raw_with_malformed_json(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '11111118-1111-1111-1111-111111111118',
            'IMAP',
            'imap.example.com',
            'dummyhash16',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $malformedJson = '{"account_id": "' . $accountId . '", "raw_source": "SGVsbG8gd29ybGQ=",'; // JSON tronqué
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], $malformedJson);
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function test_ingest_raw_with_multiple_reply_to(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            'dummyhash20',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Multiple Reply-To
From: "MultiReply" <multireply@bar.com>
To: bar@foo.com
Reply-To: reply1@foo.com
Reply-To: reply2@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with multiple Reply-To.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 3.1, 'symbols' => ['MULTIREPLY']],
            'score_risk' => 31
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertSame('reply2@foo.com', $headers['reply-to'] ?? null);
    }

    public function test_ingest_raw_with_multiple_to_cc_bcc(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '22222223-2222-2222-2222-222222222223',
            'IMAP',
            'imap.example.com',
            'dummyhash21',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Multiple To Cc Bcc
From: "MultiTo" <multito@bar.com>
To: to1@foo.com, to2@foo.com
Cc: cc1@foo.com, cc2@foo.com
Bcc: bcc1@foo.com, bcc2@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with multiple To, Cc, Bcc.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 3.2, 'symbols' => ['MULTITO']],
            'score_risk' => 32
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertSame('to1@foo.com', $headers['to'] ?? null);
        $this->assertSame('cc1@foo.com', $headers['cc'] ?? null);
        $this->assertSame('bcc1@foo.com', $headers['bcc'] ?? null);
    }

    public function test_ingest_raw_with_rfc2047_encoded_headers(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '22222224-2222-2222-2222-222222222224',
            'IMAP',
            'imap.example.com',
            'dummyhash22',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: =?UTF-8?B?U3ViamVjdCDwn5iK?=
From: =?UTF-8?B?8J+Yig==?= <emoji@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with emoji in subject and from.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 3.3, 'symbols' => ['RFC2047']],
            'score_risk' => 33
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertSame('Subject 😊', $message->getSubject());
        $this->assertSame('emoji@bar.com', $headers['from'] ?? null);
    }

    public function test_ingest_raw_with_body_only_spaces(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '22222225-2222-2222-2222-222222222225',
            'IMAP',
            'imap.example.com',
            'dummyhash23',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Only spaces
From: "Spaces" <spaces@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

     
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 3.4, 'symbols' => ['SPACES']],
            'score_risk' => 34
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertSame('', trim($message->getBodyText()));
    }

    public function test_ingest_raw_with_very_long_header(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333333-3333-3333-3333-333333333333',
            'IMAP',
            'imap.example.com',
            'dummyhash30',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        $longValue = str_repeat('A', 10000);
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Very long header
From: "Long" <long@bar.com>
To: bar@foo.com
X-Very-Long: $longValue
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with very long header.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.1, 'symbols' => ['LONGHEADER']],
            'score_risk' => 41
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertNotEmpty($headers['x-very-long'] ?? '', 'Header x-very-long should not be empty');
        $this->assertStringStartsWith(substr($longValue, 0, 100), $headers['x-very-long']);
        $this->assertGreaterThan(4000, strlen($headers['x-very-long']));
    }

    public function test_ingest_raw_with_special_characters_in_headers(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333334-3333-3333-3333-333333333334',
            'IMAP',
            'imap.example.com',
            'dummyhash31',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Spécial éèàçô 😀
From: "Été" <ete@bar.com>
To: bar@foo.com
X-Emoji: 😀
X-Accent: éèàçô
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with special characters in headers.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.2, 'symbols' => ['SPECIALHEADER']],
            'score_risk' => 42
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertSame('😀', $headers['x-emoji'] ?? null);
        $this->assertSame('éèàçô', $headers['x-accent'] ?? null);
        $this->assertSame('Spécial éèàçô 😀', $message->getSubject());
    }

    public function test_ingest_raw_with_duplicated_custom_header(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333335-3333-3333-3333-333333333335',
            'IMAP',
            'imap.example.com',
            'dummyhash32',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Duplicated custom header
From: "DupCustom" <dupcustom@bar.com>
To: bar@foo.com
X-Foo: first
X-Foo: second
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with duplicated custom header.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.3, 'symbols' => ['DUPCUSTOM']],
            'score_risk' => 43
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        // zbateson prend le dernier header dupliqué
        $this->assertSame('second', $headers['x-foo'] ?? null);
    }

    public function test_ingest_raw_with_reply_to_different_from(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333336-3333-3333-3333-333333333336',
            'IMAP',
            'imap.example.com',
            'dummyhash33',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Reply-To different
From: "Sender" <sender@bar.com>
To: bar@foo.com
Reply-To: reply@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with reply-to different from from.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.4, 'symbols' => ['REPLYTO']],
            'score_risk' => 44
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertSame('reply@foo.com', $headers['reply-to'] ?? null);
        $this->assertSame('sender@bar.com', $headers['from'] ?? null);
    }

    public function test_ingest_raw_with_special_characters_in_body(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333337-3333-3333-3333-333333333337',
            'IMAP',
            'imap.example.com',
            'dummyhash34',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Special chars in body
From: "BodySpecial" <bodyspecial@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Ceci est un body avec des accents éèàçô et des emoji 😀🚀.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.5, 'symbols' => ['BODYCHAR']],
            'score_risk' => 45
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertStringContainsString('éèàçô', $message->getBodyText());
        $this->assertStringContainsString('😀', $message->getBodyText());
        $this->assertStringContainsString('🚀', $message->getBodyText());
    }

    public function test_ingest_raw_with_empty_header(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333338-3333-3333-3333-333333333338',
            'IMAP',
            'imap.example.com',
            'dummyhash35',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Empty header
From: "Empty" <empty@bar.com>
To: bar@foo.com
X-Empty:
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Mail with empty header.
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.6, 'symbols' => ['EMPTYHEADER']],
            'score_risk' => 46
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $headers = $message->getHeaders();
        $this->assertNull($headers['x-empty'] ?? null);
    }

    public function test_ingest_raw_with_iso_8859_1_header(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '33333339-3333-3333-3333-333333333339',
            'IMAP',
            'imap.example.com',
            'dummyhash36',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);
        // Header encodé ISO-8859-1 (ex: é)
        $uniqueId = uniqid('e2e-', true);
        $mailRaw = "Subject: =?ISO-8859-1?Q?Caf=E9?=\nFrom: cafe@bar.com\nTo: bar@foo.com\nDate: Thu, 23 May 2024 10:00:00 +0000\nMessage-ID: <{$uniqueId}@bar.com>\nMIME-Version: 1.0\nContent-Type: text/plain; charset=ISO-8859-1\n\nMail with ISO-8859-1 header.\n";
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 4.7, 'symbols' => ['ISO88591']],
            'score_risk' => 47
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertSame('Café', $message->getSubject());
    }

    public function test_ingest_raw_duplicate_mail_is_not_inserted_twice(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new \App\Domain\Communication\MailAccount(
            $accountId,
            '44444444-4444-4444-4444-444444444444',
            'IMAP',
            'imap.example.com',
            'dummyhash3',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Duplicate
From: "Tester" <foo@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Hello duplicate world!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 7.5, 'symbols' => ['E2E']],
            'score_risk' => 75
        ];
        // Première ingestion
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data1 = json_decode($client->getResponse()->getContent(), true);
        // Vérification qu'il n'y a qu'un seul message en base avec ce composite_hash
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message1 = $messageRepo->find($data1['msg_id']);
        $this->assertNotNull($message1);
        $compositeHash = $message1->getCompositeHash();
        $qb = $em->createQueryBuilder();
        $qb->select('m')
            ->from(\App\Domain\Communication\Message::class, 'm')
            ->where('m.compositeHash = :hash')
            ->setParameter('hash', $compositeHash);
        $results = $qb->getQuery()->getResult();
        $this->assertCount(1, $results, 'Only one message should exist for the same mail');
    }

    public function test_ingest_multiple_mails_same_account_creates_one_conversation(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new \App\Domain\Communication\MailAccount(
            $accountId,
            '55555555-5555-5555-5555-555555555555',
            'IMAP',
            'imap.example.com',
            'dummyhash4',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        // Premier mail (original)
        $uniqueId1 = uniqid('e2e-multi-1-', true);
        $mailRaw1 = <<<MAIL
Subject: Mail 1
From: "SameSender" <same@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId1}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Hello mail 1!
MAIL;

        // Deuxième mail (réponse)
        $uniqueId2 = uniqid('e2e-multi-2-', true);
        $mailRaw2 = <<<MAIL
Subject: Re: Mail 1
From: "SameSender" <same@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 11:00:00 +0000
Message-ID: <{$uniqueId2}@bar.com>
In-Reply-To: <{$uniqueId1}@bar.com>
References: <{$uniqueId1}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Reply to mail 1!
MAIL;

        // Envoyer le premier mail
        $payload1 = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw1),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0],
            'score_risk' => 10
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload1));
        $this->assertResponseStatusCodeSame(201);
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $conv1 = $em->getRepository(\App\Domain\Communication\Conversation::class)
            ->find($data1['conv_id']);

        // Envoyer le deuxième mail
        $payload2 = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw2),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0],
            'score_risk' => 10
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload2));
        $this->assertResponseStatusCodeSame(201);
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $conv2 = $em->getRepository(\App\Domain\Communication\Conversation::class)
            ->find($data2['conv_id']);

        // Les deux messages doivent être dans la même conversation car ils sont liés
        $this->assertSame($conv1->getConvId(), $conv2->getConvId(), 'Les messages liés doivent être dans la même conversation');
        $this->assertSame($conv1->getStixId(), $conv2->getStixId(), 'Les messages liés doivent avoir le même stixId');

        // Vérifier que le reply_to_msg_id est correctement lié
        $msg2 = $em->getRepository(\App\Domain\Communication\Message::class)
            ->find($data2['msg_id']);
        $this->assertNotNull($msg2->getReplyTo(), 'Le message de réponse doit avoir un reply_to');
        $this->assertSame($data1['msg_id'], $msg2->getReplyTo()->getMsgId(), 'Le reply_to doit pointer vers le message original');
    }

    public function test_ingest_mail_with_references_joins_conversation(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new \App\Domain\Communication\MailAccount(
            $accountId,
            '66666666-6666-6666-6666-666666666666',
            'IMAP',
            'imap.example.com',
            'dummyhash5',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        // Premier mail (original)
        $uniqueId1 = uniqid('e2e-ref-1-', true);
        $mailRaw1 = <<<MAIL
Subject: Original Thread
From: "ThreadSender" <thread@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId1}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Original message!
MAIL;

        // Deuxième mail (avec référence mais pas in-reply-to)
        $uniqueId2 = uniqid('e2e-ref-2-', true);
        $mailRaw2 = <<<MAIL
Subject: New Subject
From: "ThreadSender" <thread@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 11:00:00 +0000
Message-ID: <{$uniqueId2}@bar.com>
References: <{$uniqueId1}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

New message in thread!
MAIL;

        // Envoyer le premier mail
        $payload1 = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw1),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0],
            'score_risk' => 10
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload1));
        $this->assertResponseStatusCodeSame(201);
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $conv1 = $em->getRepository(\App\Domain\Communication\Conversation::class)
            ->find($data1['conv_id']);

        // Envoyer le deuxième mail
        $payload2 = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw2),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0],
            'score_risk' => 10
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload2));
        $this->assertResponseStatusCodeSame(201);
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $conv2 = $em->getRepository(\App\Domain\Communication\Conversation::class)
            ->find($data2['conv_id']);

        // Les deux messages doivent être dans la même conversation car ils sont liés par references
        $this->assertSame($conv1->getConvId(), $conv2->getConvId(), 'Les messages liés par references doivent être dans la même conversation');
        $this->assertSame($conv1->getStixId(), $conv2->getStixId(), 'Les messages liés par references doivent avoir le même stixId');

        // Vérifier que le reply_to_msg_id est null car il n'y a pas de in-reply-to
        $msg2 = $em->getRepository(\App\Domain\Communication\Message::class)
            ->find($data2['msg_id']);
        $this->assertNull($msg2->getReplyTo(), 'Le message avec uniquement references ne doit pas avoir de reply_to');
    }

    public function test_ingest_unrelated_mails_create_separate_conversations(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new \App\Domain\Communication\MailAccount(
            $accountId,
            '77777777-7777-7777-7777-777777777777',
            'IMAP',
            'imap.example.com',
            'dummyhash6',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        // Premier mail (non lié)
        $uniqueId1 = uniqid('e2e-unrelated-1-', true);
        $mailRaw1 = <<<MAIL
Subject: Unrelated 1
From: "Unrelated" <unrelated1@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId1}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Unrelated message 1!
MAIL;

        // Deuxième mail (non lié)
        $uniqueId2 = uniqid('e2e-unrelated-2-', true);
        $mailRaw2 = <<<MAIL
Subject: Unrelated 2
From: "Unrelated" <unrelated2@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 11:00:00 +0000
Message-ID: <{$uniqueId2}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Unrelated message 2!
MAIL;

        // Envoyer le premier mail
        $payload1 = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw1),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0],
            'score_risk' => 10
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload1));
        $this->assertResponseStatusCodeSame(201);
        $data1 = json_decode($client->getResponse()->getContent(), true);
        $conv1 = $em->getRepository(\App\Domain\Communication\Conversation::class)
            ->find($data1['conv_id']);

        // Envoyer le deuxième mail
        $payload2 = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw2),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0],
            'score_risk' => 10
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload2));
        $this->assertResponseStatusCodeSame(201);
        $data2 = json_decode($client->getResponse()->getContent(), true);
        $conv2 = $em->getRepository(\App\Domain\Communication\Conversation::class)
            ->find($data2['conv_id']);

        // Les messages non liés doivent être dans des conversations différentes
        $this->assertNotSame($conv1->getConvId(), $conv2->getConvId(), 'Les messages non liés doivent être dans des conversations différentes');
        $this->assertNotSame($conv1->getStixId(), $conv2->getStixId(), 'Les messages non liés doivent avoir des stixIds différents');
    }

    public function test_ingest_raw_with_raw_source_rfc822_b64(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '77777777-7777-7777-7777-777777777777',
            'IMAP',
            'imap.example.com',
            'dummyhash7',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: RFC822
From: "RFC822" <rfc822@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Hello RFC822!
MAIL;
        $payload = [
            'account_id' => $accountId,
            'raw_source_rfc822_b64' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.2, 'symbols' => ['RFC822']],
            'score_risk' => 22
        ];
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Vérification du message en base
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');
        $this->assertSame(base64_encode($mailRaw), $message->getRawSource());
    }

    public function test_ingest_raw_with_attachments(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '44444444-4444-4444-4444-444444444444',
            'IMAP',
            'imap.example.com',
            'dummyhash3',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-', true);
        $mailRaw = <<<MAIL
Subject: Test with attachments
From: "Test" <test@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="boundary123"

--boundary123
Content-Type: text/plain; charset=UTF-8

Test message with attachments.

--boundary123
Content-Type: application/pdf
Content-Disposition: attachment; filename="test.pdf"

%PDF-1.4
--boundary123--
MAIL;

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 5.0, 'symbols' => ['ATTACH']],
            'score_risk' => 50,
            'attachments' => [
                [
                    'filename' => 'test.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024,
                    'sha256' => hash('sha256', 'test content'),
                    'strelka' => [
                        'yara_hits' => ['Phishing_PDF'],
                        'ssdeep' => '3:abcdef:ghijkl',
                        'tlsh' => 'T1234567890abcdef'
                    ],
                    'sandbox' => [
                        'score' => 3,
                        'network' => ['185.220.101.4:443'],
                        'dropped' => ['c0ffee...sha256']
                    ]
                ]
            ]
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Vérification du message et des pièces jointes en base
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');

        // Vérifier que les métadonnées des PJ sont bien stockées
        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);

        $attachment = $attachments[0];
        $this->assertSame('test.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getMimeType());
        $this->assertSame(1024, $attachment->getSizeBytes());
        $this->assertSame(hash('sha256', 'test content'), $attachment->getContentHash());

        // Vérifier que les résultats d'analyse sont bien stockés
        $metadata = $attachment->getMetadata();
        $this->assertArrayHasKey('strelka', $metadata);
        $this->assertArrayHasKey('sandbox', $metadata);
        $this->assertSame(['Phishing_PDF'], $metadata['strelka']['yara_hits']);
        $this->assertSame(3, $metadata['sandbox']['score']);
    }

    /**
     * Spec 063 — Backend-side parser fallback.
     *
     * Verifies that when the upstream collector (n8n) does not pre-populate
     * `dto.attachments`, the backend extracts attachments by parsing the
     * raw RFC822 body itself, using EmailParsingService::extractAttachments().
     *
     * Regression context: from 2026-03-31 (commit b090e31, Gmail->IMAP
     * migration), n8n stopped extracting attachments and forwards
     * `attachments: []`. The backend used to silently no-op. After this
     * spec, the backend extracts them itself from raw_source.
     */
    public function test_ingest_raw_fallback_extracts_attachments_when_dto_empty(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '55555555-5555-5555-5555-555555555555',
            'IMAP',
            'imap.example.com',
            'dummyhash063a',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $pdfBytes = "%PDF-1.4\nspec 063 fallback test pdf\n%%EOF";
        $pdfB64 = chunk_split(base64_encode($pdfBytes), 76, "\n");
        $expectedSha256 = hash('sha256', $pdfBytes);
        $expectedSize = strlen($pdfBytes);

        $uniqueId = uniqid('e2e-063a-', true);
        $mailRaw = <<<MAIL
Subject: Spec 063 fallback test
From: "Scammer" <scammer@bar.com>
To: bar@foo.com
Date: Thu, 10 Apr 2026 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="bnd063a"

--bnd063a
Content-Type: text/plain; charset=UTF-8

See the attached PDF.

--bnd063a
Content-Type: application/pdf
Content-Disposition: attachment; filename="fallback.pdf"
Content-Transfer-Encoding: base64

{$pdfB64}
--bnd063a--
MAIL;

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 5.0, 'symbols' => ['ATTACH']],
            'score_risk' => 50,
            // CRITICAL: empty attachments array triggers the parser fallback
            'attachments' => [],
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Verify that the attachment was extracted by the parser fallback and persisted
        $em->clear();
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');

        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments, 'Parser fallback should have extracted 1 attachment');

        $attachment = $attachments[0];
        $this->assertSame('fallback.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getMimeType());
        $this->assertSame($expectedSize, $attachment->getSizeBytes());
        $this->assertSame($expectedSha256, $attachment->getContentHash());
    }

    /**
     * Spec 063 — Multi-attachment parser fallback.
     */
    public function test_ingest_raw_fallback_extracts_three_attachments_when_dto_empty(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '66666666-6666-6666-6666-666666666666',
            'IMAP',
            'imap.example.com',
            'dummyhash063b',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $pdfBytes = "%PDF-1.4\npdf1 spec 063b\n%%EOF";
        $docxBytes = "PK\x03\x04docx1 spec 063b";
        $zipBytes = "PK\x03\x04zip1 spec 063b";

        $pdfB64 = chunk_split(base64_encode($pdfBytes), 76, "\n");
        $docxB64 = chunk_split(base64_encode($docxBytes), 76, "\n");
        $zipB64 = chunk_split(base64_encode($zipBytes), 76, "\n");

        $uniqueId = uniqid('e2e-063b-', true);
        $mailRaw = <<<MAIL
Subject: Three attachments fallback
From: "Scammer" <scammer@bar.com>
To: bar@foo.com
Date: Thu, 10 Apr 2026 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="bnd063b"

--bnd063b
Content-Type: text/plain; charset=UTF-8

Three files attached.

--bnd063b
Content-Type: application/pdf
Content-Disposition: attachment; filename="report.pdf"
Content-Transfer-Encoding: base64

{$pdfB64}
--bnd063b
Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document
Content-Disposition: attachment; filename="contract.docx"
Content-Transfer-Encoding: base64

{$docxB64}
--bnd063b
Content-Type: application/zip
Content-Disposition: attachment; filename="archive.zip"
Content-Transfer-Encoding: base64

{$zipB64}
--bnd063b--
MAIL;

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 5.0, 'symbols' => ['ATTACH']],
            'score_risk' => 50,
            'attachments' => [],
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);

        $em->clear();
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $attachments = $message->getAttachments();

        $this->assertCount(3, $attachments, 'Parser fallback should have extracted 3 attachments');

        $byName = [];

        foreach ($attachments as $att) {
            $byName[$att->getFilename()] = $att;
        }

        $this->assertArrayHasKey('report.pdf', $byName);
        $this->assertArrayHasKey('contract.docx', $byName);
        $this->assertArrayHasKey('archive.zip', $byName);
        $this->assertSame(hash('sha256', $pdfBytes), $byName['report.pdf']->getContentHash());
        $this->assertSame(hash('sha256', $docxBytes), $byName['contract.docx']->getContentHash());
        $this->assertSame(hash('sha256', $zipBytes), $byName['archive.zip']->getContentHash());
    }

    /**
     * Spec 063 — Regression sentinel: when dto.attachments IS populated, the
     * parser fallback MUST NOT be invoked. The DTO array takes precedence
     * even if the raw_source contains parser-extractable attachments.
     *
     * This guards against accidentally double-counting or losing producer
     * metadata (strelka, sandbox).
     */
    public function test_ingest_raw_fallback_not_invoked_when_dto_has_attachments(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '77777777-7777-7777-7777-777777777777',
            'IMAP',
            'imap.example.com',
            'dummyhash063c',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $pdfBytes = "%PDF-1.4\nfallback should NOT be triggered\n%%EOF";
        $pdfB64 = chunk_split(base64_encode($pdfBytes), 76, "\n");

        // The raw mail contains a parser-extractable attachment
        $uniqueId = uniqid('e2e-063c-', true);
        $mailRaw = <<<MAIL
Subject: DTO precedence
From: "Scammer" <scammer@bar.com>
To: bar@foo.com
Date: Thu, 10 Apr 2026 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="bnd063c"

--bnd063c
Content-Type: text/plain; charset=UTF-8

Body.

--bnd063c
Content-Type: application/pdf
Content-Disposition: attachment; filename="from-raw-source.pdf"
Content-Transfer-Encoding: base64

{$pdfB64}
--bnd063c--
MAIL;

        // BUT the DTO declares a different attachment with marker metadata.
        // The fallback must NOT be invoked → only the DTO entry is persisted.
        $dtoSha256 = hash('sha256', 'declared by DTO marker');
        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 5.0, 'symbols' => ['ATTACH']],
            'score_risk' => 50,
            'attachments' => [
                [
                    'filename' => 'from-dto.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 999,
                    'sha256' => $dtoSha256,
                    'strelka' => ['yara_hits' => ['DTO_marker']],
                ],
            ],
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);

        $em->clear();
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $attachments = $message->getAttachments();

        $this->assertCount(1, $attachments, 'Only the DTO-declared attachment should be persisted, not the parser fallback one');

        $attachment = $attachments[0];
        $this->assertSame('from-dto.pdf', $attachment->getFilename(), 'The DTO entry must take precedence over the parser fallback');
        $this->assertSame($dtoSha256, $attachment->getContentHash());

        // Verify the strelka marker survived (proves the DTO array was used, not the parser)
        $metadata = $attachment->getMetadata();
        $this->assertNotNull($metadata, 'DTO metadata should be persisted');
        $this->assertArrayHasKey('strelka', $metadata);
        $this->assertSame(['DTO_marker'], $metadata['strelka']['yara_hits']);
    }

    /**
     * Spec 063 — Defensive: garbage raw_source must not break ingestion.
     */
    public function test_ingest_raw_fallback_handles_garbage_raw_source_gracefully(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '88888888-8888-8888-8888-888888888888',
            'IMAP',
            'imap.example.com',
            'dummyhash063d',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        // Build a "minimally valid" RFC822 with no multipart, no attachments,
        // and a body that is not base64-encoded multipart content.
        // The parser should successfully parse the headers + body but find
        // zero attachments. No exception, message persisted normally.
        $uniqueId = uniqid('e2e-063d-', true);
        $mailRaw = <<<MAIL
Subject: Plain mail no attachments
From: "Scammer" <scammer@bar.com>
To: bar@foo.com
Date: Thu, 10 Apr 2026 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
Content-Type: text/plain; charset=UTF-8

Just a plain text body, no attachments.
MAIL;

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 0.0, 'symbols' => []],
            'score_risk' => 50,
            'attachments' => [],
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);

        $em->clear();
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message);
        $this->assertCount(0, $message->getAttachments(), 'Plain text mail should have zero attachments');
    }

    public function test_ingest_raw_with_raw_headers_b64(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'IMAP',
            'imap.example.com',
            'dummyhash_raw_headers_b64',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-raw-headers-', true);
        $mailRaw = <<<MAIL
Subject: Test raw_headers_b64
From: "TestB64" <testb64@bar.com>
To: bar@foo.com
X-Custom-Header: custom-value
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Test with raw_headers_b64!
MAIL;

        // Extraire seulement les headers
        $parts = explode("\n\n", $mailRaw, 2);
        $rawHeaders = $parts[0];

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'raw_headers_b64' => base64_encode($rawHeaders),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 1.0, 'symbols' => ['TEST']],
            'score_risk' => 10
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Vérification en base
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');

        // Vérifier que raw_headers est bien stocké et décodé
        $headers = $message->getHeaders();
        $this->assertArrayHasKey('raw_headers', $headers);
        $this->assertStringContainsString('Subject: Test raw_headers_b64', $headers['raw_headers']);
        $this->assertStringContainsString('X-Custom-Header: custom-value', $headers['raw_headers']);
    }

    public function test_ingest_raw_with_parsed_field(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'IMAP',
            'imap.example.com',
            'dummyhash_parsed',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-parsed-', true);
        $mailRaw = <<<MAIL
Subject: Test parsed field
From: "TestParsed" <testparsed@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>

Body with URL: https://example.com and https://phishing-site.com
Contact us at support@example.com or admin@test.com
Server IP: 192.168.1.100
MAIL;

        $parsed = [
            'headers' => [
                'from' => 'testparsed@bar.com',
                'to' => ['bar@foo.com'],
                'subject' => 'Test parsed field',
                'message_id' => "<{$uniqueId}@bar.com>",
                'date' => '2024-05-23T10:00:00Z',
            ],
            'body' => [
                'text' => 'Body with URL: https://example.com and https://phishing-site.com',
                'has_attachments' => false,
                'attachment_count' => 0,
            ],
            'iocs' => [
                'urls' => [
                    'https://example.com',
                    'https://phishing-site.com',
                ],
                'domains' => [
                    'example.com',
                    'phishing-site.com',
                ],
                'email_addresses' => [
                    'support@example.com',
                    'admin@test.com',
                ],
                'ip_addresses' => [
                    '192.168.1.100',
                ],
            ],
            'metadata' => [
                'has_html' => false,
                'has_text' => true,
                'parsing_timestamp' => '2024-05-23T10:00:00Z',
            ],
        ];

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'parsed' => $parsed,
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 2.0, 'symbols' => ['PHISHING_URL']],
            'score_risk' => 75,
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $msgId = $data['msg_id'];

        $message = $em->getRepository(\App\Domain\Communication\Message::class)->find($msgId);
        $this->assertNotNull($message);
        $this->assertEquals('Test parsed field', $message->getSubject());

        $headers = $message->getHeaders();
        $this->assertArrayHasKey('parsed', $headers);

        // Vérifier la structure du champ parsed
        $parsedStored = $headers['parsed'];
        $this->assertIsArray($parsedStored);
        $this->assertArrayHasKey('headers', $parsedStored);
        $this->assertArrayHasKey('iocs', $parsedStored);

        // Vérifier les IOCs extraits
        $this->assertArrayHasKey('urls', $parsedStored['iocs']);
        $this->assertContains('https://example.com', $parsedStored['iocs']['urls']);
        $this->assertContains('https://phishing-site.com', $parsedStored['iocs']['urls']);

        $this->assertArrayHasKey('email_addresses', $parsedStored['iocs']);
        $this->assertContains('support@example.com', $parsedStored['iocs']['email_addresses']);
        $this->assertContains('admin@test.com', $parsedStored['iocs']['email_addresses']);

        $this->assertArrayHasKey('ip_addresses', $parsedStored['iocs']);
        $this->assertContains('192.168.1.100', $parsedStored['iocs']['ip_addresses']);

        // Vérifier les domaines
        $this->assertArrayHasKey('domains', $parsedStored['iocs']);
        $this->assertContains('example.com', $parsedStored['iocs']['domains']);
        $this->assertContains('phishing-site.com', $parsedStored['iocs']['domains']);
    }

    public function test_ingest_raw_with_url_analysis(): void
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'IMAP',
            'imap.example.com',
            'dummyhash_url_analysis',
            ['mail.read'],
            true
        );
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Message m')->execute();
        $em->createQuery('DELETE FROM App\\Domain\\Communication\\Conversation c')->execute();

        $jwt = $this->getValidJwt($client);

        $uniqueId = uniqid('e2e-url-analysis-', true);
        $mailRaw = <<<MAIL
Subject: Test URL Analysis
From: "TestURLAnalysis" <testurlanalysis@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>

Check this link: https://suspicious-phishing-site.com
MAIL;

        // Simulate merged URL analysis report from URLScan + VirusTotal
        $urlAnalysis = [
            'data' => [
                'id' => 'u-724bd2ea6631c3f41143af583bcd7253808f2f32a5a0d6ab10f7c5893965cf73-022a89ad',
                'type' => 'analysis',
                'requests' => [
                    [
                        'request' => [
                            'url' => 'https://suspicious-phishing-site.com',
                            'method' => 'GET',
                        ],
                        'response' => [
                            'status' => 200,
                        ],
                    ],
                ],
                'attributes' => [
                    'stats' => [
                        'malicious' => 15,
                        'suspicious' => 5,
                        'harmless' => 70,
                        'undetected' => 0,
                        'timeout' => 0,
                    ],
                    'status' => 'completed',
                    'date' => 1760241846,
                    'results' => [
                        'Kaspersky' => [
                            'category' => 'malicious',
                            'result' => 'phishing',
                            'method' => 'blacklist',
                        ],
                        'Google Safebrowsing' => [
                            'category' => 'malicious',
                            'result' => 'phishing',
                            'method' => 'blacklist',
                        ],
                    ],
                ],
            ],
            'verdicts' => [
                'overall' => [
                    'score' => 85,
                    'malicious' => true,
                    'categories' => ['phishing', 'credential-theft'],
                    'brands' => ['PayPal'],
                    'tags' => ['urlscan-ml'],
                ],
                'urlscan' => [
                    'score' => 80,
                    'malicious' => true,
                ],
                'engines' => [
                    'score' => 85,
                    'malicious' => true,
                    'maliciousTotal' => 15,
                    'benignTotal' => 70,
                    'enginesTotal' => 90,
                ],
            ],
            'stats' => [
                'ipStats' => [
                    [
                        'ip' => '185.15.59.224',
                        'asn' => [
                            'asn' => '14907',
                            'country' => 'US',
                            'description' => 'SUSPICIOUS-HOSTING, US',
                        ],
                        'geoip' => [
                            'country' => 'US',
                            'city' => 'New York',
                        ],
                    ],
                ],
                'domainStats' => [
                    [
                        'domain' => 'suspicious-phishing-site.com',
                        'count' => 5,
                    ],
                ],
                'malicious' => 1,
            ],
            'meta' => [
                'url_info' => [
                    'id' => '724bd2ea6631c3f41143af583bcd7253808f2f32a5a0d6ab10f7c5893965cf73',
                    'url' => 'https://suspicious-phishing-site.com',
                ],
            ],
        ];

        $payload = [
            'account_id' => $accountId,
            'raw_source' => base64_encode($mailRaw),
            'url_analysis' => $urlAnalysis,
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 8.5, 'symbols' => ['PHISHING_URL', 'MALICIOUS_LINK']],
            'score_risk' => 85,
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertSame('ingested', $data['status']);

        // Vérification en base
        $messageRepo = $em->getRepository(\App\Domain\Communication\Message::class);
        $message = $messageRepo->find($data['msg_id']);
        $this->assertNotNull($message, 'Message should be persisted');

        // Vérifier que url_analysis est bien stocké dans le champ dédié
        $storedUrlAnalysis = $message->getUrlAnalysis();
        $this->assertNotNull($storedUrlAnalysis, 'URL analysis should be stored');
        $this->assertIsArray($storedUrlAnalysis);

        // Vérifier la structure du rapport fusionné
        $this->assertArrayHasKey('data', $storedUrlAnalysis);
        $this->assertArrayHasKey('verdicts', $storedUrlAnalysis);
        $this->assertArrayHasKey('stats', $storedUrlAnalysis);
        $this->assertArrayHasKey('meta', $storedUrlAnalysis);

        // Vérifier les verdicts
        $verdicts = $storedUrlAnalysis['verdicts'];
        $this->assertArrayHasKey('overall', $verdicts);
        $this->assertEquals(85, $verdicts['overall']['score']);
        $this->assertTrue($verdicts['overall']['malicious']);
        $this->assertContains('phishing', $verdicts['overall']['categories']);

        // Vérifier les statistiques VirusTotal
        $vtStats = $storedUrlAnalysis['data']['attributes']['stats'];
        $this->assertEquals(15, $vtStats['malicious']);
        $this->assertEquals(5, $vtStats['suspicious']);
        $this->assertEquals(70, $vtStats['harmless']);

        // Vérifier les résultats des moteurs
        $this->assertArrayHasKey('results', $storedUrlAnalysis['data']['attributes']);
        $this->assertArrayHasKey('Kaspersky', $storedUrlAnalysis['data']['attributes']['results']);
        $this->assertEquals('phishing', $storedUrlAnalysis['data']['attributes']['results']['Kaspersky']['result']);

        // Vérifier les métadonnées
        $this->assertEquals('https://suspicious-phishing-site.com', $storedUrlAnalysis['meta']['url_info']['url']);

        // Vérifier que le message lui-même est correct
        $this->assertEquals('Test URL Analysis', $message->getSubject());
        $this->assertStringContainsString('https://suspicious-phishing-site.com', $message->getBodyText());
    }
} 