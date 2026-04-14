<?php

declare(strict_types=1);

namespace App\Infrastructure\Preprod;

use App\Domain\Communication\ScamType;

/**
 * Générateur d'IOCs (Indicators of Compromise) réalistes
 *
 * Génère des IOCs fictifs mais plausibles pour les conversations preprod:
 * - Numéros de téléphone
 * - IBANs
 * - URLs
 * - Adresses email
 * - Cryptowallets
 */
class IocGenerator
{
    private const PHONE_PREFIXES = [
        '+33 6', '+33 7', // France mobile
        '+1 555', // US (fake)
        '+44 7', // UK mobile
        '+234 ', // Nigeria (419 scams)
    ];

    private const EMAIL_DOMAINS = [
        'gmail.com',
        'outlook.com',
        'yahoo.com',
        'hotmail.com',
        'protonmail.com',
    ];

    private const URL_SUSPICIOUS_DOMAINS = [
        'secure-verify.com',
        'account-update.net',
        'support-center.org',
        'payment-pending.info',
        'verify-account.co',
        'login-secure.xyz',
        'myaccount-verify.com',
        'portal-access.net',
    ];

    /**
     * Génère des IOCs adaptés au type de scam
     *
     * @return array<string, mixed>
     */
    public function generateIocsForScamType(ScamType $scamType): array
    {
        $code = $scamType->getCode();

        return match (true) {
            str_contains($code, 'PHISH') => $this->generatePhishingIocs(),
            str_contains($code, 'ROMANCE') => $this->generateRomanceIocs(),
            str_contains($code, 'TECH_SUPPORT') => $this->generateTechSupportIocs(),
            str_contains($code, 'INVESTMENT') => $this->generateInvestmentIocs(),
            str_contains($code, 'BEC') || str_contains($code, 'INVOICE') => $this->generateBecIocs(),
            default => $this->generateGenericIocs(),
        };
    }

    /** @return array<string, mixed> */
    private function generatePhishingIocs(): array
    {
        return [
            'urls' => [
                $this->generateSuspiciousUrl('login'),
                $this->generateSuspiciousUrl('verify'),
            ],
            'emails' => [
                $this->generateSpoofedEmail('support'),
                $this->generateSpoofedEmail('security'),
            ],
            'phones' => [$this->generatePhone('toll-free')],
            'ibans' => [],
            'message_ids' => [$this->generateMessageId()],
        ];
    }

