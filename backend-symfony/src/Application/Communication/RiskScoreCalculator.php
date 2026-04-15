<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Stateless risk score calculator extracted from IngestPostProcessor.
 *
 * Used by both the real-time ingestion pipeline and the batch recalculation command.
 */
final class RiskScoreCalculator
{
    /**
     * Financial IOC types that warrant a higher risk bonus.
     *
     * @var list<string>
     */
    private const FINANCIAL_IOC_TYPES = ['iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'credit_card'];

    /** @var array<string, int> */
    private const BASE_SCORES = [
        'PHISHING' => 40, 'PHISH_CREDENTIALS' => 45, 'PHISH_MALWARE' => 65,
        'INVOICE_FRAUD' => 60, 'CEO_FRAUD' => 70, 'ROMANCE' => 30,
        'TECH_SUPPORT' => 35, 'INVESTMENT' => 50, 'LOTTERY' => 30,
        'ADVANCE_FEE_419' => 40, 'JOB_OFFER' => 35, 'CHARITY' => 25,
        'UNKNOWN' => 30,
    ];

    /**
     * Compute risk score from scam type code and set of IOC types present.
     *
     * @param string              $scamCode Scam type code (e.g. 'CHARITY')
     * @param array<string, bool> $iocTypes Map of IOC type => true for each type present
     * @param int                 $urlCount Number of URL IOCs observed
     */
    public function compute(string $scamCode, array $iocTypes, int $urlCount = 0): int
    {
        $score = self::BASE_SCORES[$scamCode] ?? 30;

        // Financial IOC bonus (+30 for first, +10 per additional type)
        $financialTypesPresent = array_filter(
            self::FINANCIAL_IOC_TYPES,
            fn (string $ft): bool => isset($iocTypes[$ft]),
        );
        $financialCount = \count($financialTypesPresent);

        if ($financialCount > 0) {
            $score += 30; // first financial IOC type
            $score += ($financialCount - 1) * 10; // +10 per additional type
        }

        // Phone bonus (+15)
        if (isset($iocTypes['phone'])) {
            $score += 15;
        }

        // URL bonus: +5 per URL (capped at 15)
        if (isset($iocTypes['url'])) {
            $score += min($urlCount * 5, 15);
        }

        // IOC diversity bonus: +10 if >= 4 types, otherwise +3 per type (capped at 15)
        $typeCount = \count($iocTypes);

        if ($typeCount >= 4) {
            $score += 10;
        }

        $score += min($typeCount * 3, 15);

        return min($score, 100);
    }
}
