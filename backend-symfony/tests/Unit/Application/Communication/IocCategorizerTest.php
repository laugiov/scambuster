<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocCategorizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IocCategorizer
 *
 * Tests the IOC mini-taxonomy.
 */
final class IocCategorizerTest extends TestCase
{
    private IocCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new IocCategorizer();
    }

    public function testGuessCategory_B2B_InvoiceChange_WithIban(): void
    {
        $category = $this->categorizer->guessCategory('FR7630006000011234567890189');

        $this->assertSame('B2B_invoice_change', $category);
    }

    public function testGuessCategory_B2B_InvoiceChange_WithRib(): void
    {
        $category = $this->categorizer->guessCategory(
            'https://example.com/download-rib',
            'Veuillez trouver ci-joint notre nouveau RIB pour les futurs paiements'
        );

        $this->assertSame('B2B_invoice_change', $category);
    }

    public function testGuessCategory_B2B_InvoiceChange_WithFacture(): void
    {
        $category = $this->categorizer->guessCategory(
            'https://facture-secure.example.com',
            ''
        );

        $this->assertSame('B2B_invoice_change', $category);
    }

    public function testGuessCategory_B2B_InvoiceChange_WithVirement(): void
    {
        $bodyText = 'Pour régler votre facture n° FAC-2025-16288, merci d\'effectuer un virement sur notre compte';

        $category = $this->categorizer->guessCategory('payment@example.com', $bodyText);

        $this->assertSame('B2B_invoice_change', $category);
    }

    public function testGuessCategory_GovImpersonation_WithGouvFr(): void
    {
        $category = $this->categorizer->guessCategory('https://impots.gouv.fr.secure-login.com');

        $this->assertSame('Gov_impersonation', $category);
    }

    public function testGuessCategory_GovImpersonation_WithAmeli(): void
    {
        $category = $this->categorizer->guessCategory(
            'https://connexion-ameli.com',
            'Votre compte Ameli nécessite une vérification'
        );

        $this->assertSame('Gov_impersonation', $category);
    }

    public function testGuessCategory_GovImpersonation_WithImpots(): void
    {
        $category = $this->categorizer->guessCategory(
            'notification@impots-validation.com',
            'Service des impots - remboursement en attente'
        );

        $this->assertSame('Gov_impersonation', $category);
    }

    public function testGuessCategory_GovImpersonation_WithPrefecture(): void
    {
        $category = $this->categorizer->guessCategory(
            'https://prefecture-online.com',
            ''
        );

        $this->assertSame('Gov_impersonation', $category);
    }

    public function testGuessCategory_CredentialPhish_WithLogin(): void
    {
        $category = $this->categorizer->guessCategory('https://microsoft-login-verify.com');

        $this->assertSame('Credential_phish', $category);
    }

    public function testGuessCategory_CredentialPhish_WithVerify(): void
    {
        $bodyText = 'Please verify your account by clicking the link below';

        $category = $this->categorizer->guessCategory('https://verify-account.com', $bodyText);

        $this->assertSame('Credential_phish', $category);
    }

    public function testGuessCategory_CredentialPhish_WithPassword(): void
    {
        $category = $this->categorizer->guessCategory(
            'reset@example.com',
            'Your password will expire in 24 hours. Please reset it immediately.'
        );

        $this->assertSame('Credential_phish', $category);
    }

    public function testGuessCategory_CredentialPhish_WithMicrosoft(): void
    {
        $category = $this->categorizer->guessCategory('https://microsoft-office365-login.com');

        $this->assertSame('Credential_phish', $category);
    }

    public function testGuessCategory_CredentialPhish_WithWebmail(): void
    {
        $category = $this->categorizer->guessCategory(
            'https://webmail-login.com',
            'Your webmail quota is full'
        );

        $this->assertSame('Credential_phish', $category);
    }

    public function testGuessCategory_DefaultToCredentialPhish(): void
    {
        // No specific keywords → defaults to Credential_phish
        $category = $this->categorizer->guessCategory(
            'https://suspicious-link.com',
            'Click here for amazing offer'
        );

        $this->assertSame('Credential_phish', $category);
    }

    public function testGuessCategory_CaseInsensitive(): void
    {
        $category1 = $this->categorizer->guessCategory('IBAN FR76300060000112345');
        $category2 = $this->categorizer->guessCategory('iban fr76300060000112345');
        $category3 = $this->categorizer->guessCategory('IbAn FR76300060000112345');

        $this->assertSame('B2B_invoice_change', $category1);
        $this->assertSame('B2B_invoice_change', $category2);
        $this->assertSame('B2B_invoice_change', $category3);
    }

    public function testGuessCategory_PrioritizesB2BOverCredential(): void
    {
        // Has both "login" (credential) and "facture" (B2B)
        // Should prioritize B2B as it's checked first
        $category = $this->categorizer->guessCategory(
            'https://login.example.com',
            'Votre facture est disponible'
        );

        $this->assertSame('B2B_invoice_change', $category);
    }

    public function testGuessCategory_PrioritizesGovOverCredential(): void
    {
        // Has both "verify" (credential) and "gouv" (government)
        // Should prioritize Gov as it's checked before Credential
        $category = $this->categorizer->guessCategory(
            'https://verify-account.gouv.fr',
            ''
        );

        $this->assertSame('Gov_impersonation', $category);
    }
}
