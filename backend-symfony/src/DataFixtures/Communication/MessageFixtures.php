<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class MessageFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Deterministic conv_id order: message ids are derived from the loop index
        // (msg ...00N pairs with conv ...00N), so the iteration order must be stable.
        // findAll() returns Postgres heap order, which drifts across environments and
        // can attach msg ...001 to a soft-deleted conversation — invisible on a fresh
        // CI database (heap = insertion order) but breaking the TTP read tests locally.
        $conversations = $manager->getRepository(Conversation::class)->findBy([], ['convId' => 'ASC']);
        $channel = $manager->getRepository(Channel::class)->findOneBy([]);
        $directions = $manager->getRepository(Direction::class)->findAll();

        if (!$conversations || !$channel || !$directions) {
            return;
        }
        $messages = [];

        foreach ($conversations as $i => $conv) {
            $dirIn = $directions[0];
            $dirOut = $directions[1] ?? $directions[0];
            $messages[] = [
                'msg_id' => sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                'conversation' => $conv,
                'channel' => $channel,
                'direction' => $dirIn,
                'lang_detect' => 'en',
                'subject' => 'Inbound message',
                'body_text' => 'Test inbound body',
                'body_html' => null,
                'headers' => ['header' => 'value'],
                'composite_hash' => bin2hex(random_bytes(32)),
                'vector_id' => null,
                'reply_to' => null,
                'ts_msg' => new \DateTimeImmutable('-1 hour'),
                'ts_ingest' => new \DateTimeImmutable('-1 hour'),
                'deleted_at' => null,
            ];
            $messages[] = [
                'msg_id' => sprintf('00000000-0000-0000-0000-%012d', $i + 101),
                'conversation' => $conv,
                'channel' => $channel,
                'direction' => $dirOut,
                'lang_detect' => 'en',
                'subject' => 'Outbound message',
                'body_text' => 'Test outbound body',
                'body_html' => null,
                'headers' => ['header' => 'value'],
                'composite_hash' => bin2hex(random_bytes(32)),
                'vector_id' => null,
                'reply_to' => null,
                'ts_msg' => new \DateTimeImmutable('-30 minutes'),
                'ts_ingest' => new \DateTimeImmutable('-30 minutes'),
                'deleted_at' => null,
            ];
        }
        // Add a soft-deleted message
        $messages[] = [
            'msg_id' => '00000000-0000-0000-0000-999999999999',
            'conversation' => $conversations[0],
            'channel' => $channel,
            'direction' => $directions[0],
            'lang_detect' => 'en',
            'subject' => 'Soft deleted',
            'body_text' => 'This message is soft deleted',
            'body_html' => null,
            'headers' => ['header' => 'value'],
            'composite_hash' => bin2hex(random_bytes(32)),
            'vector_id' => null,
            'reply_to' => null,
            'ts_msg' => new \DateTimeImmutable('-10 minutes'),
            'ts_ingest' => new \DateTimeImmutable('-10 minutes'),
            'deleted_at' => new \DateTimeImmutable('-5 minutes'),
        ];

        foreach ($messages as $data) {
            $msg = new Message(
                $data['msg_id'],
                $data['conversation'],
                $data['channel'],
                $data['direction'],
                $data['lang_detect'],
                $data['subject'],
                $data['body_text'],
                $data['body_html'],
                $data['headers'],
                $data['composite_hash'],
                $data['vector_id'],
                $data['reply_to'],
                $data['ts_msg'],
                $data['ts_ingest'],
                $data['deleted_at']
            );

            // Add URL analysis to first message as example
            if ($data['msg_id'] === '00000000-0000-0000-0000-000000000001') {
                $msg->setUrlAnalysis([
                    'data' => [
                        'id' => 'u-example-fixture-id',
                        'type' => 'analysis',
                        'attributes' => [
                            'stats' => [
                                'malicious' => 2,
                                'suspicious' => 1,
                                'harmless' => 87,
                                'undetected' => 0,
                                'timeout' => 0,
                            ],
                            'status' => 'completed',
                        ],
                    ],
                    'verdicts' => [
                        'overall' => [
                            'score' => 25,
                            'malicious' => false,
                            'categories' => [],
                        ],
                    ],
                    'meta' => [
                        'url_info' => [
                            'url' => 'https://example.com',
                        ],
                    ],
                ]);
            }

            $manager->persist($msg);
        }
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            ConversationFixtures::class,
            ChannelFixtures::class,
            DirectionFixtures::class,
        ];
    }
}
