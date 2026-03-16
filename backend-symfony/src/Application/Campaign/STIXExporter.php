<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

final class STIXExporter
{
    private const STIX_VERSION = '2.1';
    private const IDENTITY_NAME = 'ScamBuster Threat Intel';

    /**
     * Mapping des types d'IoCs vers les STIX Cyber-observable Objects.
     *
     * @var array<string, array{stix_type: string, pattern_format: string, name_prefix: string}>
     */
    private const IOC_TYPE_MAPPING = [
        'domains' => [
            'stix_type' => 'domain-name',
            'pattern_format' => "[domain-name:value = '%s']",
            'name_prefix' => 'Malicious domain',
        ],
        'emails' => [
            'stix_type' => 'email-addr',
            'pattern_format' => "[email-addr:value = '%s']",
            'name_prefix' => 'Malicious email',
        ],
        'urls' => [
            'stix_type' => 'url',
            'pattern_format' => "[url:value = '%s']",
            'name_prefix' => 'Malicious URL',
        ],
        'phone_numbers' => [
            'stix_type' => 'x-phone-number',
            'pattern_format' => "[x-phone-number:value = '%s']",
            'name_prefix' => 'Malicious phone',
        ],
        'ip_addresses' => [
            'stix_type' => 'ipv4-addr',
            'pattern_format' => "[ipv4-addr:value = '%s']",
            'name_prefix' => 'Malicious IP',
        ],
        'file_hashes' => [
            'stix_type' => 'file',
            'pattern_format' => "[file:hashes.SHA256 = '%s']",
            'name_prefix' => 'Malicious file hash',
        ],
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $stixExportPath
    ) {
    }

    /**
     * Exporte une campagne en STIX 2.1.
     *
     * @throws \RuntimeException si export échoue
     *
     * @return array{file_path: string, bundle_id: string}
     */
    public function export(Campaign $campaign): array
    {
        $this->logger->info('Exporting campaign to STIX', [
            'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
        ]);

        // 1. Extraire IoCs depuis profile YAML
        $iocs = $this->extractIoCs($campaign);

        // 2. Générer bundle STIX 2.1
        $bundle = $this->generateSTIXBundle($campaign, $iocs);

        // 3. Validation PII (sécurité)
        $this->validateNoPII($bundle);

        // 4. Sauvegarder fichier JSON
        $filePath = $this->saveBundle($campaign->getCampaignId(), $bundle);

        // Compter tous les IoCs exportés
        $totalIocs = array_sum(array_map('count', $iocs));

        $this->logger->info('STIX export completed', [
            'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
            'file_path' => $filePath,
            'iocs_count' => $totalIocs,
            'iocs_breakdown' => array_map('count', $iocs),
        ]);

        return [
            'file_path' => $filePath,
            'bundle_id' => $bundle['id'],
        ];
    }

    /**
     * Extrait IoCs depuis le profil YAML de la campagne.
     *
     * @return array{domains: list<string>, emails: list<string>, urls: list<string>, phone_numbers: list<string>, ip_addresses: list<string>, file_hashes: list<string>}
     */
    private function extractIoCs(Campaign $campaign): array
    {
        // Priorité: profileYaml (généré par LLM), fallback sur notes
        $profileYaml = $campaign->getProfileYaml() ?? $campaign->getNotes();

        if (!$profileYaml) {
            return $this->emptyIoCs();
        }

        try {
            $profile = Yaml::parse($profileYaml);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to parse campaign profile YAML', [
                'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            return $this->emptyIoCs();
        }

        if (!is_array($profile)) {
            return $this->emptyIoCs();
        }

        // Validation structure minimale
        if (!$this->validateProfileSchema($profile)) {
            $this->logger->warning('Invalid profile schema', [
                'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
            ]);

            return $this->emptyIoCs();
        }

        // Extraction avec fallbacks multiples
        return [
            'domains' => $this->extractDomains($profile),
            'emails' => $this->extractEmails($profile),
            'urls' => $this->extractUrls($profile),
            'phone_numbers' => $this->extractPhoneNumbers($profile),
            'ip_addresses' => $this->extractIpAddresses($profile),
            'file_hashes' => $this->extractFileHashes($profile),
        ];
    }

