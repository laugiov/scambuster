<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Communication\ReplyContextService;
use App\Domain\Communication\ObservedIoc;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Tests for the IOC context assembly in ReplyContextService.
 *
 * The IOC handler returns ObservedIoc domain entities; the service maps
 * them to the compact type/value/category shape ConversationAnalyzer
 * expects. A regression shipped a closure typed against arrays, so every
 * mapping call threw a TypeError that was swallowed at DEBUG level and
 * the generator silently lost all IOC context. These tests pin:
 *  - the mapping works against real ObservedIoc entities,
 *  - a load failure is logged at WARNING or above,
 *  - context assembly degrades gracefully (empty list, no throw).
 *
 * Uses Reflection to invoke the private mapping helper without
 * constructing the full EntityManager dependency graph (same pattern as
 * ReplyContextServiceDetectLanguageTest).
 */
class ReplyContextServiceIocContextTest extends TestCase
{
    /** @var list<array{level: string, message: string}> */
    private array $logRecords = [];

    private function buildService(IocHandler $iocHandler): ReplyContextService
    {
        $this->logRecords = [];
        $records = &$this->logRecords;

        $logger = new class($records) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        $ref = new \ReflectionClass(ReplyContextService::class);
        /** @var ReplyContextService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $ref->getProperty('logger')->setValue($service, $logger);
        $ref->getProperty('iocHandler')->setValue($service, $iocHandler);

        return $service;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function invokeFetchExtractedIocs(ReplyContextService $service, string $convId): array
    {
        $method = new \ReflectionMethod(ReplyContextService::class, 'fetchExtractedIocs');

        /** @var array<int, array<string, mixed>> $result */
        $result = $method->invoke($service, $convId);

        return $result;
    }

    private function makeObservedIoc(array $context): ObservedIoc
    {
        $message = $this->createMock(\App\Domain\Communication\Message::class);

        return new ObservedIoc(
            'a0000000-0000-0000-0000-00000000000a',
            $message,
            'b0000000-0000-0000-0000-00000000000b',
            $context,
        );
    }

    public function testMapsObservedIocEntitiesToTypeValueCategory(): void
    {
        $iocHandler = $this->createMock(IocHandler::class);
        $iocHandler->method('getConversationIocs')->willReturn([
            $this->makeObservedIoc(['type' => 'iban', 'value' => 'FR7630006000011234567890189']),
            $this->makeObservedIoc(['type' => 'url', 'value' => 'https://evil.example.com/pay']),
        ]);

        $service = $this->buildService($iocHandler);
        $result = $this->invokeFetchExtractedIocs($service, 'c0000000-0000-0000-0000-00000000000c');

        $this->assertCount(2, $result);
        $this->assertSame('iban', $result[0]['type']);
        $this->assertSame('FR7630006000011234567890189', $result[0]['value']);
        $this->assertSame('financial', $result[0]['category']);
        $this->assertSame('url', $result[1]['type']);
        $this->assertNotSame('', $result[1]['category']);
    }

    public function testStoredCategoryIsPreferredOverDerivedOne(): void
    {
        $iocHandler = $this->createMock(IocHandler::class);
        $iocHandler->method('getConversationIocs')->willReturn([
            // Stored category deliberately differs from classify('iban')
            // ('financial') to prove the stored value wins.
            $this->makeObservedIoc(['type' => 'iban', 'value' => 'X', 'category' => 'custom_bucket']),
        ]);

        $service = $this->buildService($iocHandler);
        $result = $this->invokeFetchExtractedIocs($service, 'c0000000-0000-0000-0000-00000000000c');

        $this->assertSame('custom_bucket', $result[0]['category']);
    }

    public function testMissingContextFieldsFallBackToDefaults(): void
    {
        $iocHandler = $this->createMock(IocHandler::class);
        $iocHandler->method('getConversationIocs')->willReturn([
            $this->makeObservedIoc([]),
        ]);

        $service = $this->buildService($iocHandler);
        $result = $this->invokeFetchExtractedIocs($service, 'c0000000-0000-0000-0000-00000000000c');

        $this->assertCount(1, $result);
        $this->assertSame('unknown', $result[0]['type']);
        $this->assertSame('', $result[0]['value']);
    }

    public function testLoadFailureLogsAtWarningAndDegradesGracefully(): void
    {
        $iocHandler = $this->createMock(IocHandler::class);
        $iocHandler->method('getConversationIocs')->willThrowException(new \RuntimeException('boom'));

        $service = $this->buildService($iocHandler);
        $result = $this->invokeFetchExtractedIocs($service, 'c0000000-0000-0000-0000-00000000000c');

        $this->assertSame([], $result);

        $warnings = array_filter(
            $this->logRecords,
            static fn (array $r): bool => \in_array($r['level'], ['warning', 'error', 'critical'], true)
                && str_contains($r['message'], 'Failed to fetch IOCs'),
        );
        $this->assertNotEmpty($warnings, 'IOC load failure must be logged at warning level or above');
    }

    public function testNoIocHandlerYieldsEmptyList(): void
    {
        $ref = new \ReflectionClass(ReplyContextService::class);
        /** @var ReplyContextService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('logger')->setValue($service, new \Psr\Log\NullLogger());
        $ref->getProperty('iocHandler')->setValue($service, null);

        $this->assertSame([], $this->invokeFetchExtractedIocs($service, 'c0000000-0000-0000-0000-00000000000c'));
    }
}
