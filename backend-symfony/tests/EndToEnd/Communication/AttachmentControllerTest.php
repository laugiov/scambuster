<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use App\Domain\Communication\Attachment;
use App\Domain\Communication\Message;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AttachmentControllerTest extends WebTestCase
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
     * Create a fresh test attachment to avoid cross-test pollution.
     */
    private function createTestAttachment($em): Attachment
    {
        $msg = $em->getRepository(Message::class)->findOneBy([]);
        $this->assertNotNull($msg, 'At least one message must exist in fixtures');

        $att = new Attachment(
            sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0x0FFF) | 0x4000, random_int(0, 0x3FFF) | 0x8000, random_int(0, 0xFFFFFFFFFFFF)),
            $msg,
            'e2e-test-file.pdf',
            'application/pdf',
            1234,
            bin2hex(random_bytes(32)),
            'attachments/e2e-test-file.pdf',
            null,
            'pending',
            null,
            null,
            new \DateTimeImmutable(),
            null
        );
        $em->persist($att);
        $em->flush();

        return $att;
    }

    public function testDeleteAttachment(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $att = $this->createTestAttachment($em);

        $client->request('DELETE', '/api/v1/communication/attachment/' . $att->getAttachmentId(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Attachment deleted', $data['message']);
    }

    public function testDeleteNonExistentAttachmentReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('DELETE', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000000', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadAttachment(): void
    {
        // Spec 065a/H1 — The download endpoint no longer returns the
        // FAKE_CONTENT placeholder. Until a real S3-compatible storage
        // backend is wired, every successfully resolved attachment
        // returns HTTP 501 Not Implemented with a documented JSON error
        // body. The previous assertions expecting 200 + binary content
        // are intentionally replaced.
        //
        // The fuller suite of download endpoint cases lives in
        // tests/EndToEnd/Communication/AttachmentDownloadTest.php.
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $att = $this->createTestAttachment($em);

        $client->request('GET', '/api/v1/communication/attachment/' . $att->getAttachmentId() . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertSame(501, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('Attachment storage backend not configured', $body['error'] ?? null);
        $this->assertSame('STORAGE_NOT_CONFIGURED', $body['code'] ?? null);
    }

    public function testDownloadNonExistentAttachmentReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('GET', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000000/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadSoftDeletedAttachmentReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $att = $this->createTestAttachment($em);

        // Soft-delete the attachment
        $reflection = new \ReflectionObject($att);
        $prop = $reflection->getProperty('deletedAt');
        $prop->setAccessible(true);
        $prop->setValue($att, new \DateTimeImmutable('-1 minute'));
        $em->flush();

        $client->request('GET', '/api/v1/communication/attachment/' . $att->getAttachmentId() . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testListConversationAttachments(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conv = $em->getRepository('App\\Domain\\Communication\\Conversation')->findOneBy([]);
        $this->assertNotNull($conv);
        $client->request('GET', '/api/v1/communication/attachment/conversation/' . $conv->getConvId() . '/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseIsSuccessful();
        $attachments = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($attachments);
    }

    public function testListAttachmentsNonExistentConversationReturns404(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $client->request('GET', '/api/v1/communication/attachment/conversation/00000000-0000-0000-0000-000000000000/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUploadMultipleAttachmentsOnSameMessage(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $msg = $em->getRepository('App\\Domain\\Communication\\Message')->findOneBy([]);
        $this->assertNotNull($msg);
        $conv = $msg->getConversation();
        for ($i = 1; $i <= 2; $i++) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($tmpFile, 'file' . $i);
            $client->request('POST', '/api/v1/communication/message/' . $msg->getMsgId() . '/attachments', [], [
                'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                    $tmpFile,
                    "test{$i}.txt",
                    'text/plain',
                    null,
                    true
                )
            ], [ 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt ]);
            $this->assertTrue(
                in_array($client->getResponse()->getStatusCode(), [201, 200]),
                'Expected 201 or 200 for attachment upload.'
            );
        }
        $client->request('GET', '/api/v1/communication/attachment/conversation/' . $conv->getConvId() . '/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseIsSuccessful();
        $attachments = json_decode($client->getResponse()->getContent(), true);
        $filenames = array_column($attachments, 'filename');
        $this->assertContains('test1.txt', $filenames);
        $this->assertContains('test2.txt', $filenames);
    }
}
