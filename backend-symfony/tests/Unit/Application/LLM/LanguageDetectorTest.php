<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\LanguageDetector;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    private LanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LanguageDetector();
    }

    public function testDetectsEnglish(): void
    {
        $text = 'Hello, I am writing to you regarding the invoice you sent me last week. I would like to discuss the payment terms and conditions before proceeding with the transfer.';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testDetectsFrench(): void
    {
        $text = 'Bonjour, je vous contacte au sujet de la facture que vous nous avez envoyee la semaine derniere. Je souhaiterais discuter des conditions de paiement avant de proceder au virement.';

        $this->assertSame('fr', $this->detector->detect($text));
    }

    public function testDetectsSpanish(): void
    {
        $text = 'Hola, le escribo con respecto a la factura que nos envio la semana pasada. Me gustaria hablar sobre las condiciones de pago antes de proceder con la transferencia.';

        $this->assertSame('es', $this->detector->detect($text));
    }

    public function testDetectsGerman(): void
    {
        $text = 'Guten Tag, ich schreibe Ihnen bezueglich der Rechnung, die Sie uns letzte Woche geschickt haben. Ich moechte die Zahlungsbedingungen besprechen, bevor ich mit der Ueberweisung fortfahre.';

        $this->assertSame('de', $this->detector->detect($text));
    }

    public function testShortTextDefaultsToEnglish(): void
    {
        $text = 'Hello there';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testEmptyTextDefaultsToEnglish(): void
    {
        $this->assertSame('en', $this->detector->detect(''));
    }

    public function testStripsHtmlTags(): void
    {
        $text = '<p>Hello, I am writing to you about the <b>important</b> payment that needs to be processed immediately.</p>';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testHandlesUnicodeAccents(): void
    {
        $text = 'Je vous remercie pour votre reponse rapide concernant cette situation delicate. Nous devons agir rapidement pour resoudre ce probleme ensemble.';

        $this->assertSame('fr', $this->detector->detect($text));
    }

    public function testRealScamEmailEnglish(): void
    {
        $text = 'Dear beneficiary, I am contacting you regarding your unclaimed inheritance of $5.2 million. Please provide your banking details for the wire transfer to be completed within 48 hours.';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testRealScamEmailFrench(): void
    {
        $text = 'Cher beneficiaire, je vous contacte au sujet de votre heritage non reclame de 5,2 millions de dollars. Veuillez fournir vos coordonnees bancaires pour que le virement soit effectue dans les 48 heures.';

        $this->assertSame('fr', $this->detector->detect($text));
    }

    public function testPhishingEmailEnglish(): void
    {
        $text = 'Dear Customer, We have detected unauthorized access to your account from IP address 198.51.100.42. For your protection, please verify your identity immediately. Failure to verify within 24 hours will result in permanent account suspension.';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testInvoiceFraudEmailEnglish(): void
    {
        $text = 'Dear Accounts Payable, Please be advised that our banking details have changed effective immediately. All outstanding and future payments should be redirected to our new account. Please process this payment promptly.';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testRomanceScamEmailEnglish(): void
    {
        $text = 'Hello my dear, I found your profile and I felt compelled to write. My name is Dr. Sarah Mitchell, I am a humanitarian worker in Eastern Europe. Life here is lonely and I am looking for genuine connection.';

        $this->assertSame('en', $this->detector->detect($text));
    }

    public function testAmbiguousShortTextDefaultsToEnglish(): void
    {
        $text = 'OK merci beaucoup'; // Very short, ambiguous

        $this->assertSame('en', $this->detector->detect($text));
    }
}
