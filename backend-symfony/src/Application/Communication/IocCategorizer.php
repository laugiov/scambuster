<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Categorizes IOCs and messages using heuristics.
 *
 * Categories:
 * - B2B_invoice_change: Invoice/payment scams (RIB, IBAN, facture, virement)
 * - Credential_phish: Credential theft (login, verify, password, webmail)
 * - Gov_impersonation: Government impersonation (gouv, ameli, impots, prefecture)
 *
 * Purpose: Category is used to select appropriate LLM prompt for reply generation
 */
final class IocCategorizer
{
    /**
     * Guess category from IOC value and optional message body text
     *
     * Uses keyword-based heuristics (v0 - simple but effective)
     *
     * @param string $value    IOC value (URL, email, etc.)
     * @param string $bodyText Optional message body text for context
     *
     * @return 'B2B_invoice_change'|'Credential_phish'|'Gov_impersonation'
     */
    public function guessCategory(string $value, string $bodyText = ''): string
    {
        $combined = mb_strtolower($value . ' ' . $bodyText);

        // Gov_impersonation: French government keywords (checked first - higher priority)
        if ($this->containsAnyKeyword($combined, [
            'gouv.fr', 'gouv', 'ameli', 'impots', 'prefecture', 'cpam',
            'mairie', 'ministere', 'dgfip', 'tresor public', 'urssaf'
        ])) {
            return 'Gov_impersonation';
        }

        // B2B_invoice_change: Payment/invoice keywords
        // Detect IBAN pattern (FR followed by 2 digits) or specific keywords
        if ($this->containsAnyKeyword($combined, [
            'rib', 'iban', 'facture', 'invoice', 'payment', 'virement', 'bic',
            'paiement', 'transfert', 'swift', 'fr76', 'fr2', 'fr3', 'fr7'
        ])) {
            return 'B2B_invoice_change';
        }

        // Credential_phish: Authentication/account keywords
        if ($this->containsAnyKeyword($combined, [
            'login', 'verify', 'password', 'account', 'microsoft', 'office',
            'webmail', 'connexion', 'identifiant', 'mot de passe'
        ])) {
            return 'Credential_phish';
        }

        // Default: assume credential phishing (most common scam type)
        return 'Credential_phish';
    }

    /**
     * Check if text contains any of the given keywords
     *
     * @param string        $text     Lowercased text to search
     * @param array<string> $keywords Keywords to look for
     *
     * @return bool True if at least one keyword found
     */
    private function containsAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