    /** @return array<string, mixed> */
    private function generateRomanceIocs(): array
    {
        return [
            'urls' => [],
            'emails' => [
                $this->generatePersonalEmail(),
            ],
            'phones' => [
                $this->generatePhone('international'),
            ],
            'ibans' => [],
            'money_transfer' => [
                'service' => $this->randomChoice(['Western Union', 'MoneyGram', 'Wise']),
                'recipient' => $this->generateName(),
                'country' => $this->randomChoice(['Ghana', 'Nigeria', 'Philippines', 'Russia']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function generateTechSupportIocs(): array
    {
        return [
            'urls' => [
                $this->generateSuspiciousUrl('support'),
                'anydesk.com/download', // Real remote desktop tool used by scammers
            ],
            'emails' => [
                $this->generateSpoofedEmail('techsupport'),
            ],
            'phones' => [
                $this->generatePhone('toll-free'),
                $this->generatePhone('international'),
            ],
            'ibans' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function generateInvestmentIocs(): array
    {
        return [
            'urls' => [
                $this->generateSuspiciousUrl('invest'),
                $this->generateSuspiciousUrl('trading'),
            ],
            'emails' => [
                $this->generateBusinessEmail(),
            ],
            'phones' => [
                $this->generatePhone('business'),
            ],
            'ibans' => [
                $this->generateIban(),
            ],
            'crypto_wallets' => [
                $this->generateBitcoinAddress(),
                $this->generateEthereumAddress(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function generateBecIocs(): array
    {
        return [
            'urls' => [],
            'emails' => [
                $this->generateBusinessEmail(),
                $this->generateSpoofedEmail('ceo'),
            ],
            'phones' => [
                $this->generatePhone('business'),
            ],
            'ibans' => [
                $this->generateIban(),
                $this->generateIban(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function generateGenericIocs(): array
    {
        return [
            'urls' => [$this->generateSuspiciousUrl()],
            'emails' => [$this->generatePersonalEmail()],
            'phones' => [$this->generatePhone()],
            'ibans' => [$this->generateIban()],
            'message_ids' => [$this->generateMessageId()],
        ];
    }

    /**
     * Génère un numéro de téléphone réaliste
     */
    private function generatePhone(string $type = 'generic'): string
    {
        return match ($type) {
            'toll-free' => sprintf('+1-800-%03d-%04d', random_int(100, 999), random_int(1000, 9999)),
            'business' => sprintf('+33 1 %02d %02d %02d %02d', random_int(10, 99), random_int(10, 99), random_int(10, 99), random_int(10, 99)),
            'international' => $this->randomChoice(self::PHONE_PREFIXES) . ' ' . $this->generatePhoneNumber(),
            default => $this->randomChoice(self::PHONE_PREFIXES) . ' ' . $this->generatePhoneNumber(),
        };
    }

    private function generatePhoneNumber(): string
    {
        return sprintf('%02d %02d %02d %02d', random_int(10, 99), random_int(10, 99), random_int(10, 99), random_int(10, 99));
    }

    /**
     * Génère un IBAN réaliste (mais invalide)
     */
    private function generateIban(): string
    {
        $countries = ['FR', 'DE', 'GB', 'IT', 'ES', 'NL', 'BE'];
        $country = $this->randomChoice($countries);
        $checkDigits = sprintf('%02d', random_int(10, 99));

        $accountNumber = match ($country) {
            'FR' => sprintf('%05d%05d%011d%02d', random_int(10000, 99999), random_int(10000, 99999), random_int(0, 99999999999), random_int(10, 99)),
            'DE' => sprintf('%08d%010d', random_int(10000000, 99999999), random_int(0, 9999999999)),
            default => strtoupper(bin2hex(random_bytes(10))),
        };

        return $country . $checkDigits . $accountNumber;
    }

    /**
     * Génère une adresse Bitcoin réaliste (mais non valide)
     */
    private function generateBitcoinAddress(): string
    {
        $chars = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $address = '1'; // Bitcoin addresses start with 1, 3, or bc1

        for ($i = 0; $i < 33; $i++) {
            $address .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $address;
    }

    /**
     * Generates a realistic (but invalid) Ethereum address
     */
    private function generateEthereumAddress(): string
    {
        return '0x' . bin2hex(random_bytes(20));
    }

    /**
     * Generates a realistic email Message-ID header
     */
    private function generateMessageId(): string
    {
        $domains = ['mail.gmail.com', 'outlook.com', 'yahoo.com', 'protonmail.ch'];
        $domain = $this->randomChoice($domains);
        $id = bin2hex(random_bytes(16));

        return sprintf('<%s@%s>', $id, $domain);
    }

    /**
     * Generates a suspicious URL
     */
    private function generateSuspiciousUrl(string $context = ''): string
    {
        $domain = $this->randomChoice(self::URL_SUSPICIOUS_DOMAINS);
        $subdomain = $context ?: $this->randomChoice(['secure', 'account', 'verify', 'update']);
        $path = $this->randomChoice(['login', 'verify', 'confirm', 'update']);
        $token = bin2hex(random_bytes(8));

        return sprintf('https://%s.%s/%s?token=%s', $subdomain, $domain, $path, $token);
    }

    /**
     * Génère un email personnel
     */
    private function generatePersonalEmail(): string
    {
        $firstNames = ['john', 'mary', 'david', 'sarah', 'michael', 'lisa', 'james', 'emma'];
        $lastNames = ['smith', 'johnson', 'williams', 'brown', 'jones', 'garcia', 'miller'];

        $firstName = $this->randomChoice($firstNames);
        $lastName = $this->randomChoice($lastNames);
        $domain = $this->randomChoice(self::EMAIL_DOMAINS);
        $number = random_int(10, 99);

        return sprintf('%s.%s%d@%s', $firstName, $lastName, $number, $domain);
    }

    /**
     * Génère un email professionnel
     */
    private function generateBusinessEmail(): string
    {
        $companies = ['techcorp', 'globalinvest', 'financeplus', 'tradingpro', 'cryptomax'];
        $names = ['john', 'sarah', 'david', 'lisa', 'michael'];
        $tlds = ['com', 'net', 'io', 'co'];

        $name = $this->randomChoice($names);
        $company = $this->randomChoice($companies);
        $tld = $this->randomChoice($tlds);

        return sprintf('%s@%s.%s', $name, $company, $tld);
    }

    /**
     * Génère un email d'usurpation (spoofed)
     */
    private function generateSpoofedEmail(string $role = ''): string
    {
        $prefixes = [
            'support' => ['support', 'no-reply', 'noreply', 'service'],
            'security' => ['security', 'alert', 'verification', 'account'],
            'ceo' => ['ceo', 'president', 'director', 'admin'],
            'techsupport' => ['tech', 'helpdesk', 'support', 'it'],
        ];

        $prefix = $this->randomChoice($prefixes[$role] ?? ['no-reply']);
        $domain = $this->randomChoice(self::URL_SUSPICIOUS_DOMAINS);

        return sprintf('%s@%s', $prefix, $domain);
    }

    /**
     * Génère un nom de personne
     */
    private function generateName(): string
    {
        $firstNames = ['Michael', 'Sarah', 'David', 'Jennifer', 'James', 'Emma', 'Robert', 'Lisa'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis'];

        return sprintf('%s %s', $this->randomChoice($firstNames), $this->randomChoice($lastNames));
    }

    /**
     * @template T
     *
     * @param array<int|string, T> $options
     *
     * @return T
     */
    private function randomChoice(array $options): mixed
    {
        return $options[array_rand($options)];
    }
}
