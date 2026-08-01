<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\ConversationQualityAuditor;
use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \App\Application\Audit\ConversationQualityAuditor
 */
class ConversationQualityAuditorTest extends TestCase
{
    private LLMClientInterface $llmClient;
    private Connection $connection;
    private ConversationQualityAuditor $auditor;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->auditor = new ConversationQualityAuditor(
            $this->llmClient,
            $this->connection,
            new NullLogger(),
        );
    }

    private function validAuditResponse(): string
    {
        return json_encode([
            'classification' => [
                'verdict' => 'AGREE',
                'assigned' => 'PHISHING',
                'suggested' => 'PHISHING',
                'reasoning' => 'Message clearly impersonates a bank',
            ],
            'ioc_completeness' => [
                'verdict' => 'COMPLETE',
                'missed_iocs' => [],
                'reasoning' => 'All visible IOCs were extracted',
            ],
            'urgency' => [
                'verdict' => 'AGREE',
                'assigned_score' => 0.75,
                'suggested_score' => 0.80,
                'reasoning' => 'Urgency assessment is reasonable',
            ],
            'semantic_roles' => [
                'verdict' => 'AGREE',
                'issues' => [],
                'reasoning' => 'Roles correctly assigned',
            ],
            'risk_score' => [
                'verdict' => 'DISAGREE',
                'assigned' => 30,
                'suggested' => 60,
                'reasoning' => 'Risk underestimated given phishing URL presence',
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null              $conversationRow
     * @param array<string, mixed>|null              $messageRow
     * @param array<int, array<string, mixed>>       $iocRows
     */
    private function setupConnectionMocks(
        ?array $conversationRow = null,
        ?array $messageRow = null,
        array $iocRows = [],
    ): void {
        $conversationRow ??= [
            'conv_id' => 'conv-001',
            'scam_type' => 'PHISHING',
            'score_risk' => 30,
            'status' => 'open',
        ];

        $messageRow ??= [
            'msg_id' => 'msg-001',
            'body_text' => 'Dear customer, your account has been suspended. Click https://evil-bank.com/verify to restore access. Call +1234567890 for help.',
        ];

        $callIndex = 0;
        $this->connection
            ->method('fetchAssociative')
            ->willReturnCallback(function () use (&$callIndex, $conversationRow, $messageRow): ?array {
                /** @var int $callIndex */
                return match ($callIndex++) {
                    0 => $conversationRow,
                    1 => $messageRow,
                    default => null,
                };
            });

        $this->connection
            ->method('fetchAllAssociative')
            ->willReturn($iocRows);
    }

    public function testPromptContainsIndependentSecurityAnalystRole(): void
    {
        $this->setupConnectionMocks();

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) {
                    $systemContent = $messages[0]['content'] ?? '';

                    return str_contains($systemContent, 'independent security intelligence analyst');
                }),
                $this->anything(),
            )
            ->willReturn($this->validAuditResponse());

        $this->auditor->audit('conv-001');
    }

    public function testPromptContainsConversationBodyText(): void
    {
        $this->setupConnectionMocks();

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) {
                    $userContent = $messages[1]['content'] ?? '';

                    return str_contains($userContent, 'your account has been suspended');
                }),
                $this->anything(),
            )
            ->willReturn($this->validAuditResponse());

        $this->auditor->audit('conv-001');
    }

    public function testPromptContainsIocList(): void
    {
        $iocRows = [
            [
                'type' => 'url',
                'value' => 'https://evil-bank.com/verify',
                'semantic_role' => 'PHISHING_CREDENTIAL_URL',
                'urgency_score' => '0.75',
                'stimulus_type' => 'PASSIVE',
            ],
            [
                'type' => 'phone',
                'value' => '+1234567890',
                'semantic_role' => 'CONTACT_CHANNEL',
                'urgency_score' => '0.75',
                'stimulus_type' => 'PASSIVE',
            ],
        ];

        $this->setupConnectionMocks(iocRows: $iocRows);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) {
                    $userContent = $messages[1]['content'] ?? '';

                    return str_contains($userContent, 'type: url')
                        && str_contains($userContent, 'https://evil-bank.com/verify')
                        && str_contains($userContent, 'type: phone')
                        && str_contains($userContent, 'PHISHING_CREDENTIAL_URL');
                }),
                $this->anything(),
            )
            ->willReturn($this->validAuditResponse());

        $this->auditor->audit('conv-001');
    }

    public function testValidJsonResponseIsParsedIntoStructuredArray(): void
    {
        $this->setupConnectionMocks();

        $this->llmClient
            ->method('chat')
            ->willReturn($this->validAuditResponse());

        $result = $this->auditor->audit('conv-001');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('ioc_completeness', $result);
        $this->assertArrayHasKey('urgency', $result);
        $this->assertArrayHasKey('semantic_roles', $result);
        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('overall_agreement', $result);

        // 4 AGREE/COMPLETE + 1 DISAGREE = 0.80
        $this->assertSame(0.8, $result['overall_agreement']);
        $this->assertSame('AGREE', $result['classification']['verdict']);
        $this->assertSame('DISAGREE', $result['risk_score']['verdict']);
    }

    public function testMalformedJsonReturnsNull(): void
    {
        $this->setupConnectionMocks();

        $this->llmClient
            ->method('chat')
            ->willReturn('This is not valid JSON at all, sorry I cannot help.');

        $result = $this->auditor->audit('conv-001');

        $this->assertNull($result);
    }

    public function testLlmExceptionReturnsNull(): void
    {
        $this->setupConnectionMocks();

        $this->llmClient
            ->method('chat')
            ->willThrowException(new \RuntimeException('API rate limit exceeded'));

        $result = $this->auditor->audit('conv-001');

        $this->assertNull($result);
    }

    public function testEmptyConversationNoMessagesReturnsNull(): void
    {
        // Conversation exists but no inbound messages
        // We need a fresh mock since setupConnectionMocks already configures fetchAssociative
        $connection = $this->createMock(Connection::class);
        $llmClient = $this->createMock(LLMClientInterface::class);

        $auditor = new ConversationQualityAuditor(
            $llmClient,
            $connection,
            new NullLogger(),
        );

        $callIndex = 0;
        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(function () use (&$callIndex): ?array {
                /** @var int $callIndex */
                return match ($callIndex++) {
                    0 => [
                        'conv_id' => 'conv-empty',
                        'scam_type' => 'UNKNOWN',
                        'score_risk' => 0,
                        'status' => 'open',
                    ],
                    default => null, // no messages
                };
            });

        $llmClient
            ->expects($this->never())
            ->method('chat');

        $result = $auditor->audit('conv-empty');

        $this->assertNull($result);
    }
}
