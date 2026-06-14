<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\HeaderIocExtractor;
use App\Application\Communication\IocCategorizer;
use App\Application\Communication\IocExportMapper;
use App\Application\Communication\IocUpsertService;
use App\Application\Communication\RiskScorer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 061 — Sprint 1 — Task 1.5
 *
 * Even when extraction runs legitimately on an incoming message
 * (direction='in'), if a scammer quotes our reply, our honeypot address would
 * be re-extracted as a "scammer IOC". This test asserts that the upsert path
 * rejects any email IOC whose normalised value matches the configured
 * honeypot addresses.
 *
 * Source of canonical addresses: env var HONEYPOT_EMAIL_ADDRESSES, exposed
 * via Symfony parameter and injected into IocUpsertService constructor.
 */
final class IocUpsertServiceHoneypotFilterTest extends KernelTestCase
{
    private const HONEYPOT_ADDRESS = 'honeypot-test-spec061@example.com';
    private const INCOMING_MSG_ID = '00000000-0000-0000-0000-000000000001';

    /**
     * @param list<string>      $honeypotAddresses
     * @param list<string>|null $honeypotDomains   Spec 098 — explicit owned
     *                                             domains. If null, derive
     *                                             from the addresses by
     *                                             splitting on '@' (test-only
     *                                             convenience: tests that
     *                                             pre-date Spec 098 keep the
     *                                             pre-fix semantics).
     */
    private function buildServiceWithHoneypot(array $honeypotAddresses, ?array $honeypotDomains = null): IocUpsertService
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        if ($honeypotDomains === null) {
            $honeypotDomains = [];

            foreach ($honeypotAddresses as $a) {
                $at = strrpos($a, '@');

                if ($at !== false && $at < strlen($a) - 1) {
                    $honeypotDomains[] = strtolower(substr($a, $at + 1));
                }
            }
            $honeypotDomains = array_values(array_unique($honeypotDomains));
        }

