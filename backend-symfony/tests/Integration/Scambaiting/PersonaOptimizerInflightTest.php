<?php

declare(strict_types=1);

namespace Tests\Integration\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationRepositoryInterface;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 092 §US1 — integration coverage on the in-flight pull tracking.
 *
 * Validates that PersonaOptimizer, when fed real OPEN conversations on
 * a (persona, scam_type) pair, deflates the UCB1 exploration bonus so
 * the bandit picks the persona with the higher reward_avg instead of
 * the stuck-with-bonus one.
 *
 * Kept separate from the unit-mocked PersonaOptimizerTest because the
 * in-flight count comes from a Doctrine query — we need real Conversation
 * rows in the test DB, not mocks.
 */
final class PersonaOptimizerInflightTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PersonaOptimizer $optimizer;
    private ConversationRepositoryInterface $convRepo;

    /** @var list<string> conv ids inserted by this test */
    private array $insertedConvs = [];

    /**
     * Snapshot of PHISHING + UNKNOWN persona_performance_stats rows at setUp time,
     * indexed by "persona_code|scam_type_code". Used to wipe-then-restore the
     * pre-existing fixtures so the bandit selection is deterministic on the
     * personas we seed.
     *
     * @var array<string, array{persona_id: int, scam_type_id: int, sessions_count: int, total_reward: float, reward_avg: float}>
     */
    private array $statsSnapshot = [];

    private Channel $channel;
    private MailAccount $account;
    private ScamType $scamTypePhishing;
    private ScamType $scamTypeUnknown;
    private Persona $personaA;
    private Persona $personaB;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->optimizer = self::getContainer()->get(PersonaOptimizer::class);
        $this->convRepo = self::getContainer()->get(ConversationRepositoryInterface::class);

        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $phishing = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);
        $unknown = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'UNKNOWN']);
        $a = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'hopeless_romantic']);
        $b = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => 'small_business_owner']);

        $this->assertNotNull($channel);
        $this->assertNotNull($account);
        $this->assertNotNull($phishing);
        $this->assertNotNull($unknown);
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $this->channel = $channel;
        $this->account = $account;
        $this->scamTypePhishing = $phishing;
        $this->scamTypeUnknown = $unknown;
        $this->personaA = $a;
        $this->personaB = $b;

        // Snapshot then wipe PHISHING + UNKNOWN stats so the bandit only sees
        // the personas we seed in the test. tearDown restores the snapshot.
        $this->snapshotAndWipeStatsForScamTypes([$this->scamTypePhishing, $this->scamTypeUnknown]);
    }

    protected function tearDown(): void
    {
        foreach ($this->insertedConvs as $convId) {
            $conv = $this->em->find(Conversation::class, $convId);

            if ($conv !== null) {
                $this->em->remove($conv);
            }
        }

        if ($this->insertedConvs !== []) {
            $this->em->flush();
        }

        $this->restoreStatsSnapshot();

        parent::tearDown();
    }

    /**
     * @param list<ScamType> $scamTypes
     */
    private function snapshotAndWipeStatsForScamTypes(array $scamTypes): void
    {
        foreach ($scamTypes as $scamType) {
            $rows = $this->em->getRepository(PersonaPerformanceStatsEntity::class)
                ->findBy(['scamType' => $scamType]);

            foreach ($rows as $row) {
                $key = $row->getPersona()->getPersonaCode() . '|' . $scamType->getCode();
                $this->statsSnapshot[$key] = [
                    'persona_id' => $row->getPersona()->getPersonaId(),
                    'scam_type_id' => $scamType->getScamTypeId(),
                    'sessions_count' => $row->getSessionsCount(),
                    'total_reward' => $row->getRewardSum(),
                    'reward_avg' => $row->getRewardAvg(),
                ];
                $this->em->remove($row);
            }
        }

        if ($this->statsSnapshot !== []) {
            $this->em->flush();
        }
    }

    private function restoreStatsSnapshot(): void
    {
        // Also remove any seeded rows we created during the test.
        foreach (array_keys($this->statsSnapshot) as $key) {
            [$personaCode, $scamTypeCode] = explode('|', $key);
            $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => $personaCode]);
            $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

            if ($persona !== null && $scamType !== null) {
                $row = $this->em->getRepository(PersonaPerformanceStatsEntity::class)
                    ->findOneBy(['persona' => $persona, 'scamType' => $scamType]);

                if ($row !== null) {
                    $this->em->remove($row);
                }
            }
        }

        // Also clean up any seeded rows for personas NOT in the snapshot (we
        // may have seeded A or B fresh; the snapshot loop above already covered
        // them if they had pre-existing rows). Iterate over both seed personas.
        foreach ([$this->personaA, $this->personaB] as $persona) {
            foreach ([$this->scamTypePhishing, $this->scamTypeUnknown] as $scamType) {
                $key = $persona->getPersonaCode() . '|' . $scamType->getCode();

                if (isset($this->statsSnapshot[$key])) {
                    continue;
                }

                $row = $this->em->getRepository(PersonaPerformanceStatsEntity::class)
                    ->findOneBy(['persona' => $persona, 'scamType' => $scamType]);

                if ($row !== null) {
                    $this->em->remove($row);
                }
            }
        }
        $this->em->flush();

        // Restore snapshot rows.
        foreach ($this->statsSnapshot as $snap) {
            $persona = $this->em->getReference(Persona::class, $snap['persona_id']);
            $scamType = $this->em->getReference(ScamType::class, $snap['scam_type_id']);
            $row = new PersonaPerformanceStatsEntity(
                $persona,
                $scamType,
                $snap['sessions_count'],
                $snap['total_reward'],
                $snap['reward_avg'],
            );
            $this->em->persist($row);
        }

        if ($this->statsSnapshot !== []) {
            $this->em->flush();
        }
    }

    public function test_in_flight_isolation_per_scam_type(): void
    {
        // Spec 092 §US1 acceptance scenario 3 — in-flight on PHISHING must
        // NOT inflate persona N for UNKNOWN bandit decisions.
        // Direct repo assertion: ground truth of the per-scam-type query.
        $beforeUnknown = $this->convRepo->countOpenByPersonaForScamType($this->scamTypeUnknown);
        $beforePhishing = $this->convRepo->countOpenByPersonaForScamType($this->scamTypePhishing);
        $beforeAUnknown = $beforeUnknown[$this->personaA->getPersonaCode()] ?? 0;
        $beforeAPhishing = $beforePhishing[$this->personaA->getPersonaCode()] ?? 0;

        // Seed 7 open A on PHISHING, 0 on UNKNOWN
        for ($i = 0; $i < 7; $i++) {
            $this->seedConv(ConversationStatus::OPEN, $this->personaA, $this->scamTypePhishing);
        }

        $afterUnknown = $this->convRepo->countOpenByPersonaForScamType($this->scamTypeUnknown);
        $afterPhishing = $this->convRepo->countOpenByPersonaForScamType($this->scamTypePhishing);

        $this->assertSame(
            $beforeAUnknown,
            $afterUnknown[$this->personaA->getPersonaCode()] ?? 0,
            'UNKNOWN count must NOT be affected by PHISHING in-flight convs',
        );
        $this->assertSame(
            $beforeAPhishing + 7,
            $afterPhishing[$this->personaA->getPersonaCode()] ?? 0,
            'PHISHING count must include the 7 newly seeded open convs',
        );
    }

    public function test_in_flight_count_reaches_optimizer(): void
    {
        // Spec 092 §US1 — surface check that the optimizer's audit log
        // payload carries the elevated effective_total_sessions when
        // in-flight convs exist. We can't easily intercept the audit log
        // in this integration test, so we verify via the simpler check:
        // forcing the bandit decision and confirming the selected persona
        // diverges from the no-in-flight case.
        //
        // Setup: persona A with reward 0.336 (closed 5 sessions via stats
        // seed) + 17 in-flight on PHISHING; persona B with reward 0.476
        // and 19 closed sessions, 0 in-flight.
        //
        // Without spec 092 fix: A wins (UCB1 bonus inflates A's 5-session
        // score above B's 19-session score).
        // With spec 092 fix: B wins (A's effective N = 22 ≈ B's 19, and
        // B's higher reward dominates).
        $this->seedStats($this->personaA, $this->scamTypePhishing, sessions: 5, rewardAvg: 0.336);
        $this->seedStats($this->personaB, $this->scamTypePhishing, sessions: 19, rewardAvg: 0.476);

        for ($i = 0; $i < 17; $i++) {
            $this->seedConv(ConversationStatus::OPEN, $this->personaA, $this->scamTypePhishing);
        }

        // Force exploit branch by running many selections and counting.
        // Epsilon = 20% exploration, so over 200 runs we expect ~160 exploit.
        // After spec 092 fix, exploit branch must pick personaB; we assert
        // personaB share dominates personaA (deterministic flip vs current
        // buggy behaviour where personaA would dominate).
        $selections = ['A' => 0, 'B' => 0, 'other' => 0];

        for ($i = 0; $i < 200; $i++) {
            $code = $this->optimizer->selectPersona('PHISHING');

            if ($code === $this->personaA->getPersonaCode()) {
                $selections['A']++;
            } elseif ($code === $this->personaB->getPersonaCode()) {
                $selections['B']++;
            } else {
                $selections['other']++;
            }
        }

        // With spec 092 fix, persona B should be picked significantly
        // more often than persona A. We allow some randomness from the
        // 20% exploration branch + cold-start exploration on the many
        // unseeded personas. Threshold: B picks > A picks (the bandit
        // must at least flip the dominance).
        $this->assertGreaterThan(
            $selections['A'],
            $selections['B'],
            sprintf(
                'Spec 092 — persona B (higher reward) must beat persona A (stuck with inflated bonus): got A=%d, B=%d',
                $selections['A'],
                $selections['B'],
            ),
        );
    }

    public function test_no_in_flight_preserves_legacy_behaviour(): void
    {
        // Spec 092 §US1 acceptance scenario 2 — without any in-flight
        // convs, the bandit's behaviour is identical to today. With the
        // PHISHING reproduction stats (A: 5 closed, reward 0.336;
        // B: 19 closed, reward 0.476) and NO open convs, the UCB1 math
        // before the fix favours A (low N, big bonus). After the fix
        // with in_flight = 0, the math is unchanged → A should still win.
        $this->seedStats($this->personaA, $this->scamTypeUnknown, sessions: 5, rewardAvg: 0.336);
        $this->seedStats($this->personaB, $this->scamTypeUnknown, sessions: 19, rewardAvg: 0.476);

        // No open convs seeded — pure stats-only scenario.
        $selections = ['A' => 0, 'B' => 0];

        for ($i = 0; $i < 200; $i++) {
            $code = $this->optimizer->selectPersona('UNKNOWN');

            if ($code === $this->personaA->getPersonaCode()) {
                $selections['A']++;
            } elseif ($code === $this->personaB->getPersonaCode()) {
                $selections['B']++;
            }
        }

        // With no in-flight, A's UCB1 bonus is larger (5 sessions vs 19),
        // so A should be picked more often. Regression guard for backward
        // compatibility on the steady-state case.
        $this->assertGreaterThan(
            $selections['B'],
            $selections['A'],
            sprintf(
                'No-in-flight regression: persona A (lower N) must still beat B per pure UCB1: got A=%d, B=%d',
                $selections['A'],
                $selections['B'],
            ),
        );
    }

    public function test_cold_start_branch_ignores_in_flight(): void
    {
        // Spec 092 §US3 — when ALL personas are in cold start (no closed
        // sessions for any), the bandit fires the cold-start branch
        // regardless of in-flight count. Seed 50 open convs of persona A
        // on UNKNOWN, NO stats anywhere → cold start should still fire
        // and the selection should be uniformly random (not stuck on A).
        for ($i = 0; $i < 50; $i++) {
            $this->seedConv(ConversationStatus::OPEN, $this->personaA, $this->scamTypeUnknown);
        }

        // No stats seeded → all personas are in cold start.
        // Run many selections; with cold-start uniform random over 27
        // personas, A should NOT exceed ~25-30% (would be ~3.7% in pure
        // uniform). We assert A's share is well below 100%, confirming
        // the cold-start branch is not biased by the 50 in-flight.
        $selections = ['A' => 0, 'other' => 0];

        for ($i = 0; $i < 200; $i++) {
            $code = $this->optimizer->selectPersona('UNKNOWN');

            if ($code === $this->personaA->getPersonaCode()) {
                $selections['A']++;
            } else {
                $selections['other']++;
            }
        }

        $this->assertLessThan(
            150,
            $selections['A'],
            sprintf(
                'Cold-start branch must not be biased by in-flight count: persona A picked %d/200 times (expected uniform random across all personas)',
                $selections['A'],
            ),
        );
    }

    private function seedConv(ConversationStatus $status, Persona $persona, ScamType $scamType): string
    {
        $convId = uuid_create(UUID_TYPE_RANDOM);
        $now = new \DateTimeImmutable();
        $conv = new Conversation(
            convId: $convId,
            primaryChannel: $this->channel,
            scamType: $scamType,
            account: $this->account,
            status: $status,
            scoreRisk: 0,
            tsFirst: $now,
            tsLast: $now,
            stixId: 'stix-test-092-opt-' . substr($convId, 0, 12),
        );
        $conv->setPersona($persona);
        $this->em->persist($conv);
        $this->em->flush();
        $this->insertedConvs[] = $convId;

        return $convId;
    }

    private function seedStats(Persona $persona, ScamType $scamType, int $sessions, float $rewardAvg): void
    {
        // setUp already wiped any pre-existing PHISHING/UNKNOWN stats via the
        // snapshot mechanism, so we always insert fresh. tearDown will remove
        // the freshly inserted row before restoring the snapshot.
        $stats = new PersonaPerformanceStatsEntity($persona, $scamType, $sessions, $rewardAvg * $sessions, $rewardAvg);
        $this->em->persist($stats);
        $this->em->flush();
    }
}
