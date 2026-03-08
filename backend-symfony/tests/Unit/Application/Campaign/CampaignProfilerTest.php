<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\CampaignProfiler;
use App\Application\Campaign\PromptBuilder;
use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Unit tests for CampaignProfiler with mocked LLM and Cache
 */
final class CampaignProfilerTest extends TestCase
{
    private CampaignProfiler $profiler;
    private LLMClientInterface $llmClient;
    private PromptBuilder $promptBuilder;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->promptBuilder = new PromptBuilder();
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->profiler = new CampaignProfiler(
            $this->llmClient,
            $this->promptBuilder,
            $this->cache,
            $this->logger
        );
    }

    public function testProfileThrowsExceptionWhenLessThan3Messages(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Au moins 3 messages requis');

        $this->profiler->profile($messages);
    }

    public function testProfileSuccessReturnsCacheHit(): void
    {
        $messages = [
            $this->createMockMessage('S1', 'B1'),
            $this->createMockMessage('S2', 'B2'),
            $this->createMockMessage('S3', 'B3'),
        ];

        $validYaml = $this->getValidProfileYaml();

        // Mock cache hit (bypass LLM call)
        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($validYaml) {
                // Simulate cache miss → callback executes
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($validYaml);

        $result = $this->profiler->profile($messages);

        $this->assertArrayHasKey('profile_yaml', $result);
        $this->assertArrayHasKey('cache_hit', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertStringContainsString('campaign:', $result['profile_yaml']);
    }

    public function testProfileRetryLogicSucceedsOnSecondAttempt(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $validYaml = $this->getValidProfileYaml();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        // First call fails, second succeeds
        $this->llmClient
            ->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Timeout')),
                $validYaml
            );

        $result = $this->profiler->profile($messages);

        $this->assertEquals(2, $result['attempts']);
        $this->assertStringContainsString('campaign:', $result['profile_yaml']);
    }

    public function testValidateProfileYamlAcceptsValidStructure(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $validYaml = $this->getValidProfileYaml();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($validYaml);

        $result = $this->profiler->profile($messages);

        $this->assertStringContainsString('summary', $result['profile_yaml']);
        $this->assertStringContainsString('tactics', $result['profile_yaml']);
    }

    public function testValidateProfileYamlThrowsOnMissingCampaignKey(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $invalidYaml = "variants:\n  subjects: []\ninfra:\n  domain_age_pattern: test";

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($invalidYaml);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->profiler->profile($messages);
    }

    public function testValidateProfileYamlThrowsOnMissingSubKeys(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $invalidYaml = <<<YAML
campaign:
  summary: "Test"
  # Missing: tactics, target_audience, cta, risk
variants:
  subjects: []
  display_names: []
  url_shapes: []
infra:
  domain_age_pattern: "<7d"
YAML;

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($invalidYaml);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->profiler->profile($messages);
    }

    public function testDetectPIIThrowsOnEmailDetected(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $yamlWithEmail = <<<YAML
campaign:
  summary: "Contact support@example.com for details"
  tactics: ["phishing"]
  target_audience: "general"
  cta: "click link"
  risk: 4
variants:
  subjects: []
  display_names: []
  url_shapes: []
infra:
  domain_age_pattern: "<7d"
YAML;

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($yamlWithEmail);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->profiler->profile($messages);
    }

    public function testExtractYamlFromMarkdownCodeBlock(): void
    {
        $messages = [
            $this->createMockMessage(),
            $this->createMockMessage(),
            $this->createMockMessage(),
        ];

        $validYaml = $this->getValidProfileYaml();
        $markdownResponse = "```yaml\n{$validYaml}\n```";

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            });

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($markdownResponse);

        $result = $this->profiler->profile($messages);

        $this->assertStringContainsString('campaign:', $result['profile_yaml']);
        $this->assertStringNotContainsString('```', $result['profile_yaml']);
    }

    private function createMockMessage(
        string $subject = 'Test Subject',
        string $body = 'Test body content'
    ): Message {
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn($subject);
        $message->method('getBodyText')->willReturn($body);
        $message->method('getHeaders')->willReturn(['from' => 'test@example.com']);
        $message->method('getMsgId')->willReturn(\Symfony\Component\Uid\Uuid::v7()->toRfc4122());

        return $message;
    }

    private function getValidProfileYaml(): string
    {
        return <<<YAML
campaign:
  summary: "Bank phishing campaign"
  tactics: ["urgency", "impersonation"]
  target_audience: "bank customers"
  cta: "click verification link"
  risk: 5
variants:
  subjects: ["Account suspended", "Verify your identity"]
  display_names: ["Bank Security", "Account Team"]
  url_shapes: ["bank-lookalike.com/verify"]
infra:
  domain_age_pattern: "<7d"
  dkim_spf_pattern: "fail|absent"
  mx_provider_pattern: "low-cost"
YAML;
    }
}
