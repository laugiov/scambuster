<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

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

    public function testDeleteAttachment(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $att = $em->getRepository('App\\Domain\\Communication\\Attachment')->findOneBy([]);
        $this->assertNotNull($att);
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
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $em = $client->getContainer()->get('doctrine')->getManager();
        $att = $em->getRepository('App\\Domain\\Communication\\Attachment')->findOneBy([]);
        $this->assertNotNull($att);
        $client->request('GET', '/api/v1/communication/attachment/' . $att->getAttachmentId() . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame($att->getMimeType(), $client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString($att->getFilename(), $client->getResponse()->headers->get('Content-Disposition'));
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
        $att = $em->getRepository('App\\Domain\\Communication\\Attachment')->findOneBy([]);
        $this->assertNotNull($att);
        // Soft-delete l'attachment
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
        // Upload 2 attachments
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
        // Vérifie qu'elles sont listées
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