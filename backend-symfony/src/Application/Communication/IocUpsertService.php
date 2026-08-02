<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\Policy\IocExtractionPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles IOC upsert, deduplication, and header IOC extraction.
 *
 * Extracted from IocHandler (CT-0 decomposition).
 */
class IocUpsertService
{
    /** @var array<string, true> */
    private readonly array $honeypotAddressesIndex;

    /** @var array<string, true> */
    private readonly array $honeypotDomainsIndex;

    /**
     * @param list<string>|null $honeypotEmailAddresses canonical honeypot addresses to never ingest as IOCs.
     *                                                  Null or empty array → email exact-match filter is a no-op.
     * @param list<string>|null $honeypotDomains        honeypot-OWNED domains (NOT derived from emails,
     *                                                  because some addresses are on shared providers like gmail.com).
     *                                                  Drives domain/url filters AND email-by-domain-part match.
     *                                                  Null or empty array → domain filters are no-ops.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RiskScorer $riskScorer,
        private readonly IocCategorizer $categorizer,
        private readonly IocExportMapper $exportMapper,
        private readonly HeaderIocExtractor $headerExtractor,
        ?array $honeypotEmailAddresses = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?IocContextService $contextService = null,
        // Extracted from Message::canExtractIocs()
        private readonly IocExtractionPolicy $iocExtractionPolicy = new IocExtractionPolicy(),
        ?array $honeypotDomains = null,
    ) {
        // Normalize once (lowercase, deduplicated, indexed for O(1) lookup).
        $addresses = [];

        foreach ($honeypotEmailAddresses ?? [] as $address) {
            $normalized = strtolower(trim($address));

            if ($normalized !== '') {
                $addresses[$normalized] = true;
            }
        }
        $this->honeypotAddressesIndex = $addresses;

        // Honeypot-owned domains are EXPLICITLY configured, not
        // derived from email addresses. Auto-derivation would catch
        // gmail.com / outlook.com if any persona uses a free-provider inbox,
        // wiping legitimate scammer addresses on the same provider.
        $domainsIdx = [];

        foreach ($honeypotDomains ?? [] as $domain) {
            $normalized = strtolower(trim($domain));

            if ($normalized !== '') {
                if (str_starts_with($normalized, 'www.')) {
                    $normalized = substr($normalized, 4);
                }
                $domainsIdx[$normalized] = true;
            }
        }
        $this->honeypotDomainsIndex = $domainsIdx;
    }

    /**
     * Layer 2: refuse to upsert any IOC pointing back
     * at honeypot infrastructure. Catches the case where a scammer quotes
     * our reply, causing our own identifiers to be re-extracted from an
     * incoming message body. Covered IOC types:
     *
     *   - email  : exact address match OR domain-part match against
     *              honeypot domains. Any address under a honeypot
     *              domain is by definition our infrastructure (persona
     *              alias, automated sender, etc.) — only EMAIL addresses
     *              not in HONEYPOT_EMAIL_ADDRESSES but on a honeypot domain
     *              still ought to be rejected, which the exact-match check
     *              alone would miss.
     *   - domain : exact match against honeypot domains
     *   - url    : host match against honeypot domains after stripping
     *              `www.`
     *
     * For type='url', a malformed value (parse_url returns no host) falls
     * through — let downstream validation decide. We never invent a host.
     */
    /**
     * Un-defang an IOC value so the filter compares against the
     * canonical (non-bracketed) form. IocCategorizer normalises domains and
     * URLs by wrapping each '.' as '[.]' (and '://' as '[://]') so the
     * stored value_norm is not auto-rendered as a clickable link by
     * security tools. The runtime filter sees the defanged form on upsert,
     * so it must reverse it before matching against the honeypot indexes.
     */
    private function unDefang(string $value): string
    {
        return str_replace(['[.]', '[/]', '[://]', '[:]'], ['.', '/', '://', ':'], $value);
    }

    private function isHoneypotAddress(string $type, string $valueNorm): bool
    {
        $needle = strtolower($this->unDefang($valueNorm));

        if ($type === 'email') {
            if (isset($this->honeypotAddressesIndex[$needle])) {
                return true;
            }
            $atPos = strrpos($needle, '@');

            if ($atPos === false || $atPos >= strlen($needle) - 1) {
                return false;
            }

            return isset($this->honeypotDomainsIndex[substr($needle, $atPos + 1)]);
        }

        if ($type === 'domain') {
            return isset($this->honeypotDomainsIndex[$needle]);
        }

        if ($type === 'url') {
            // Scheme-less URLs (e.g. `www.example.com/x`) → parse_url
            // returns no host. Prefix `https://` so parse_url can find
            // the host; we only use the parsed host to compare against
            // honeypotDomainsIndex, so the synthetic scheme is harmless.
            $forParse = $needle;

            if (!preg_match('#^[a-z][a-z0-9+\-.]*://#', $forParse)) {
                $forParse = 'https://' . $forParse;
            }
            $host = parse_url($forParse, PHP_URL_HOST);

            if (!is_string($host) || $host === '') {
                return false;
            }

            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }

            return isset($this->honeypotDomainsIndex[$host]);
        }