    /**
     * Retourne une structure IoCs vide.
     *
     * @return array{domains: list<string>, emails: list<string>, urls: list<string>, phone_numbers: list<string>, ip_addresses: list<string>, file_hashes: list<string>}
     */
    private function emptyIoCs(): array
    {
        return [
            'domains' => [],
            'emails' => [],
            'urls' => [],
            'phone_numbers' => [],
            'ip_addresses' => [],
            'file_hashes' => [],
        ];
    }

    /**
     * Valide que le profil a une structure minimale valide.
     *
     * @param array<string, mixed> $profile
     */
    private function validateProfileSchema(array $profile): bool
    {
        return isset($profile['infra']) || isset($profile['variants']) || isset($profile['campaign']);
    }

    /**
     * Extrait les domaines avec fallbacks multiples.
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private function extractDomains(array $profile): array
    {
        $domains = [];

        // Chemin 1: infra.domains
        if (isset($profile['infra']) && is_array($profile['infra'])) {
            /** @var array<string, mixed> $infra */
            $infra = $profile['infra'];

            if (isset($infra['domains']) && is_array($infra['domains'])) {
                $domains = array_merge($domains, $infra['domains']);
            }
        }

        // Chemin 2: variants.url_shapes (parsing)
        if (isset($profile['variants']) && is_array($profile['variants'])) {
            /** @var array<string, mixed> $variants */
            $variants = $profile['variants'];

            if (isset($variants['url_shapes']) && is_array($variants['url_shapes'])) {
                foreach ($variants['url_shapes'] as $urlShape) {
                    if (is_string($urlShape) && preg_match('#https?://([^/\s{]+)#', $urlShape, $matches)) {
                        $domains[] = $matches[1];
                    }
                }
            }
        }

        // Chemin 3: DSL rules (si présentes)
        if (isset($profile['rules']) && is_array($profile['rules'])) {
            foreach ($profile['rules'] as $rule) {
                if (is_string($rule) && preg_match('/from\.domain\s*==\s*[\'"]([^\'"]+)[\'"]/i', $rule, $matches)) {
                    $domains[] = $matches[1];
                }
            }
        }

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * Extrait les emails avec filtrage PII.
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private function extractEmails(array $profile): array
    {
        $emails = [];

        // Chemin 1: infra.emails
        if (isset($profile['infra']) && is_array($profile['infra'])) {
            /** @var array<string, mixed> $infra */
            $infra = $profile['infra'];

            if (isset($infra['emails']) && is_array($infra['emails'])) {
                $emails = array_merge($emails, $infra['emails']);
            }
        }

        // Chemin 2: campaign sender_emails
        if (isset($profile['campaign']) && is_array($profile['campaign'])) {
            /** @var array<string, mixed> $campaign */
            $campaign = $profile['campaign'];

            if (isset($campaign['sender_emails']) && is_array($campaign['sender_emails'])) {
                $emails = array_merge($emails, $campaign['sender_emails']);
            }
        }

        // Filtrer les emails personnels (PII)
        $emails = array_filter($emails, fn ($email) => !$this->isPersonalEmail($email));

        return array_values(array_unique($emails));
    }

    /**
     * Vérifie si un email est un email personnel (PII).
     */
    private function isPersonalEmail(string $email): bool
    {
        $personalDomains = ['gmail.com', 'yahoo.com', 'yahoo.fr', 'hotmail.com', 'outlook.com', 'live.com'];

        foreach ($personalDomains as $domain) {
            if (str_ends_with(strtolower($email), '@' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrait les URLs avec filtrage placeholders.
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private function extractUrls(array $profile): array
    {
        $urls = [];

        // Chemin 1: variants.url_shapes
        if (isset($profile['variants']) && is_array($profile['variants'])) {
            /** @var array<string, mixed> $variants */
            $variants = $profile['variants'];

            if (isset($variants['url_shapes']) && is_array($variants['url_shapes'])) {
                $urls = array_merge($urls, $variants['url_shapes']);
            }
        }

        // Chemin 2: campaign.urls
        if (isset($profile['campaign']) && is_array($profile['campaign'])) {
            /** @var array<string, mixed> $campaign */
            $campaign = $profile['campaign'];

            if (isset($campaign['urls']) && is_array($campaign['urls'])) {
                $urls = array_merge($urls, $campaign['urls']);
            }
        }

        // Filtrer les patterns (enlever placeholders)
        $urls = array_filter($urls, fn ($url) => is_string($url) && !str_contains($url, '{'));

        return array_values(array_unique($urls));
    }

    /**
     * Extrait les numéros de téléphone.
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private function extractPhoneNumbers(array $profile): array
    {
        $phones = [];

        // Chemin 1: infra.phone_numbers
        if (isset($profile['infra']) && is_array($profile['infra'])) {
            /** @var array<string, mixed> $infra */
            $infra = $profile['infra'];

            if (isset($infra['phone_numbers']) && is_array($infra['phone_numbers'])) {
                $phones = array_merge($phones, $infra['phone_numbers']);
            }
        }

        // Chemin 2: campaign.phone_numbers
        if (isset($profile['campaign']) && is_array($profile['campaign'])) {
            /** @var array<string, mixed> $campaign */
            $campaign = $profile['campaign'];

            if (isset($campaign['phone_numbers']) && is_array($campaign['phone_numbers'])) {
                $phones = array_merge($phones, $campaign['phone_numbers']);
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * Extrait les adresses IP.
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private function extractIpAddresses(array $profile): array
    {
        $ips = [];

        // Chemin 1: infra.ip_addresses
        if (isset($profile['infra']) && is_array($profile['infra'])) {
            /** @var array<string, mixed> $infra */
            $infra = $profile['infra'];

            if (isset($infra['ip_addresses']) && is_array($infra['ip_addresses'])) {
                $ips = array_merge($ips, $infra['ip_addresses']);
            }

            // Chemin 2: infra.c2_servers
            if (isset($infra['c2_servers']) && is_array($infra['c2_servers'])) {
                $ips = array_merge($ips, $infra['c2_servers']);
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Extrait les hashes de fichiers.
     *
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private function extractFileHashes(array $profile): array
    {
        $hashes = [];

        // Chemin 1: infra.file_hashes
        if (isset($profile['infra']) && is_array($profile['infra'])) {
            /** @var array<string, mixed> $infra */
            $infra = $profile['infra'];

            if (isset($infra['file_hashes']) && is_array($infra['file_hashes'])) {
                $hashes = array_merge($hashes, $infra['file_hashes']);
            }
        }

        // Chemin 2: malware.hashes
        if (isset($profile['malware']) && is_array($profile['malware'])) {
            /** @var array<string, mixed> $malware */
            $malware = $profile['malware'];

            if (isset($malware['hashes']) && is_array($malware['hashes'])) {
                $hashes = array_merge($hashes, $malware['hashes']);
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * Génère un bundle STIX 2.1 de manière générique.
     *
     * @param array{domains: list<string>, emails: list<string>, urls: list<string>, phone_numbers: list<string>, ip_addresses: list<string>, file_hashes: list<string>} $iocs
     *
     * @return array<string, mixed> Bundle STIX
     */
    private function generateSTIXBundle(Campaign $campaign, array $iocs): array
    {
        $bundleId = 'bundle--' . Uuid::v4()->toRfc4122();
        $identityId = 'identity--' . Uuid::v4()->toRfc4122();
        $reportId = 'report--' . Uuid::v4()->toRfc4122();

        /** @var array<int, array<string, mixed>> $objects */
        $objects = [];

        // Identity object
        $objects[] = [
            'type' => 'identity',
            'spec_version' => self::STIX_VERSION,
            'id' => $identityId,
            'created' => $campaign->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'modified' => $campaign->getUpdatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'name' => self::IDENTITY_NAME,
            'identity_class' => 'organization',
        ];

        // Report object avec TLP
        $objects[] = [
            'type' => 'report',
            'spec_version' => self::STIX_VERSION,
            'id' => $reportId,
            'created' => $campaign->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'modified' => $campaign->getUpdatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'name' => 'ScamBuster Campaign ' . substr($campaign->getCampaignId()->toRfc4122(), 0, 8),
            'published' => $campaign->getUpdatedAt()->format('Y-m-d\TH:i:s.000\Z'),
            'object_refs' => [$identityId],
            'labels' => ['campaign', 'threat-report', $campaign->getTlp()],
        ];

        // Générer les indicators de manière générique pour tous les types d'IoCs
        $this->generateIndicatorsForAllIocTypes($campaign, $iocs, $objects);

        // Bundle
        return [
            'type' => 'bundle',
            'id' => $bundleId,
            'spec_version' => self::STIX_VERSION,
            'objects' => $objects,
        ];
    }

    /**
     * Génère les indicators STIX pour tous les types d'IoCs de manière générique.
     *
     * @param array{domains: list<string>, emails: list<string>, urls: list<string>, phone_numbers: list<string>, ip_addresses: list<string>, file_hashes: list<string>} $iocs
     * @param array<int, array<string, mixed>>                                                                                                                           &$objects
     */
    private function generateIndicatorsForAllIocTypes(Campaign $campaign, array $iocs, array &$objects): void
    {
        foreach ($iocs as $iocType => $iocValues) {
            // Ignorer les types d'IoCs vides
            if (empty($iocValues)) {
                continue;
            }

            // Vérifier si ce type d'IoC a un mapping STIX défini
            if (!array_key_exists($iocType, self::IOC_TYPE_MAPPING)) {
                $this->logger->warning('Unknown IoC type, skipping', [
                    'ioc_type' => $iocType,
                    'values_count' => count($iocValues),
                ]);

                continue;
            }

            $mapping = self::IOC_TYPE_MAPPING[$iocType];

            // Générer un indicator pour chaque valeur
            foreach ($iocValues as $iocValue) {
                $indicatorId = 'indicator--' . Uuid::v4()->toRfc4122();

                $objects[] = [
                    'type' => 'indicator',
                    'spec_version' => self::STIX_VERSION,
                    'id' => $indicatorId,
                    'created' => $campaign->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z'),
                    'modified' => $campaign->getUpdatedAt()->format('Y-m-d\TH:i:s.000\Z'),
                    'name' => $mapping['name_prefix'] . ': ' . $iocValue,
                    'pattern' => sprintf($mapping['pattern_format'], addslashes($iocValue)),
                    'pattern_type' => 'stix',
                    'valid_from' => $campaign->getFirstSeen()->format('Y-m-d\TH:i:s.000\Z'),
                    'labels' => ['malicious-activity'],
                ];

                // Ajouter la référence au report
                /** @var array<string, mixed> $reportObject */
                $reportObject = &$objects[1];
                /** @var array<int, string> $objectRefs */
                $objectRefs = $reportObject['object_refs'];
                $objectRefs[] = $indicatorId;
                $reportObject['object_refs'] = $objectRefs;
            }

            $this->logger->debug('Generated STIX indicators', [
                'ioc_type' => $iocType,
                'count' => count($iocValues),
            ]);
        }
    }

    /**
     * Valide qu'aucune PII n'est présente dans le bundle.
     *
     * @param array<string, mixed> $bundle
     *
     * @throws \RuntimeException si PII détectée
     */
    private function validateNoPII(array $bundle): void
    {
        $bundleJson = json_encode($bundle);

        if ($bundleJson === false) {
            throw new \RuntimeException('Failed to encode STIX bundle to JSON');
        }

        // Patterns PII: emails personnels uniquement
        $patterns = [
            '/\b[A-Za-z0-9._%+-]+@gmail\.com\b/i',
            '/\b[A-Za-z0-9._%+-]+@yahoo\.com\b/i',
            '/\b[A-Za-z0-9._%+-]+@hotmail\.com\b/i',
            '/\b[A-Za-z0-9._%+-]+@outlook\.com\b/i',
            '/\b[A-Za-z0-9._%+-]+@live\.com\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $bundleJson)) {
                throw new \RuntimeException('PII detected in STIX bundle - export aborted');
            }
        }
    }

    /**
     * Sauvegarde le bundle STIX en fichier JSON.
     *
     * @param array<string, mixed> $bundle
     */
    private function saveBundle(Uuid $campaignId, array $bundle): string
    {
        $filename = sprintf(
            'campaign-%s-%s.json',
            substr($campaignId->toRfc4122(), 0, 8),
            date('Ymd-His')
        );

        $filePath = $this->stixExportPath . '/' . $filename;

        // Créer répertoire si nécessaire
        if (!is_dir($this->stixExportPath)) {
            mkdir($this->stixExportPath, 0755, true);
        }

        file_put_contents($filePath, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $filePath;
    }
}
