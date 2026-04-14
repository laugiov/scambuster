<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\CampaignProfiler;
use App\Application\Campaign\PromptBuilder;
use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Communication\Message;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Mutation-killing tests for CampaignProfiler.
 *
 * Targets:
 * - Minimum 3 messages boundary (2 fails, 3 succeeds)
 * - MAX_RETRIES=3 boundary
 * - Backoff delay values [1, 2, 4]
 * - PII detection patterns (email, phone, IBAN)
 * - YAML validation required keys (campaign, variants, infra)
 * - Campaign sub-keys (summary, tactics, target_audience, cta, risk)
 * - Variants sub-keys (subjects, display_names, url_shapes)
 * - Risk range validation (1-5)
 * - Tactics must be array
 * - Cache key determinism (sorted IDs + md5)
 * - CACHE_TTL = 7200
 * - Extraction from markdown vs raw
 */
final class CampaignProfilerMutationTest extends TestCase
{
    private CampaignProfiler $profiler;
    private LLMClientInterface&MockObject $llmClient;
    private CacheInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->profiler = new CampaignProfiler(
            $this->llmClient,
            new PromptBuilder(),
            $this->cache,
            $this->logger,
        );
    }

    private function mockMessage(string $id = ''): Message
    {
        $msg = $this->createMock(Message::class);
        $msg->method('getSubject')->willReturn('Test Subject');
        $msg->method('getBodyText')->willReturn('Test body');
        $msg->method('getHeaders')->willReturn(['from' => 'test@test.com']);
        $msg->method('getMsgId')->willReturn($id ?: \Symfony\Component\Uid\Uuid::v7()->toRfc4122());
        return $msg;
    }

    private function setupCacheMiss(): void
    {
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });
    }

    private function validYaml(): string
    {
        return <<<'YAML'
campaign:
  summary: "Test campaign"
  tactics: ["urgency"]
  target_audience: "users"
  cta: "click"
  risk: 3
variants:
  subjects: ["Test"]
  display_names: ["Support"]
  url_shapes: ["test.com"]
infra:
  domain_age_pattern: "<7d"
YAML;
    }

    // === Minimum message count boundary ===

    public function test_0_messages_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiler->profile([]);
    }

    public function test_1_message_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->profiler->profile([$this->mockMessage()]);
    }

    public function test_2_messages_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Au moins 3 messages requis');
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage()]);
    }

    public function test_3_messages_succeeds(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->method('chat')->willReturn($this->validYaml());

        $result = $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
        $this->assertArrayHasKey('profile_yaml', $result);
    }

    // === MAX_RETRIES = 3 ===

    public function test_fails_after_exactly_3_retries(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->expects($this->exactly(3))
            ->method('chat')
            ->willThrowException(new \RuntimeException('fail'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_succeeds_on_third_attempt(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->expects($this->exactly(3))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('err1')),
                $this->throwException(new \RuntimeException('err2')),
                $this->validYaml(),
            );

        $result = $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
        $this->assertSame(3, $result['attempts']);
    }

    public function test_first_attempt_success_returns_attempts_1(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->expects($this->once())->method('chat')->willReturn($this->validYaml());

        $result = $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
        $this->assertSame(1, $result['attempts']);
    }

    // === PII detection: email ===

    public function test_pii_email_detected(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('"Test campaign"', '"Contact john@evil.com"', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === PII detection: phone ===

    public function test_pii_french_phone_detected(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('"Test campaign"', '"Call 06 12 34 56 78"', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_pii_landline_phone_detected(): void
    {
        $this->setupCacheMiss();
        // Use standard French landline format: 01 23 45 67 89
        $yaml = str_replace('"Test campaign"', '"Call 01 23 45 67 89"', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === PII detection: IBAN ===

    public function test_pii_iban_detected(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('"Test campaign"', '"Transfer to FR7612345678901234567890123"', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === YAML validation: required top-level keys ===

    public function test_missing_campaign_key_fails(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->method('chat')->willReturn("variants:\n  subjects: []\ninfra:\n  x: y");

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_variants_key_fails(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->method('chat')->willReturn("campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  cta: x\n  risk: 3\ninfra:\n  x: y");

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_infra_key_fails(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->method('chat')->willReturn("campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  cta: x\n  risk: 3\nvariants:\n  subjects: []\n  display_names: []\n  url_shapes: []");

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === YAML validation: campaign sub-keys ===

    public function test_missing_summary_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  tactics: []\n  target_audience: x\n  cta: x\n  risk: 3\nvariants:\n  subjects: []\n  display_names: []\n  url_shapes: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_tactics_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  summary: x\n  target_audience: x\n  cta: x\n  risk: 3\nvariants:\n  subjects: []\n  display_names: []\n  url_shapes: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_cta_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  risk: 3\nvariants:\n  subjects: []\n  display_names: []\n  url_shapes: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_risk_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  cta: x\nvariants:\n  subjects: []\n  display_names: []\n  url_shapes: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === Variants sub-keys ===

    public function test_missing_subjects_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  cta: x\n  risk: 3\nvariants:\n  display_names: []\n  url_shapes: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_display_names_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  cta: x\n  risk: 3\nvariants:\n  subjects: []\n  url_shapes: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_missing_url_shapes_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = "campaign:\n  summary: x\n  tactics: []\n  target_audience: x\n  cta: x\n  risk: 3\nvariants:\n  subjects: []\n  display_names: []\ninfra:\n  x: y";
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === Risk value validation ===

    public function test_risk_0_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('risk: 3', 'risk: 0', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_risk_6_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('risk: 3', 'risk: 6', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    public function test_risk_1_succeeds(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('risk: 3', 'risk: 1', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $result = $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
        $this->assertArrayHasKey('profile_yaml', $result);
    }

    public function test_risk_5_succeeds(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('risk: 3', 'risk: 5', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $result = $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
        $this->assertArrayHasKey('profile_yaml', $result);
    }

    // === Tactics must be array ===

    public function test_tactics_string_fails(): void
    {
        $this->setupCacheMiss();
        $yaml = str_replace('tactics: ["urgency"]', 'tactics: "not array"', $this->validYaml());
        $this->llmClient->method('chat')->willReturn($yaml);

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }

    // === Cache key is deterministic with sorted IDs ===

    public function test_same_messages_same_order_same_cache_key(): void
    {
        $msg1 = $this->mockMessage('aaa');
        $msg2 = $this->mockMessage('bbb');
        $msg3 = $this->mockMessage('ccc');

        $capturedKeys = [];
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) use (&$capturedKeys) {
            $capturedKeys[] = $key;
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });
        $this->llmClient->method('chat')->willReturn($this->validYaml());

        $this->profiler->profile([$msg1, $msg2, $msg3]);
        $this->profiler->profile([$msg1, $msg2, $msg3]);

        $this->assertSame($capturedKeys[0], $capturedKeys[1], 'Same messages must produce same cache key');
    }

    public function test_different_order_same_cache_key(): void
    {
        // IDs are sorted before hashing, so order shouldn't matter
        $msg1 = $this->mockMessage('aaa');
        $msg2 = $this->mockMessage('bbb');
        $msg3 = $this->mockMessage('ccc');

        $capturedKeys = [];
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) use (&$capturedKeys) {
            $capturedKeys[] = $key;
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });
        $this->llmClient->method('chat')->willReturn($this->validYaml());

        $this->profiler->profile([$msg1, $msg2, $msg3]);
        $this->profiler->profile([$msg3, $msg1, $msg2]);

        $this->assertSame($capturedKeys[0], $capturedKeys[1], 'Different order must produce same cache key (sorted)');
    }

    public function test_cache_key_starts_with_campaign_profile(): void
    {
        $capturedKey = '';
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) use (&$capturedKey) {
            $capturedKey = $key;
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });
        $this->llmClient->method('chat')->willReturn($this->validYaml());

        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);

        $this->assertStringStartsWith('campaign_profile_', $capturedKey);
    }

    // === Messages truncated to 10 ===

    public function test_11_messages_logs_warning(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->method('chat')->willReturn($this->validYaml());

        $messages = [];
        for ($i = 0; $i < 11; $i++) {
            $messages[] = $this->mockMessage();
        }

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->profiler->profile($messages);
    }

    // === YAML extraction from markdown ===

    public function test_yaml_extracted_from_markdown_code_block(): void
    {
        $this->setupCacheMiss();
        $yaml = $this->validYaml();
        $this->llmClient->method('chat')->willReturn("```yaml\n{$yaml}\n```");

        $result = $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
        $this->assertStringNotContainsString('```', $result['profile_yaml']);
        $this->assertStringContainsString('campaign:', $result['profile_yaml']);
    }

    // === No valid YAML throws ===

    public function test_no_campaign_keyword_in_response_throws(): void
    {
        $this->setupCacheMiss();
        $this->llmClient->method('chat')->willReturn('No valid YAML here at all just text');

        $this->expectException(\RuntimeException::class);
        $this->profiler->profile([$this->mockMessage(), $this->mockMessage(), $this->mockMessage()]);
    }
}
