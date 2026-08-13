<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ConversationFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Order explicitly: findOneBy([]) emits no ORDER BY, so the row returned is
        // whichever the storage engine yields first. That is physical, not logical —
        // any UPDATE rewrites a row and moves it, silently rebinding these fixtures
        // and flipping tests that assert the resulting codes. Ordering by identifier
        // pins the historical binding (lowest id first) and keeps it reproducible.
        $channel = $manager->getRepository(Channel::class)->findBy([], ['channelId' => 'ASC'], 1)[0] ?? null;
        $scamType = $manager->getRepository(ScamType::class)->findBy([], ['scamTypeId' => 'ASC'], 1)[0] ?? null;
        $account = $manager->getRepository(MailAccount::class)->findBy([], ['accountId' => 'ASC'], 1)[0] ?? null;

        if (!$channel || !$scamType || !$account) {
            return;
        }
        $now = new \DateTimeImmutable();
        $conversations = [
            [
                'conv_id' => '00000000-0000-0000-0000-000000000001',
                'primary_channel' => $channel,
                'scam_type' => $scamType,
                'account' => $account,
                'status' => ConversationStatus::OPEN,
                'score_risk' => 10,
                'ts_first' => new \DateTimeImmutable('-2 days'),
                'ts_last' => new \DateTimeImmutable('-1 day'),
                'stix_id' => 'stix-fixture-1',
                'deleted_at' => null,
            ],
            [
                'conv_id' => '00000000-0000-0000-0000-000000000002',
                'primary_channel' => $channel,
                'scam_type' => $scamType,
                'account' => $account,
                'status' => ConversationStatus::CLOSED,
                'score_risk' => 80,
                'ts_first' => new \DateTimeImmutable('-10 days'),
                'ts_last' => new \DateTimeImmutable('-5 days'),
                'stix_id' => 'stix-fixture-2',
                'deleted_at' => null,
            ],
            [
                'conv_id' => '00000000-0000-0000-0000-000000000003',
                'primary_channel' => $channel,
                'scam_type' => $scamType,
                'account' => $account,
                'status' => ConversationStatus::ABANDONED,
                'score_risk' => 50,
                'ts_first' => new \DateTimeImmutable('-7 days'),
                'ts_last' => new \DateTimeImmutable('-6 days'),
                'stix_id' => 'stix-fixture-3',
                'deleted_at' => null,
            ],
            [
                'conv_id' => '00000000-0000-0000-0000-000000000004',
                'primary_channel' => $channel,
                'scam_type' => $scamType,
                'account' => $account,
                'status' => ConversationStatus::MISTAKE,
                'score_risk' => 5,
                'ts_first' => new \DateTimeImmutable('-3 days'),
                'ts_last' => new \DateTimeImmutable('-2 days'),
                'stix_id' => 'stix-fixture-4',
                'deleted_at' => new \DateTimeImmutable('-1 day'),
            ],
            [
                'conv_id' => '00000000-0000-0000-0000-000000000005',
                'primary_channel' => $channel,
                'scam_type' => $scamType,
                'account' => $account,
                'status' => ConversationStatus::CLOSED,
                'score_risk' => 60,
                'ts_first' => $now->modify('-3 years')->setTime(0, 0),
                'ts_last' => $now->modify('-3 years')->setTime(0, 0),
                'stix_id' => 'stix-fixture-softdelete',
                'deleted_at' => null,
            ],
            [
                'conv_id' => '00000000-0000-0000-0000-000000000006',
                'primary_channel' => $channel,
                'scam_type' => $scamType,
                'account' => $account,
                'status' => ConversationStatus::CLOSED,
                'score_risk' => 90,
                'ts_first' => $now->modify('-6 years')->setTime(0, 0),
                'ts_last' => $now->modify('-6 years')->setTime(0, 0),
                'stix_id' => 'stix-fixture-harddelete',
                'deleted_at' => $now->modify('-5 years -1 day')->setTime(0, 0),
            ],
        ];

        foreach ($conversations as $data) {
            $conv = new Conversation(
                $data['conv_id'],
                $data['primary_channel'],
                $data['scam_type'],
                $data['account'],
                $data['status'],
                $data['score_risk'],
                $data['ts_first'],
                $data['ts_last'],
                $data['stix_id']
            );

            if (isset($data['deleted_at'])) {
                $reflection = new \ReflectionObject($conv);

                if ($reflection->hasProperty('deletedAt')) {
                    $prop = $reflection->getProperty('deletedAt');
                    $prop->setAccessible(true);
                    $prop->setValue($conv, $data['deleted_at']);
                }
            }
            $manager->persist($conv);
        }
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ChannelFixtures::class,
            ScamTypeFixtures::class,
            MailAccountFixtures::class,
        ];
    }
}
