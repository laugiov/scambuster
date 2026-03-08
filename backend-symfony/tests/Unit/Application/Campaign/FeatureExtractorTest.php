<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\FeatureExtractor;
use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires complets pour FeatureExtractor
 *
 * Couvre:
 * - Extraction basique des 3 types de features
 * - Edge cases (textes vides, null, très longs, caractères spéciaux)
 * - Sécurité (XSS, SQL injection, defanging URLs)
 * - Normalisation (HTML, emojis, Unicode)
 * - Cohérence (simhash identique pour textes identiques)
 * - Features infrastructure (URLs, DKIM/SPF, domaines)
 * - Features stylistiques (ponctuation, formalité)
 */
final class FeatureExtractorTest extends TestCase
{
    private FeatureExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FeatureExtractor();
    }

    // ==================== Tests Basiques ====================

    public function testExtractReturnsThreeFeatureGroups(): void
    {
        $message = $this->createMockMessage('Hello', 'This is a test message.');

        $features = $this->extractor->extract($message);

        $this->assertArrayHasKey('text', $features);
        $this->assertArrayHasKey('infra', $features);
        $this->assertArrayHasKey('style', $features);
    }

    public function testTextFeaturesContainExpectedKeys(): void
    {
        $message = $this->createMockMessage('Subject', 'Body text');

        $features = $this->extractor->extract($message);

        $this->assertArrayHasKey('subject', $features['text']);
        $this->assertArrayHasKey('simhash', $features['text']);
        $this->assertArrayHasKey('ngrams', $features['text']);
        $this->assertArrayHasKey('body_normalized', $features['text']);
        $this->assertIsString($features['text']['simhash']);
        $this->assertIsArray($features['text']['ngrams']);
    }

    public function testInfraFeaturesContainExpectedKeys(): void
    {
        $message = $this->createMockMessage('Test', 'Body', headers: ['auth' => ['dkim' => true, 'spf' => false]]);

        $features = $this->extractor->extract($message);

        $this->assertArrayHasKey('url_domains', $features['infra']);
        $this->assertArrayHasKey('domain_ages', $features['infra']);
        $this->assertArrayHasKey('dkim', $features['infra']);
        $this->assertArrayHasKey('spf', $features['infra']);
        $this->assertArrayHasKey('mx_provider', $features['infra']);
        $this->assertTrue($features['infra']['dkim']);
        $this->assertFalse($features['infra']['spf']);
    }

    public function testStyleFeaturesContainExpectedKeys(): void
    {
        $message = $this->createMockMessage('Test', 'Hello! This is formal text with punctuation.');

        $features = $this->extractor->extract($message);

        $this->assertArrayHasKey('punct_ratio', $features['style']);
        $this->assertArrayHasKey('avg_sentence_len', $features['style']);
        $this->assertArrayHasKey('formality_score', $features['style']);
        $this->assertIsFloat($features['style']['punct_ratio']);
        $this->assertGreaterThan(0.0, $features['style']['punct_ratio']);
    }

    // ==================== Tests Simhash ====================

    public function testSimhashIsConsistentForSameText(): void
    {
        $message1 = $this->createMockMessage('Hello World', 'This is test');
        $message2 = $this->createMockMessage('Hello World', 'This is test');

        $features1 = $this->extractor->extract($message1);
        $features2 = $this->extractor->extract($message2);

        $this->assertSame($features1['text']['simhash'], $features2['text']['simhash']);
    }

    public function testSimhashIsMD5Format(): void
    {
        $message = $this->createMockMessage('Test', 'Body');

        $features = $this->extractor->extract($message);

        $simhash = $features['text']['simhash'];
        $this->assertSame(32, strlen($simhash), 'Simhash should be 32 chars (MD5)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $simhash, 'Simhash should be hexadecimal');
    }

    public function testSimhashIsCaseInsensitive(): void
    {
        $message1 = $this->createMockMessage('URGENT ALERT', 'YOUR ACCOUNT IS SUSPENDED');
        $message2 = $this->createMockMessage('urgent alert', 'your account is suspended');

        $features1 = $this->extractor->extract($message1);
        $features2 = $this->extractor->extract($message2);

        $this->assertSame($features1['text']['simhash'], $features2['text']['simhash'], 'Simhash should be case-insensitive');
    }

    public function testSimhashHandlesExtraWhitespace(): void
    {
        $message1 = $this->createMockMessage('Test', 'Hello    World');
        $message2 = $this->createMockMessage('Test', 'Hello World');

        $features1 = $this->extractor->extract($message1);
        $features2 = $this->extractor->extract($message2);

        $this->assertSame($features1['text']['simhash'], $features2['text']['simhash'], 'Simhash should normalize whitespace');
    }

    // ==================== Tests Edge Cases - Textes Vides/Null ====================

    public function testExtractWithEmptySubject(): void
    {
        $message = $this->createMockMessage('', 'Body content');

        $features = $this->extractor->extract($message);

        $this->assertSame('', $features['text']['subject']);
        $this->assertIsString($features['text']['simhash']);
        $this->assertNotEmpty($features['text']['ngrams']);
    }

    public function testExtractWithEmptyBody(): void
    {
        $message = $this->createMockMessage('Subject', '');

        $features = $this->extractor->extract($message);

        $this->assertSame('', $features['text']['body_normalized']);
        $this->assertIsString($features['text']['simhash']);
    }

    public function testExtractWithNullSubjectAndBody(): void
    {
        // Note: Les méthodes retournent string vide au lieu de null car la signature l'exige
        // En production, Message entity garantit que getSubject/getBodyText ne retournent jamais null
        $message = $this->createMockMessage('', '');

        $features = $this->extractor->extract($message);

        $this->assertIsString($features['text']['simhash']);
        $this->assertIsArray($features['text']['ngrams']);
        $this->assertSame(0.0, $features['style']['punct_ratio']);
    }

    // ==================== Tests HTML Stripping ====================

    public function testExtractStripsHtmlTags(): void
    {
        $html = '<html><body><h1>Title</h1><p>Paragraph with <b>bold</b> text.</p></body></html>';
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn('Test');
        $message->method('getBodyText')->willReturn('');
        $message->method('getBodyHtml')->willReturn($html);
        $message->method('getHeaders')->willReturn(['auth' => ['dkim' => false, 'spf' => false]]);

        $features = $this->extractor->extract($message);

        $bodyNormalized = $features['text']['body_normalized'];
        $this->assertStringNotContainsString('<html>', $bodyNormalized);
        $this->assertStringNotContainsString('<body>', $bodyNormalized);
        $this->assertStringContainsString('Title', $bodyNormalized);
        $this->assertStringContainsString('Paragraph', $bodyNormalized);
    }

    public function testExtractRemovesScriptTags(): void
    {
        $html = '<p>Good content</p><script>alert("XSS")</script><p>More content</p>';
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn('Test');
        $message->method('getBodyText')->willReturn('');
        $message->method('getBodyHtml')->willReturn($html);
        $message->method('getHeaders')->willReturn(['auth' => ['dkim' => false, 'spf' => false]]);

        $features = $this->extractor->extract($message);

        $bodyNormalized = $features['text']['body_normalized'];
        $this->assertStringNotContainsString('<script>', $bodyNormalized);
        $this->assertStringNotContainsString('alert', $bodyNormalized);
        $this->assertStringContainsString('Good content', $bodyNormalized);
    }

    public function testExtractRemovesStyleTags(): void
    {
        $html = '<p>Content</p><style>body { color: red; }</style>';
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn('Test');
        $message->method('getBodyText')->willReturn('');
        $message->method('getBodyHtml')->willReturn($html);
        $message->method('getHeaders')->willReturn(['auth' => ['dkim' => false, 'spf' => false]]);

        $features = $this->extractor->extract($message);

        $bodyNormalized = $features['text']['body_normalized'];
        $this->assertStringNotContainsString('<style>', $bodyNormalized);
        $this->assertStringNotContainsString('color: red', $bodyNormalized);
    }

    // ==================== Tests URL Defanging ====================

    public function testExtractDefangsHttpUrls(): void
    {
        $body = 'Click here: http://evil.com/phishing';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertStringContainsString('hxxp://', $features['text']['body_normalized']);
        $this->assertStringNotContainsString('http://', $features['text']['body_normalized']);
    }

    public function testExtractDefangsHttpsUrls(): void
    {
        $body = 'Secure phishing: https://paypal-verify.scam.com/login';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertStringContainsString('hxxps://', $features['text']['body_normalized']);
        $this->assertStringNotContainsString('https://', $features['text']['body_normalized']);
    }

    public function testExtractDefangsMultipleUrls(): void
    {
        $body = 'Visit http://site1.com and https://site2.com for more info';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $bodyNormalized = $features['text']['body_normalized'];
        $this->assertStringContainsString('hxxp://site1.com', $bodyNormalized);
        $this->assertStringContainsString('hxxps://site2.com', $bodyNormalized);
    }

    // ==================== Tests URL Extraction ====================

    public function testExtractFindsUrlsInBody(): void
    {
        $body = 'Visit https://example.com and http://test.org for details';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $urlDomains = $features['infra']['url_domains'];
        $this->assertContains('example.com', $urlDomains);
        $this->assertContains('test.org', $urlDomains);
    }

    public function testExtractHandlesNoUrls(): void
    {
        $body = 'No URLs in this message, just plain text.';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertEmpty($features['infra']['url_domains']);
    }

    public function testExtractHandlesMalformedUrls(): void
    {
        $body = 'Visit hxxp://already-defanged[.]com or example dot com';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        // Should not extract defanged or non-standard URLs
        $this->assertEmpty($features['infra']['url_domains']);
    }

    public function testExtractHandlesUrlsWithPaths(): void
    {
        $body = 'https://phishing.com/path/to/page?param=value&foo=bar';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertContains('phishing.com', $features['infra']['url_domains']);
    }

    // ==================== Tests N-grams ====================

    public function testExtractGeneratesNgrams(): void
    {
        $message = $this->createMockMessage('Test', 'Hello World');

        $features = $this->extractor->extract($message);

        $ngrams = $features['text']['ngrams'];
        $this->assertIsArray($ngrams);
        $this->assertNotEmpty($ngrams);
    }

    public function testNgramsAreUnique(): void
    {
        $message = $this->createMockMessage('Test', 'aaa aaa aaa');

        $features = $this->extractor->extract($message);

        $ngrams = $features['text']['ngrams'];
        $this->assertSame(count($ngrams), count(array_unique($ngrams)), 'N-grams should be unique');
    }

    public function testNgramsAreLowercase(): void
    {
        $message = $this->createMockMessage('Test', 'URGENT Alert');

        $features = $this->extractor->extract($message);

        $ngrams = $features['text']['ngrams'];
        foreach ($ngrams as $ngram) {
            $this->assertSame($ngram, mb_strtolower($ngram), 'N-grams should be lowercase');
        }
    }

    // ==================== Tests DKIM/SPF ====================

    public function testExtractReadsAuthHeadersDkim(): void
    {
        $headers = ['auth' => ['dkim' => true, 'spf' => false]];
        $message = $this->createMockMessage('Test', 'Body', headers: $headers);

        $features = $this->extractor->extract($message);

        $this->assertTrue($features['infra']['dkim']);
        $this->assertFalse($features['infra']['spf']);
    }

    public function testExtractHandlesMissingAuthHeaders(): void
    {
        $headers = [];
        $message = $this->createMockMessage('Test', 'Body', headers: $headers);

        $features = $this->extractor->extract($message);

        $this->assertFalse($features['infra']['dkim']);
        $this->assertFalse($features['infra']['spf']);
    }

    public function testExtractHandlesPartialAuthHeaders(): void
    {
        $headers = ['auth' => ['dkim' => true]]; // SPF manquant
        $message = $this->createMockMessage('Test', 'Body', headers: $headers);

        $features = $this->extractor->extract($message);

        $this->assertTrue($features['infra']['dkim']);
        $this->assertFalse($features['infra']['spf']);
    }

    // ==================== Tests Style Features ====================

    public function testPunctuationRatioCalculation(): void
    {
        $body = 'Hello! How are you? Nice day, right.';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $punctRatio = $features['style']['punct_ratio'];
        $this->assertGreaterThan(0.0, $punctRatio);
        $this->assertLessThan(1.0, $punctRatio);
    }

    public function testPunctuationRatioForNoPunctuation(): void
    {
        $body = 'No punctuation here at all';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertSame(0.0, $features['style']['punct_ratio']);
    }

    public function testPunctuationRatioForHighPunctuation(): void
    {
        $body = '!!!URGENT!!! ACT NOW!!!';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertGreaterThan(0.3, $features['style']['punct_ratio'], 'High punctuation ratio expected');
    }

    public function testAvgSentenceLengthCalculation(): void
    {
        $body = 'Short sentence. Another short one. And a third.';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $avgLen = $features['style']['avg_sentence_len'];
        $this->assertGreaterThan(0.0, $avgLen);
    }

    public function testAvgSentenceLengthForNoSentences(): void
    {
        $body = '';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertSame(0.0, $features['style']['avg_sentence_len']);
    }

    public function testFormalityScoreCalculation(): void
    {
        $formal = 'Distinguished professionals require comprehensive documentation.';
        $informal = 'Hey yo check this out bro lol';

        $message1 = $this->createMockMessage('Test', $formal);
        $message2 = $this->createMockMessage('Test', $informal);

        $features1 = $this->extractor->extract($message1);
        $features2 = $this->extractor->extract($message2);

        $this->assertGreaterThan(
            $features2['style']['formality_score'],
            $features1['style']['formality_score'],
            'Formal text should have higher formality score'
        );
    }

    public function testFormalityScoreForEmptyText(): void
    {
        $message = $this->createMockMessage('Test', '');

        $features = $this->extractor->extract($message);

        $this->assertSame(0.0, $features['style']['formality_score']);
    }

    // ==================== Tests Unicode & Special Characters ====================

    public function testExtractHandlesUnicodeText(): void
    {
        $body = 'こんにちは世界 🌍 Привет мир';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertIsString($features['text']['simhash']);
        $this->assertIsArray($features['text']['ngrams']);
    }

    public function testExtractHandlesEmojis(): void
    {
        $body = '🚨 URGENT 🚨 Your account 💰 needs verification ✅';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertStringContainsString('URGENT', $features['text']['body_normalized']);
    }

    public function testExtractHandlesSpecialCharacters(): void
    {
        $body = 'Special chars: ñ, é, ü, ç, ø, ł, ß';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertIsString($features['text']['simhash']);
    }

    // ==================== Tests Scénarios Réalistes - Phishing ====================

    public function testPayPalPhishingScenario(): void
    {
        $subject = 'URGENT: Your PayPal Account Has Been Suspended';
        $body = 'Dear user, Your PayPal account has been suspended due to suspicious activity. ' .
                'Click here to verify your identity: http://paypal-verify.scam.com/login';

        $headers = ['auth' => ['dkim' => false, 'spf' => false]];
        $message = $this->createMockMessage($subject, $body, headers: $headers);

        $features = $this->extractor->extract($message);

        // Text features
        $this->assertStringContainsString('URGENT', $features['text']['subject']);
        $this->assertNotEmpty($features['text']['ngrams']);

        // Infra features
        $this->assertContains('paypal-verify.scam.com', $features['infra']['url_domains']);
        $this->assertFalse($features['infra']['dkim'], 'Phishing should fail DKIM');
        $this->assertFalse($features['infra']['spf'], 'Phishing should fail SPF');

        // Style features (urgency = high punctuation)
        $this->assertGreaterThan(0.0, $features['style']['punct_ratio']);
    }

    public function testBankPhishingScenario(): void
    {
        $subject = 'Action Required: Verify Your Bank Account';
        $body = 'We have detected unusual activity on your account. ' .
                'Please verify your information immediately at https://secure-bank-verify.evil.com/auth';

        $message = $this->createMockMessage($subject, $body);

        $features = $this->extractor->extract($message);

        $this->assertContains('secure-bank-verify.evil.com', $features['infra']['url_domains']);
        $this->assertStringContainsString('hxxps://', $features['text']['body_normalized']);
    }

    public function testAmazonPhishingScenario(): void
    {
        $subject = 'Your Amazon Prime Membership is Expiring';
        $body = 'Your Amazon Prime membership will expire in 24 hours! ' .
                'Renew now to avoid service interruption: http://amazon-renew.scam.net/pay';

        $message = $this->createMockMessage($subject, $body);

        $features = $this->extractor->extract($message);

        $this->assertContains('amazon-renew.scam.net', $features['infra']['url_domains']);
        $this->assertGreaterThan(0.0, $features['style']['avg_sentence_len']);
    }

    // ==================== Tests Scénarios Extrêmes ====================

    public function testVeryLongText(): void
    {
        $longBody = str_repeat('This is a very long message. ', 1000);
        $message = $this->createMockMessage('Test', $longBody);

        $features = $this->extractor->extract($message);

        $this->assertIsString($features['text']['simhash']);
        $this->assertSame(32, strlen($features['text']['simhash']));
    }

    public function testVeryShortText(): void
    {
        $message = $this->createMockMessage('Hi', 'OK');

        $features = $this->extractor->extract($message);

        $this->assertIsString($features['text']['simhash']);
        $this->assertIsArray($features['text']['ngrams']);
    }

    public function testTextWithOnlyWhitespace(): void
    {
        $message = $this->createMockMessage('   ', '     ');

        $features = $this->extractor->extract($message);

        $this->assertIsString($features['text']['simhash']);
    }

    public function testTextWithManyUrls(): void
    {
        $body = implode(' ', array_map(
            fn($i) => "http://site$i.com",
            range(1, 50)
        ));
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertCount(50, $features['infra']['url_domains']);
    }

    // ==================== Tests Sécurité - XSS & Injection ====================

    public function testXssInSubject(): void
    {
        $subject = '<script>alert("XSS")</script>';
        $message = $this->createMockMessage($subject, 'Body');

        $features = $this->extractor->extract($message);

        // XSS should be stored as-is in features (no execution in backend)
        $this->assertStringContainsString('script', $features['text']['subject']);
    }

    public function testSqlInjectionInBody(): void
    {
        $body = "'; DROP TABLE users; --";
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        // SQL injection should be stored safely
        $this->assertStringContainsString('DROP TABLE', $features['text']['body_normalized']);
    }

    public function testPathTraversalInUrls(): void
    {
        $body = 'Visit http://evil.com/../../etc/passwd for details';
        $message = $this->createMockMessage('Test', $body);

        $features = $this->extractor->extract($message);

        $this->assertContains('evil.com', $features['infra']['url_domains']);
    }

    // ==================== Helper Methods ====================

    private function createMockMessage(
        string $subject,
        string $body,
        ?array $headers = null
    ): Message {
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn($subject);
        $message->method('getBodyText')->willReturn($body);
        $message->method('getBodyHtml')->willReturn(null);
        $message->method('getHeaders')->willReturn($headers ?? ['auth' => ['dkim' => false, 'spf' => false]]);

        return $message;
    }
}
