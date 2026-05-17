<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

use App\Domain\Communication\MailAccount;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for critical end-to-end flow tests.
 *
 * Provides helpers for JWT acquisition and email ingestion via the /ingest/raw endpoint.
 * Creates a fresh MailAccount per test to avoid per-account rate limiting when the
 * full E2E suite runs many ingest calls.
 */
abstract class AbstractCriticalFlowTestCase extends WebTestCase
{
    private ?string $testAccountId = null;

    /**
     * Defensive cleanup: ensure no stale totp_secret on the shared fixture
     * users blocks login with `requires_2fa: true`. AuthLifecycleFlowTest is
     * supposed to clear this in its tearDown, but if its tearDown ever
     * crashes (it has, see 2026-05-12 CI incident), every subsequent
     * CriticalFlow test fails with 401 on /ingest/raw.
     *
     * Raw SQL on purpose — going through the ORM would hydrate the user
     * and decrypt totp_secret, which is itself what crashed last time.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $container = static::getContainer();
            $conn = $container->get('doctrine.dbal.default_connection');

            if ($conn instanceof Connection) {
                $conn->executeStatement(
                    "UPDATE app_users SET totp_secret = NULL WHERE email IN ('user@example.com', 'admin@example.com') AND totp_secret IS NOT NULL",
                );
            }
        } catch (\Throwable) {
            // Best-effort: tests will fail naturally if a leak actually happened.
        } finally {
            // The cleanup above booted the kernel via static::getContainer().
            // The tests then call static::createClient(), which refuses to
            // re-boot. Shut the kernel down here so createClient gets a clean
            // slate.
            static::ensureKernelShutdown();
        }
    }

    protected function getJwt(KernelBrowser $client, string $email = 'user@example.com'): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => $email,
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    protected function getAdminJwt(KernelBrowser $client): string
    {
        return $this->getJwt($client, 'admin@example.com');
    }

    /**
     * Returns the account_id for ingest, creating a fresh MailAccount if needed.
     */
    protected function getAccountId(KernelBrowser $client): string
    {
        if ($this->testAccountId !== null) {
            return $this->testAccountId;
        }

        $this->testAccountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $this->testAccountId,
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.criticalflow.test',
            'criticalflow-' . bin2hex(random_bytes(4)),
            ['mail.read'],
            true,
        );

        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mailAccount);
        $em->flush();

        return $this->testAccountId;
    }

    /**
     * Ingest an RFC822 email via POST /api/v1/communication/ingest/raw.
     *
     * @return array{msg_id: string, conv_id: string, status: string}
     */
    protected function ingestEmail(
        KernelBrowser $client,
        string $jwt,
        string $from,
        string $subject,
        string $body,
        ?string $inReplyTo = null,
        ?string $messageId = null,
    ): array {
        $messageId ??= '<' . bin2hex(random_bytes(16)) . '@test.local>';
        $rfc822 = $this->buildRfc822($from, 'honeypot@scambuster.test', $subject, $body, $messageId, $inReplyTo);

        $payload = [
            'account_id' => $this->getAccountId($client),
            'raw_source' => base64_encode($rfc822),
            'ts_received' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'channel' => 'email',
            'rspamd' => ['score' => 7.5, 'symbols' => ['CRITICAL_FLOW_TEST']],
            'score_risk' => 75,
        ];

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201, 'Ingest should return 201');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);

        return $data;
    }

    protected function buildRfc822(
        string $from,
        string $to,
        string $subject,
        string $body,
        ?string $messageId = null,
        ?string $inReplyTo = null,
    ): string {
        $messageId ??= '<' . bin2hex(random_bytes(16)) . '@test.local>';
        $date = (new \DateTimeImmutable())->format('r');

        $headers = "Subject: {$subject}\r\n";
        $headers .= "From: {$from}\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Date: {$date}\r\n";
        $headers .= "Message-ID: {$messageId}\r\n";
        if ($inReplyTo !== null) {
            $headers .= "In-Reply-To: {$inReplyTo}\r\n";
            $headers .= "References: {$inReplyTo}\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        return $headers . "\r\n" . $body;
    }
}