        return new IocUpsertService(
            $em,
            $container->get(RiskScorer::class),
            $container->get(IocCategorizer::class),
            $container->get(IocExportMapper::class),
            $container->get(HeaderIocExtractor::class),
            $honeypotAddresses,
            null,
            null,
            new \App\Domain\Communication\Policy\IocExtractionPolicy(),
            $honeypotDomains,
        );
    }

    public function testUpsertHoneypotEmailIsRejected(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => self::HONEYPOT_ADDRESS,
                'value_norm' => self::HONEYPOT_ADDRESS,
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertHoneypotEmailIsCaseInsensitive(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => 'Honeypot-TEST-Spec061@Example.COM',
                'value_norm' => 'Honeypot-TEST-Spec061@Example.COM',
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertNonHoneypotEmailIsAccepted(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => 'real-scammer-spec061@example.org',
                'value_norm' => 'real-scammer-spec061@example.org',
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        // Cleanup so the test is idempotent
        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM observed_ioc WHERE obs_id = :id',
            ['id' => $observed->getObsId()]
        );
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'email' AND value_norm = :v",
            ['v' => 'real-scammer-spec061@example.org']
        );
    }

    public function testNonEmailIocsBypassHoneypotFilter(): void
    {
        // A phone or iban with a value that LITERALLY equals a honeypot email string
        // (theoretical, but we want the filter strictly scoped to type='email').
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'iban',
                'value' => 'GB29NWBK60161331926000',
                'value_norm' => 'GB29NWBK60161331926000',
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM observed_ioc WHERE obs_id = :id',
            ['id' => $observed->getObsId()]
        );
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'iban' AND value_norm = :v",
            ['v' => 'GB29NWBK60161331926000']
        );
    }

    public function testEmptyHoneypotListBehavesAsNoOp(): void
    {
        // Safe default for fresh deployments: empty array → filter never matches.
        $service = $this->buildServiceWithHoneypot([]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => self::HONEYPOT_ADDRESS,
                'value_norm' => self::HONEYPOT_ADDRESS,
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM observed_ioc WHERE obs_id = :id',
            ['id' => $observed->getObsId()]
        );
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'email' AND value_norm = :v",
            ['v' => self::HONEYPOT_ADDRESS]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Spec 098 — DOMAIN + URL filter extension
    //
    // Before Spec 098, the filter only matched type='email'. After 098, any
    // domain or url whose host matches a configured honeypot-owned domain
    // is also rejected. This catches the case where a scammer quotes our
    // reply (in the body of an incoming message) — our own domain and the
    // matching `https://www.<domain>/...` link would otherwise leak into the
    // IOC catalog as adversary intel.
    // ─────────────────────────────────────────────────────────────────────

    public function testUpsertHoneypotDomainIsRejected(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'domain',
                'value' => 'example.com',
                'value_norm' => 'example.com',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertHoneypotDomainIsCaseInsensitive(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'domain',
                'value' => 'Example.COM',
                'value_norm' => 'Example.COM',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertHoneypotUrlIsRejected(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'url',
                'value' => 'https://example.com/portfolio',
                'value_norm' => 'https://example.com/portfolio',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertHoneypotUrlWithWwwPrefixIsRejected(): void
    {
        // Persona prompts sometimes render absolute links with www. prefix.
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'url',
                'value' => 'https://www.example.com/anything',
                'value_norm' => 'https://www.example.com/anything',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testTypoSquatDomainIsAccepted(): void
    {
        // A typo-squat like "example-pay.com" is a different registrable
        // domain than the honeypot domain "example.com" — must NOT be
        // filtered. The filter uses strict equality on the host string,
        // not substring/suffix matching.
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $typoValue = 'example-pay.com';
        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'domain',
                'value' => $typoValue,
                'value_norm' => $typoValue,
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('DELETE FROM observed_ioc WHERE obs_id = :id', ['id' => $observed->getObsId()]);
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'domain' AND value_norm = :v",
            ['v' => $typoValue],
        );
    }

    public function testMalformedUrlIsNotRejectedByHoneypotFilter(): void
    {
        // If parse_url cannot extract a host, the filter must NOT reject the
        // value — let the rest of the validation chain decide what to do.
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $malformed = 'not-actually-a-url';
        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'url',
                'value' => $malformed,
                'value_norm' => $malformed,
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        // Should NOT throw the honeypot exception. May persist successfully
        // or throw some unrelated validation error — we only assert: no
        // /honeypot/ in any thrown message.
        try {
            $observed = $service->upsertEnrichedIoc($payload);
            $this->assertNotNull($observed->getObsId());
            /** @var Connection $conn */
            $conn = self::getContainer()->get(Connection::class);
            $conn->executeStatement('DELETE FROM observed_ioc WHERE obs_id = :id', ['id' => $observed->getObsId()]);
            $conn->executeStatement(
                "DELETE FROM indicator WHERE type = 'url' AND value_norm = :v",
                ['v' => $malformed],
            );
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsStringIgnoringCase('honeypot', $e->getMessage());
        }
    }

    public function testDefangedDomainValueNormIsRejected(): void
    {
        // Spec 098 fix-up — IocCategorizer stores value_norm in defanged form
        // (acme.example → acme[.]com). The filter must un-defang before
        // matching, otherwise the defanged form leaks through.
        $service = $this->buildServiceWithHoneypot(['admin@example.com']);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'domain',
                'value' => 'example.com',
                'value_norm' => 'example[.]com',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testSchemeLessUrlValueNormIsRejected(): void
    {
        // Spec 098 fix-up — a URL like `www.example.com/x` without an
        // explicit scheme returns null from parse_url. The filter must
        // synthesise `https://` before parsing so the host check still
        // bites. Otherwise scammers quoting our home page as `www.our-
        // domain.example` would leak the IOC.
        $service = $this->buildServiceWithHoneypot(['admin@example.com']);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'url',
                'value' => 'www.example.com',
                'value_norm' => 'www.example.com',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testDefangedUrlValueNormIsRejected(): void
    {
        $service = $this->buildServiceWithHoneypot(['admin@example.com']);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'url',
                'value' => 'https://www.example.com/x',
                'value_norm' => 'https[://]www[.]example[.]com[/]x',
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testPersonaAliasEmailUnderHoneypotDomainIsRejected(): void
    {
        // Only admin@example.com is configured, but alias.persona@example.com
        // is on the same honeypot domain → must be rejected too. This catches
        // the persona aliases that are not enumerated in the env var.
        $service = $this->buildServiceWithHoneypot(['admin@example.com']);

        $aliasAddress = 'alias.persona@example.com';
        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => $aliasAddress,
                'value_norm' => $aliasAddress,
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testNonHoneypotDomainIsAccepted(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $value = 'real-scammer-domain-spec098.org';
        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'domain',
                'value' => $value,
                'value_norm' => $value,
                'source' => 'body',
                'first_seen' => '2026-06-14T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('DELETE FROM observed_ioc WHERE obs_id = :id', ['id' => $observed->getObsId()]);
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'domain' AND value_norm = :v",
            ['v' => $value],
        );
    }
}
