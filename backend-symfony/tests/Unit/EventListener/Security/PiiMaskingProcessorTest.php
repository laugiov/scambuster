<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener\Security;

use App\EventListener\Security\PiiMaskingProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class PiiMaskingProcessorTest extends TestCase
{
    private PiiMaskingProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new PiiMaskingProcessor();
    }

    public function testMasksEmailInMessage(): void
    {
        $record = $this->createRecord('Login failed for user@example.com');
        $result = ($this->processor)($record);

        $this->assertSame('Login failed for use***@example.com', $result->message);
    }

    public function testMasksMultipleEmailsInMessage(): void
    {
        $record = $this->createRecord('From scammer@evil.org to victim@bank.com');
        $result = ($this->processor)($record);

        $this->assertSame('From sca***@evil.org to vic***@bank.com', $result->message);
    }

    public function testMasksIpv4InMessage(): void
    {
        $record = $this->createRecord('Request from 192.168.1.42 denied');
        $result = ($this->processor)($record);

        $this->assertSame('Request from 192.168.1.*** denied', $result->message);
    }

    public function testMasksEmailInContext(): void
    {
        $record = $this->createRecord('Auth failed', ['email' => 'admin@scambuster.io']);
        $result = ($this->processor)($record);

        $this->assertSame('adm***@scambuster.io', $result->context['email']);
    }

    public function testMasksIpInContext(): void
    {
        $record = $this->createRecord('Rate limited', ['ip' => '10.0.0.55']);
        $result = ($this->processor)($record);

        $this->assertSame('10.0.0.***', $result->context['ip']);
    }

    public function testMasksNestedContextArray(): void
    {
        $record = $this->createRecord('Event', ['details' => ['sender' => 'bad@scam.net', 'ip' => '1.2.3.4']]);
        $result = ($this->processor)($record);

        $this->assertSame('bad***@scam.net', $result->context['details']['sender']);
        $this->assertSame('1.2.3.***', $result->context['details']['ip']);
    }

    public function testDoesNotMaskNonPiiStrings(): void
    {
        $record = $this->createRecord('Processing conversation conv-12345');
        $result = ($this->processor)($record);

        $this->assertSame('Processing conversation conv-12345', $result->message);
    }

    public function testDoesNotMaskShortEmailPrefix(): void
    {
        $record = $this->createRecord('Contact ab@test.com for info');
        $result = ($this->processor)($record);

        $this->assertSame('Contact ab***@test.com for info', $result->message);
    }

    public function testPreservesNonStringContextValues(): void
    {
        $record = $this->createRecord('Stats', ['count' => 42, 'active' => true, 'ratio' => 0.75]);
        $result = ($this->processor)($record);

        $this->assertSame(42, $result->context['count']);
        $this->assertTrue($result->context['active']);
        $this->assertSame(0.75, $result->context['ratio']);
    }

    public function testReturnsOriginalRecordWhenNoPii(): void
    {
        $record = $this->createRecord('Simple log message', ['key' => 'value']);
        $result = ($this->processor)($record);

        $this->assertSame($record, $result);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createRecord(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context,
        );
    }
}
