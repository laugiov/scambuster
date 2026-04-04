<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\EmailParsingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IngestHandlerTest extends TestCase
{
    private EmailParsingService $emailParser; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->emailParser = new EmailParsingService($logger);
    }

    /**
     * Helper method to access convertHtmlToText (now public on EmailParsingService)
     */
    private function invokeConvertHtmlToText(string $html): string
    {
        return $this->emailParser->convertHtmlToText($html);
    }

    public function test_convertHtmlToText_preserves_phone_numbers(): void
    {
        $html = '<div>Appelez le 07.89.65.43.21 pour plus d&#039;informations</div>';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringContainsString('07.89.65.43.21', $result);
        $this->assertStringNotContainsString('<div>', $result);
        $this->assertStringContainsString("d'informations", $result); // HTML entity decoded
    }

    public function test_convertHtmlToText_preserves_line_breaks(): void
    {
        $html = '<div>Line 1</div><p>Line 2</p><br />Line 3';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 2', $result);
        $this->assertStringContainsString('Line 3', $result);

        // Should have newlines between sections
        $lines = explode("\n", $result);
        $this->assertGreaterThanOrEqual(3, count($lines));
    }

    public function test_convertHtmlToText_removes_all_html_tags(): void
    {
        $html = '<strong>Bold</strong> <em>italic</em> <a href="http://example.com">link</a>';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringNotContainsString('<strong>', $result);
        $this->assertStringNotContainsString('<em>', $result);
        $this->assertStringNotContainsString('<a', $result);
        $this->assertStringContainsString('Bold', $result);
        $this->assertStringContainsString('italic', $result);
        $this->assertStringContainsString('link', $result);
    }

    public function test_convertHtmlToText_decodes_html_entities(): void
    {
        $html = 'Prix: 100&euro; &ndash; &copy; 2024 &amp; &#039;test&#039;';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringContainsString('€', $result);
        $this->assertStringContainsString('–', $result);
        $this->assertStringContainsString('©', $result);
        $this->assertStringContainsString('&', $result);
        $this->assertStringContainsString("'test'", $result);
    }

    public function test_convertHtmlToText_normalizes_whitespace(): void
    {
        $html = '<div>Multiple    spaces</div><p>  Leading spaces</p>';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringContainsString('Multiple spaces', $result); // Single space
        $this->assertStringNotContainsString('    ', $result); // No multiple spaces
    }

    public function test_convertHtmlToText_handles_lists(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li><li>Item 3</li></ul>';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringContainsString('Item 1', $result);
        $this->assertStringContainsString('Item 2', $result);
        $this->assertStringContainsString('Item 3', $result);
        // Should have bullet points
        $this->assertStringContainsString('•', $result);
    }

    public function test_convertHtmlToText_preserves_iocs(): void
    {
        $html = <<<HTML
<div>
    <p>Contactez-nous:</p>
    <ul>
        <li>Email: scammer@example.com</li>
        <li>Phone: +33 7 89 65 43 21</li>
        <li>Site: http://phishing-site.com</li>
        <li>IBAN: FR76 1234 5678 9012 3456 7890 123</li>
    </ul>
</div>
HTML;
        $result = $this->invokeConvertHtmlToText($html);

        // All IOCs should be preserved
        $this->assertStringContainsString('scammer@example.com', $result);
        $this->assertStringContainsString('+33 7 89 65 43 21', $result);
        $this->assertStringContainsString('http://phishing-site.com', $result);
        $this->assertStringContainsString('FR76 1234 5678 9012 3456 7890 123', $result);
    }

    public function test_convertHtmlToText_handles_empty_string(): void
    {
        $result = $this->invokeConvertHtmlToText('');
        $this->assertSame('', $result);
    }

    public function test_convertHtmlToText_handles_plain_text(): void
    {
        $plainText = 'This is plain text without HTML';
        $result = $this->invokeConvertHtmlToText($plainText);

        $this->assertSame(trim($plainText), $result);
    }

    public function test_convertHtmlToText_removes_script_tags(): void
    {
        $html = '<div>Content</div><script>alert("xss")</script><div>More content</div>';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Content', $result);
        $this->assertStringContainsString('More content', $result);
    }

    public function test_convertHtmlToText_handles_nested_tags(): void
    {
        $html = '<div><p><strong><em>Nested</em> text</strong></p></div>';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
        $this->assertStringContainsString('Nested text', $result);
    }

    public function test_convertHtmlToText_trims_output(): void
    {
        $html = '   <div>Content</div>   ';
        $result = $this->invokeConvertHtmlToText($html);

        $this->assertSame('Content', $result);
        $this->assertStringStartsNotWith(' ', $result);
        $this->assertStringEndsNotWith(' ', $result);
    }
}
