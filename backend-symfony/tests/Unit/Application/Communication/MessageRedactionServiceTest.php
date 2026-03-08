<?php

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\MessageRedactionService;
use PHPUnit\Framework\TestCase;

class MessageRedactionServiceTest extends TestCase
{
    public function testRedactHeaders()
    {
        $service = new MessageRedactionService();
        $headers = [
            'From' => 'attacker@example.com',
            'To' => 'victim@example.com',
            'Subject' => 'Hello',
            'X-Originating-IP' => '1.2.3.4',
            'Received' => 'by mail.example.com'
        ];
        $redacted = $service->redactHeaders($headers);
        $this->assertSame('[REDACTED]', $redacted['From']);
        $this->assertSame('[REDACTED]', $redacted['To']);
        $this->assertSame('[REDACTED]', $redacted['X-Originating-IP']);
        $this->assertSame('[REDACTED]', $redacted['Received']);
        $this->assertSame('Hello', $redacted['Subject']);
    }
} 