        return false;
    }

    /**
     * Upsert enriched IOC from n8n workflow.
     *
     * Idempotent: Uses unique constraint on (msg_id, type, value_norm).
     *
     * @param array{
     *     message_id?: string,
     *     msg_id?: string,
     *     ioc: array{type: string, value: string, value_norm: string, source: string, first_seen: string},
     *     enrichment?: array<string, mixed>,
     *     score?: array<string, mixed>,
     *     category?: string,
     *     tags?: array<string>,
     *     tlp?: string
     * } $data Enriched IOC payload from n8n
     *
     * @throws \RuntimeException If message not found
     */
    public function upsertEnrichedIoc(array $data): ObservedIoc
    {
        $message = $this->resolveMessage($data);

        if (!$message instanceof \App\Domain\Communication\Message) {
            throw new \RuntimeException(sprintf(
                'Message not found for external_message_id=%s or msg_id=%s',
                $data['message_id'] ?? 'null',
                $data['msg_id'] ?? 'null'
            ));
        }

        // Layer 1: refuse extraction on outgoing messages.
        // Single funnel: this guard catches all callers (HTTP /enriched, MigrateHeaderIocs,
        // IngestPostProcessor, future entry points).
        if (!$this->iocExtractionPolicy->allows($message)) {
            throw new \InvalidArgumentException(sprintf(
                'IOC extraction is not allowed on outgoing messages (msg_id=%s, direction=%s)',
                $message->getMsgId(),
                $message->getDirection()->getCode(),
            ));
        }

        $iocData = $data['ioc'];
        $type = $iocData['type'];
        $valueNorm = $iocData['value_norm'];

        // Layer 2: refuse honeypot identifiers
        // (case-insensitive). Covers email, domain + url.
        // Catches scammers quoting our reply back at us in an incoming body.
        if ($this->isHoneypotAddress($type, $valueNorm)) {
            throw new \InvalidArgumentException(sprintf(
                'Refused to upsert honeypot %s "%s" as IOC',
                $type,
                $valueNorm,
            ));
        }

        $existingIoc = $this->findExistingIoc($message->getMsgId(), $type, $valueNorm);

        if ($existingIoc instanceof \App\Domain\Communication\ObservedIoc) {
            $this->updateIocContext($existingIoc, $data);
            $this->em->flush();

            return $existingIoc;
        }

        $iocId = uuid_create(UUID_TYPE_RANDOM);
        $obsId = uuid_create(UUID_TYPE_RANDOM);

        $enrichment = $data['enrichment'] ?? [];
        /** @phpstan-ignore-next-line */
        $score = $data['score'] ?? $this->riskScorer->calculateIocScore($enrichment);

        $providedCategory = $data['category'] ?? null;
        $category = ($providedCategory !== null && $providedCategory !== 'Unknown')
            ? $providedCategory
            : $this->categorizer->guessCategory(
                $iocData['value'],
                $message->getBodyText()
            );

        $context = [
            'type' => $type,
            'value' => $iocData['value'],
            'value_norm' => $valueNorm,
            'source' => $iocData['source'],
            'first_seen' => $iocData['first_seen'],
            'enrichment' => $enrichment,
            'score' => $score,
            'category' => $category,
            'tags' => $data['tags'] ?? ['phishing'],
            'tlp' => $data['tlp'] ?? 'AMBER',
        ];

        $context = $this->exportMapper->enrichWithExportMetadata($context);

        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existingIndicator = $conn->executeQuery(
            'SELECT indicator_id FROM indicator WHERE type = :type AND value_norm = :valueNorm',
            ['type' => $type, 'valueNorm' => $valueNorm]
        )->fetchAssociative();

        if ($existingIndicator) {
            $indicatorId = $existingIndicator['indicator_id'];
            $conn->executeStatement(
                'UPDATE indicator SET
                    last_seen = :lastSeen,
                    last_enriched = :lastEnriched,
                    occurrences = occurrences + 1,
                    enrichment = :enrichment,
                    score = :score,
                    updated_at = :updatedAt
                WHERE indicator_id = :indicatorId',
                [
                    'lastSeen' => $now,
                    'lastEnriched' => $now,
                    'enrichment' => json_encode($enrichment),
                    'score' => json_encode($score),
                    'updatedAt' => $now,
                    'indicatorId' => $indicatorId,
                ]
            );
        } else {
            $indicatorId = $iocId;
            $conn->executeStatement(
                'INSERT INTO indicator (
                    indicator_id, type, value, value_norm, first_seen, last_seen,
                    last_enriched, occurrences, enrichment, score, tlp, created_at, updated_at
                ) VALUES (
                    :indicatorId, :type, :value, :valueNorm, :firstSeen, :lastSeen,
                    :lastEnriched, 1, :enrichment, :score, :tlp, :createdAt, :updatedAt
                )',
                [
                    'indicatorId' => $indicatorId,
                    'type' => $type,
                    'value' => $iocData['value'],
                    'valueNorm' => $valueNorm,
                    'firstSeen' => $now,
                    'lastSeen' => $now,
                    'lastEnriched' => $now,
                    'enrichment' => json_encode($enrichment),
                    'score' => json_encode($score),
                    'tlp' => $data['tlp'] ?? 'AMBER',
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );
        }

        /** @var string $extractionMethod */
        $extractionMethod = $context['extraction_method'] ?? 'unknown';
        $confidence = IocConfidenceCalculator::getBaseConfidence($extractionMethod);

        $occurrencesRow = $conn->fetchOne(
            'SELECT occurrences FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId],
        );
        $occurrences = \is_numeric($occurrencesRow) ? (int) $occurrencesRow : 1;
        $confidence = IocConfidenceCalculator::boostConfidence($confidence, $occurrences);

        $observedIoc = new ObservedIoc(
            $obsId,
            $message,
            $indicatorId,
            $context,
            new \DateTimeImmutable(),
            $confidence,
        );

        $this->em->persist($observedIoc);
        $this->em->flush();

        $this->auditLogger?->log(
            AuditEventType::IOC_EXTRACTED,
            $message->getConversation()->getConvId(),
            'ioc_extracted',
            'success',
            'observed_ioc',
            $obsId,
            [
                'type' => $type,
                'value_norm' => $valueNorm,
                'indicator_id' => $indicatorId,
            ],
        );

        // Compute structural context for the newly upserted IOC
        $this->contextService?->computeAndPersistForMessage(
            $message->getMsgId(),
            [['obs_id' => $obsId, 'indicator_id' => $indicatorId, 'ioc_type' => $type]],
        );

        return $observedIoc;
    }

    /**
     * Extract and upsert header-based IOCs from a message.
     */
    public function extractAndUpsertHeaderIocs(Message $message): int
    {
        $headers = $message->getHeaders();
        $subject = $message->getSubject() ?? '';

        $headerIocs = $this->headerExtractor->extractHeaderIocs($headers, $subject);

        $count = 0;

        foreach ($headerIocs as $iocData) {
            $payload = [
                'msg_id' => $message->getMsgId(),
                'ioc' => [
                    'type' => $iocData['type'],
                    'value' => $iocData['value'],
                    'value_norm' => $iocData['value_norm'],
                    'source' => $iocData['source'],
                    'first_seen' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                ],
                'enrichment' => [],
                'category' => 'Unknown',
                'tags' => [],
                'tlp' => 'AMBER',
            ];

            try {
                $this->upsertEnrichedIoc($payload);
                ++$count;
            } catch (\Exception) {
                continue;
            }
        }

        return $count;
    }

    /**
     * Resolve message by external_message_id or msg_id.
     *
     * @param array{message_id?: string, msg_id?: string} $data
     */
    private function resolveMessage(array $data): ?Message
    {
        $repo = $this->em->getRepository(Message::class);

        if (!empty($data['message_id'])) {
            $message = $repo->findOneBy(['externalMessageId' => $data['message_id']]);

            if ($message !== null) {
                return $message;
            }
        }

        if (!empty($data['msg_id'])) {
            return $repo->find($data['msg_id']);
        }

        return null;
    }

    /**
     * Find existing IOC by (msg_id, type, value_norm).
     */
    private function findExistingIoc(string $msgId, string $type, string $valueNorm): ?ObservedIoc
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT obs_id
            FROM observed_ioc
            WHERE msg_id = :msgId
              AND context_observation->>'type' = :type
              AND context_observation->>'value_norm' = :valueNorm
            LIMIT 1
        ";

        $result = $conn->executeQuery($sql, [
            'msgId' => $msgId,
            'type' => $type,
            'valueNorm' => $valueNorm,
        ])->fetchAssociative();

        if (!$result) {
            return null;
        }

        return $this->em->getRepository(ObservedIoc::class)->find($result['obs_id']);
    }

    /**
     * Update existing IOC context with new enrichment data.
     *
     * @param ObservedIoc          $ioc     Existing IOC entity
     * @param array<string, mixed> $newData New data
     */
    private function updateIocContext(ObservedIoc $ioc, array $newData): void
    {
        $context = $ioc->getContext();

        if (isset($newData['enrichment']) && is_array($newData['enrichment'])) {
            $existingEnrichment = $context['enrichment'] ?? [];
            $context['enrichment'] = is_array($existingEnrichment) ? array_merge($existingEnrichment, $newData['enrichment']) : $newData['enrichment'];
        }

        /** @phpstan-ignore-next-line */
        $context['score'] = $this->riskScorer->calculateIocScore($context['enrichment'] ?? []);

        if (isset($newData['category']) && is_string($newData['category'])) {
            $context['category'] = $newData['category'];
        }

        if (isset($newData['tags']) && is_array($newData['tags'])) {
            $existingTags = $context['tags'] ?? [];
            $context['tags'] = array_unique(is_array($existingTags) ? array_merge($existingTags, $newData['tags']) : $newData['tags']);
        }

        $context = $this->exportMapper->enrichWithExportMetadata($context);

        $ioc->updateContext($context);
    }
}
