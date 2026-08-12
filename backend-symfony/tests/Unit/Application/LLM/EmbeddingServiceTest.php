<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\EmbeddingService;
use App\Application\LLM\Port\EmbeddingClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * EmbeddingService owns truncation, batching and fail-safe semantics and
 * delegates the actual embedding to the provider-agnostic client.
 */
final class EmbeddingServiceTest extends TestCase
{
    public function testGetModelDelegatesToClient(): void
    {
        $svc = new EmbeddingService(new FakeEmbeddingClient('model-under-test'), new NullLogger());
        self::assertSame('model-under-test', $svc->getModel());
    }

    public function testGetDimensionsReturnsConfiguredHint(): void
    {
        $svc = new EmbeddingService(new FakeEmbeddingClient('m'), new NullLogger(), 768);
        self::assertSame(768, $svc->getDimensions());
    }

    public function testGenerateReturnsFirstVector(): void
    {
        $client = new FakeEmbeddingClient('m');
        $svc = new EmbeddingService($client, new NullLogger());

        self::assertSame([1.0, 2.0], $svc->generate('hello'));
        self::assertSame(['hello'], $client->lastInput);
    }

    public function testGenerateBatchDelegatesInOrder(): void
    {
        $client = new FakeEmbeddingClient('m');
        $svc = new EmbeddingService($client, new NullLogger());

        $out = $svc->generateBatch(['a', 'b']);
        self::assertCount(2, $out);
        self::assertSame(['a', 'b'], $client->lastInput);
    }

    public function testLongTextIsTruncatedBeforeEmbedding(): void
    {
        $client = new FakeEmbeddingClient('m');
        $svc = new EmbeddingService($client, new NullLogger());

        $svc->generate(str_repeat('x', 50000));

        self::assertNotNull($client->lastInput);
        self::assertSame(30000, mb_strlen($client->lastInput[0]), 'text must be truncated to the char cap before embedding');
    }

    public function testFailureReturnsEmptyArray(): void
    {
        $client = new FakeEmbeddingClient('m', fail: true);
        $svc = new EmbeddingService($client, new NullLogger());

        self::assertNull($svc->generate('x'));
        self::assertSame([], $svc->generateBatch(['x']));
    }

    public function testEmptyBatchReturnsEmpty(): void
    {
        $svc = new EmbeddingService(new FakeEmbeddingClient('m'), new NullLogger());
        self::assertSame([], $svc->generateBatch([]));
    }
}

final class FakeEmbeddingClient implements EmbeddingClientInterface
{
    /** @var array<int, string>|null */
    public ?array $lastInput = null;

    public function __construct(private readonly string $model, private readonly bool $fail = false)
    {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts): array
    {
        if ($this->fail) {
            throw new \RuntimeException('provider down');
        }

        $this->lastInput = $texts;

        return array_map(static fn (): array => [1.0, 2.0], $texts);
    }
}
