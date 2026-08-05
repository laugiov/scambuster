<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * downloadAttachment must NOT return FAKE_CONTENT.
 *
 * Until a real S3-compatible storage backend is wired (future spec), the
 * download endpoint MUST return HTTP 501 Not Implemented with a documented
 * JSON error body. The legacy `tempnam(...) + 'FAKE_CONTENT'` path is
 * removed.
 *
 * Pre-existing behaviors preserved:
 *   - 404 when the attachment ID does not exist
 *   - 404 when the attachment is soft-deleted (deletedAt is non-null)
 *
 * Test seeding strategy: each test uses the production `/ingest/raw`
 * pipeline to create a real Attachment row, then exercises the download
 * endpoint. This mirrors the pattern used by `IngestControllerTest` and
 * avoids manual wiring of `Channel`, `ScamType`, and `Conversation`.
 */
class AttachmentDownloadTest extends WebTestCase
{
    private function getValidJwt(KernelBrowser $client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    /**
     * Seed a MailAccount and ingest one mail with one PDF attachment via the
     * real `/ingest/raw` pipeline. Returns [client, em, jwt, attachmentId].
     *
     * @return array{0: KernelBrowser, 1: EntityManagerInterface, 2: string, 3: string}
     */
    private function seedAttachment(string $accountUuid, string $tenantUuid, string $hashSeed): array
    {
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            $tenantUuid,
            'IMAP',
            'imap.example.com',
            $hashSeed,
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

        $pdfBytes = "%PDF-1.4\n065a download test " . $hashSeed . "\n%%EOF";
        $pdfB64 = chunk_split(base64_encode($pdfBytes), 76, "\n");

        $uniqueId = uniqid('e2e-065a-', true);
        $mailRaw = <<<MAIL
Subject: Spec 065a download test
From: "Sender" <sender@bar.com>
To: bar@foo.com
Date: Fri, 11 Apr 2026 10:00:00 +0000
Message-ID: <{$uniqueId}@bar.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="bnd065a"

--bnd065a
Content-Type: text/plain; charset=UTF-8

See the attached PDF.

--bnd065a
Content-Type: application/pdf
Content-Disposition: attachment; filename="download-test.pdf"
Content-Transfer-Encoding: base64

{$pdfB64}
--bnd065a--
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

        $this->assertResponseStatusCodeSame(201, 'Ingestion failed in test setup');
        $data = json_decode($client->getResponse()->getContent(), true);
        $msgId = $data['msg_id'];

        // Retrieve the persisted attachment id from the database
        $em->clear();
        $row = $em->getConnection()->fetchAssociative(
            'SELECT attachment_id FROM attachment WHERE msg_id = :msgId LIMIT 1',
            ['msgId' => $msgId]
        );
        $this->assertIsArray($row, 'Test setup expected one attachment row');
        $attachmentId = (string) $row['attachment_id'];

        return [$client, $em, $jwt, $attachmentId];
    }

    public function test_download_returns_501_when_storage_not_configured(): void
    {
        [$client, , $jwt, $attachmentId] = $this->seedAttachment(
            uuid_create(UUID_TYPE_RANDOM),
            '11111111-aaaa-aaaa-aaaa-111111111111',
            'dummy065a1'
        );

        $client->request('GET', '/api/v1/communication/attachment/' . $attachmentId . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertSame(501, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('Attachment storage backend not configured', $body['error'] ?? null);
        $this->assertSame('STORAGE_NOT_CONFIGURED', $body['code'] ?? null);
    }

    public function test_download_returns_404_when_attachment_not_found(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $randomUuid = uuid_create(UUID_TYPE_RANDOM);
        $client->request('GET', '/api/v1/communication/attachment/' . $randomUuid . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('Attachment not found', $body['error'] ?? null);
    }

    public function test_download_returns_404_when_attachment_soft_deleted(): void
    {
        [$client, $em, $jwt, $attachmentId] = $this->seedAttachment(
            uuid_create(UUID_TYPE_RANDOM),
            '22222222-bbbb-bbbb-bbbb-222222222222',
            'dummy065a3'
        );

        // Soft-delete via direct SQL: production code uses hard-delete via
        // AttachmentHandler::deleteAttachment(), but the controller still
        // honors the deletedAt field if it is set externally (e.g., by a
        // future spec that introduces real soft-delete on attachments).
        $em->getConnection()->executeStatement(
            'UPDATE attachment SET deleted_at = NOW() WHERE attachment_id = :id',
            ['id' => $attachmentId]
        );

        $client->request('GET', '/api/v1/communication/attachment/' . $attachmentId . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('Attachment not found', $body['error'] ?? null);
    }

    public function test_download_response_body_is_valid_json_on_error(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Trigger the 404 not-found path; the test asserts the body parses
        // as JSON regardless of which error path is taken.
        $randomUuid = uuid_create(UUID_TYPE_RANDOM);
        $client->request('GET', '/api/v1/communication/attachment/' . $randomUuid . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $content = (string) $client->getResponse()->getContent();
        $this->assertNotSame('FAKE_CONTENT', $content, 'FAKE_CONTENT must never be returned');

        $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('error', $body);
    }
}
