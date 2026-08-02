<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\ThreatActor;

use App\Application\LLM\Port\LLMClientInterface;
use App\Application\ThreatActor\ClusterBehaviourReaderInterface;
use App\Application\ThreatActor\ThreatActorPsychProfileGenerator;
use App\Domain\ThreatActor\CialdiniLever;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ThreatActorPsychProfileGeneratorTest extends TestCase
{
    private const CLUSTER = '11111111-1111-1111-1111-111111111111';

    /**
     * @param array<string, mixed>|false $clusterRow
     * @param list<array<string, mixed>> $messageRows
     */
    private function connection(array|false $clusterRow, array $messageRows, ?\PHPUnit\Framework\Constraint\Constraint $persistParams = null): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($clusterRow);
        $conn->method('fetchAllAssociative')->willReturn($messageRows);

        if ($persistParams !== null) {
            $conn->expects(self::once())->method('executeStatement')->with(self::anything(), $persistParams)->willReturn(1);
        }

        return $conn;
    }

    private function behaviour(): ClusterBehaviourReaderInterface
    {
        $reader = $this->createMock(ClusterBehaviourReaderInterface::class);
        $reader->method('getBehavioralProfile')->willReturn([
            'dominant_stimulus'           => 'fear',
            'dominant_stimulus_count'     => 3,
            'avg_urgency_score'           => 0.7,
            'dominant_revelation_turn'    => 2,
            'hesitation_count'            => 1,
            'language_switch_count'       => 0,
            'templated_excerpt_count'     => 0,
            'total_excerpt_variant_count' => 5,
            'total_enriched_iocs'         => 8,
        ]);

        return $reader;
    }

    private function llm(string $response): LLMClientInterface
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn($response);

        return $llm;
    }

    private function generator(Connection $conn, LLMClientInterface $llm): ThreatActorPsychProfileGenerator
    {
        return new ThreatActorPsychProfileGenerator(
            $llm,
            $conn,
            $this->behaviour(),
            $this->createMock(EventDispatcherInterface::class),
            new NullLogger(),
        );
    }

    private function validClusterRow(): array
    {
        return ['name' => 'Cluster A', 'conversation_count' => 4, 'scam_types' => 'banking, invoice'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(): array
    {
        return [['body_text' => 'Pay now or your account closes today.'], ['body_text' => 'I am the bank director, trust me.']];
    }

    public function testHappyPathReturnsProfileAndPersistsIt(): void
    {
        $json = json_encode([
            'dominant_lever'      => 'Urgency',
            'secondary_levers'    => ['Authority', 'Urgency', 'None', 'bogus'], // dominant/None/unknown must be filtered
            'behavioural_summary' => 'Pushes hard deadlines and leans on a fake bank title.',
            'escalation_pattern'  => 'rapid',
            'victim_targeting'    => 'Time-poor account holders.',
        ]);

        $params = self::callback(static fn (array $p): bool => $p['lever'] === 'Urgency'
            && $p['secondary'] === '{Authority}'
            && $p['escalation'] === 'rapid'
            && $p['stimulus'] === 'fear'
            && $p['cid'] === self::CLUSTER);

        $generator = $this->generator($this->connection($this->validClusterRow(), $this->messages(), $params), $this->llm((string) $json));
        $profile = $generator->generateForCluster(self::CLUSTER);

        self::assertNotNull($profile);
        self::assertSame(CialdiniLever::Urgency, $profile->dominantLever);
        self::assertSame([CialdiniLever::Authority], $profile->secondaryLevers);
        self::assertSame('rapid', $profile->escalationPattern);
        self::assertSame('fear', $profile->dominantStimulus);
        self::assertSame(0.7, $profile->avgUrgency);
        self::assertSame(4, $profile->conversationCount);
    }

    public function testUnknownClusterReturnsNullAndDoesNotCallLlm(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->expects(self::never())->method('chat');

        $generator = $this->generator($this->connection(false, []), $llm);

        self::assertNull($generator->generateForCluster(self::CLUSTER));
    }

    public function testNoInboundCorpusReturnsNullAndDoesNotCallLlm(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->expects(self::never())->method('chat');

        $generator = $this->generator($this->connection($this->validClusterRow(), []), $llm);

        self::assertNull($generator->generateForCluster(self::CLUSTER));
    }

    public function testMalformedJsonReturnsNull(): void
    {
        $generator = $this->generator($this->connection($this->validClusterRow(), $this->messages()), $this->llm('not json at all'));

        self::assertNull($generator->generateForCluster(self::CLUSTER));
    }

    public function testUnknownDominantLeverReturnsNull(): void
    {
        $json = (string) json_encode([
            'dominant_lever'      => 'Gaslighting',
            'secondary_levers'    => [],
            'behavioural_summary' => 'x',
            'escalation_pattern'  => 'rapid',
            'victim_targeting'    => 'y',
        ]);

        $generator = $this->generator($this->connection($this->validClusterRow(), $this->messages()), $this->llm($json));

        self::assertNull($generator->generateForCluster(self::CLUSTER));
    }

    public function testInvalidEscalationPatternIsCoercedToUnknown(): void
    {
        $json = (string) json_encode([
            'dominant_lever'      => 'Authority',
            'secondary_levers'    => [],
            'behavioural_summary' => 'Leans on authority.',
            'escalation_pattern'  => 'nuclear',
            'victim_targeting'    => 'anyone',
        ]);

        $generator = $this->generator($this->connection($this->validClusterRow(), $this->messages()), $this->llm($json));
        $profile = $generator->generateForCluster(self::CLUSTER);

        self::assertNotNull($profile);
        self::assertSame('unknown', $profile->escalationPattern);
    }

    public function testFencedJsonResponseIsParsed(): void
    {
        $json = "```json\n" . (string) json_encode([
            'dominant_lever'      => 'Scarcity',
            'secondary_levers'    => ['Liking'],
            'behavioural_summary' => 'Only-a-few-left framing.',
            'escalation_pattern'  => 'gradual',
            'victim_targeting'    => 'bargain hunters',
        ]) . "\n```";

        $generator = $this->generator($this->connection($this->validClusterRow(), $this->messages()), $this->llm($json));
        $profile = $generator->generateForCluster(self::CLUSTER);

        self::assertNotNull($profile);
        self::assertSame(CialdiniLever::Scarcity, $profile->dominantLever);
        self::assertSame([CialdiniLever::Liking], $profile->secondaryLevers);
    }
}
