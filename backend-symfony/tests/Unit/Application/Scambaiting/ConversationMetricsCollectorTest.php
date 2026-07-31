<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Scambaiting;

use App\Application\Communication\IocHandler;
use App\Application\Scambaiting\ConversationMetricsCollector;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stub for IocHandler extends the real class to allow type compatibility.
 */
class IocHandlerStub extends IocHandler
{
    /** @var array<ObservedIoc> */
    private array $iocs = [];

    public function __construct()
    {
        // Don't call parent constructor - we don't need real dependencies
    }

    /** @param array<ObservedIoc> $iocs */
    public function setIocs(array $iocs): void
    {
        $this->iocs = $iocs;
    }

    /** @return array<ObservedIoc> */
    public function getConversationIocs(string $convId, bool $actionableOnly = false): array
    {
        // stub mirrors the new IocHandler signature.
        // The metrics collector still requests the full IOC set
        // (actionableOnly=false), so this stub ignores the flag.
        return $this->iocs;
    }
}

class ConversationMetricsCollectorTest extends TestCase
{
    private IocHandlerStub $iocHandler;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->iocHandler = new IocHandlerStub();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createCollector(): ConversationMetricsCollector
    {
        // Use reflection to inject stub since IocHandler is final
        $reflection = new \ReflectionClass(ConversationMetricsCollector::class);
        $constructor = $reflection->getConstructor();
        $collector = $reflection->newInstanceWithoutConstructor();

        $iocHandlerProp = $reflection->getProperty('iocHandler');
        $iocHandlerProp->setValue($collector, $this->iocHandler);

        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setValue($collector, $this->logger);

        return $collector;
    }

    public function testCollectReturnsConversationMetricsWithAllData(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-123',
            durationSec: 450,
            turnsCount: 12,
            status: ConversationStatus::CLOSED
        );

        $iocs = [
            $this->createMockIoc('email', 'scammer@evil.com'),
            $this->createMockIoc('IBAN', 'FR76...'),
            $this->createMockIoc('phone', '+33...'),
            $this->createMockIoc('url', 'http://phishing.com'),
            $this->createMockIoc('crypto_wallet', 'bc1q...'),
        ];

        $this->iocHandler->setIocs($iocs);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert
        $this->assertSame(450, $metrics->getDurationSec());
        $this->assertSame(5, $metrics->getIocsTotal());
        $this->assertSame(4, $metrics->getIocsSensibles()); // IBAN, phone, url, crypto_wallet
        $this->assertTrue($metrics->isCompleted());
    }

    public function testCollectHandlesClosedStatus(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-456',
            durationSec: 120,
            turnsCount: 5,
            status: ConversationStatus::CLOSED
        );

        $this->iocHandler->setIocs([]);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert
        $this->assertTrue($metrics->isCompleted(), 'CLOSED status should be considered completed');
    }

    public function testCollectHandlesOpenStatus(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-789',
            durationSec: 60,
            turnsCount: 3,
            status: ConversationStatus::OPEN
        );

        $this->iocHandler->setIocs([]);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert
        // isCompleted is always true (set at closure time, not based on status)
        $this->assertTrue($metrics->isCompleted());
    }

    public function testCollectCountsSensitiveIocsCorrectly(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-abc',
            durationSec: 300,
            turnsCount: 8,
            status: ConversationStatus::CLOSED
        );

        $iocs = [
            $this->createMockIoc('email', 'test@test.com'),       // not sensitive
            $this->createMockIoc('IBAN', 'FR76...'),              // sensitive
            $this->createMockIoc('phone', '+33...'),              // sensitive
            $this->createMockIoc('url', 'http://evil.com'),       // sensitive
            $this->createMockIoc('crypto_wallet', 'bc1q...'),     // sensitive
            $this->createMockIoc('IBAN', 'DE89...'),              // sensitive
            $this->createMockIoc('ip', '192.168.1.1'),            // not sensitive
        ];

        $this->iocHandler->setIocs($iocs);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert
        $this->assertSame(7, $metrics->getIocsTotal());
        $this->assertSame(5, $metrics->getIocsSensibles()); // 2 IBAN + 1 phone + 1 url + 1 crypto_wallet
    }

    public function testCollectHandlesEmptyIocs(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-def',
            durationSec: 30,
            turnsCount: 2,
            status: ConversationStatus::OPEN
        );

        $this->iocHandler->setIocs([]);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert
        $this->assertSame(0, $metrics->getIocsTotal());
        $this->assertSame(0, $metrics->getIocsSensibles());
    }

    public function testCollectHandlesZeroDuration(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-ghi',
            durationSec: 0,
            turnsCount: 1,
            status: ConversationStatus::OPEN
        );

        $this->iocHandler->setIocs([]);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert
        $this->assertSame(0, $metrics->getDurationSec());
        // isCompleted is always true (set at closure time, not based on status)
        $this->assertTrue($metrics->isCompleted());
    }

    public function testCollectCountsTelegramAndUrlAsSensitive(): void
    {
        $conversation = $this->createMockConversation(
            convId: 'conv-tg-url',
            durationSec: 200,
            turnsCount: 6,
            status: ConversationStatus::CLOSED
        );

        $iocs = [
            $this->createMockIoc('telegram_username', '@scammer_bot'),  // sensitive
            $this->createMockIoc('url', 'https://payment.evil.com'),    // sensitive
            $this->createMockIoc('domain', 'evil.com'),                 // not sensitive
        ];

        $this->iocHandler->setIocs($iocs);
        $collector = $this->createCollector();

        $metrics = $collector->collect($conversation);

        $this->assertSame(3, $metrics->getIocsTotal());
        $this->assertSame(2, $metrics->getIocsSensibles()); // telegram_username + url
    }

    public function testCollectHandlesMalformedIocContext(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-jkl',
            durationSec: 100,
            turnsCount: 4,
            status: ConversationStatus::OPEN
        );

        $iocs = [
            $this->createMockIoc('IBAN', 'FR76...'),          // valid sensitive
            $this->createMockIocWithContext([]),              // missing 'type' key
            $this->createMockIocWithContext(['type' => null]), // null type
            $this->createMockIoc('phone', '+33...'),          // valid sensitive
        ];

        $this->iocHandler->setIocs($iocs);
        $collector = $this->createCollector();

        // Act
        $metrics = $collector->collect($conversation);

        // Assert: Only valid sensitive IOCs should be counted
        $this->assertSame(4, $metrics->getIocsTotal());
        $this->assertSame(2, $metrics->getIocsSensibles()); // Only IBAN and phone
    }

    private function createMockConversation(
        string $convId,
        int $durationSec,
        int $turnsCount,
        ConversationStatus $status
    ): Conversation {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn($convId);
        $conversation->method('getEngagementDurationSec')->willReturn($durationSec);
        $conversation->method('getTurnsCount')->willReturn($turnsCount);
        $conversation->method('getStatus')->willReturn($status);
        return $conversation;
    }

    private function createMockIoc(string $type, string $value): ObservedIoc
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn([
            'type' => $type,
            'value' => $value,
        ]);
        return $ioc;
    }

    private function createMockIocWithContext(array $context): ObservedIoc
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn($context);
        return $ioc;
    }
}
