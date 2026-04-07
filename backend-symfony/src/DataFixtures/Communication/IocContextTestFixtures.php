<?php

declare(strict_types=1);

namespace App\DataFixtures\Communication;

use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates test IOCs with indicator entries for IocContextService tests.
 * Depends on MessageFixtures (needs messages with conversations).
 */
class IocContextTestFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct()
    {
    }

    public static function getGroups(): array
    {
        return ['test'];
    }

    public function getDependencies(): array
    {
        return [
            MessageFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // Use Doctrine's connection (same transaction as DAMA)
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $manager;
        $conn = $em->getConnection();

        // Find an inbound message with a conversation
        $inboundMsg = $conn->fetchAssociative(
            'SELECT m.msg_id, m.conv_id, m.ts_msg'
            . ' FROM message m'
            . ' JOIN lkp_direction d ON m.direction = d.dir_id'
            . " WHERE d.code = 'in' AND m.deleted_at IS NULL"
            . ' LIMIT 1'
        );

        if (!$inboundMsg || !\is_string($inboundMsg['msg_id'])) {
            return;
        }

        $msgId = $inboundMsg['msg_id'];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $indicators = [
            ['id' => 'aaaaaaaa-0001-4000-8000-000000000001', 'type' => 'url', 'value' => 'https://evil-test.com/pay', 'value_norm' => 'hxxps://evil-test[.]com/pay'],
            ['id' => 'aaaaaaaa-0002-4000-8000-000000000002', 'type' => 'iban', 'value' => 'FR7630006000011234567890189', 'value_norm' => 'FR7630006000011234567890189'],
            ['id' => 'aaaaaaaa-0003-4000-8000-000000000003', 'type' => 'phone', 'value' => '+33612345678', 'value_norm' => '+33612345678'],
        ];

        $obsIds = [];

        foreach ($indicators as $ind) {
            // Insert indicator via same connection (DAMA transaction)
            $conn->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)'
                . " VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, 'AMBER', :now, :now)"
                . ' ON CONFLICT (indicator_id) DO NOTHING',
                ['id' => $ind['id'], 'type' => $ind['type'], 'value' => $ind['value'], 'valueNorm' => $ind['value_norm'], 'now' => $now]
            );

            $message = $manager->getRepository(Message::class)->find($msgId);

            if (!$message) {
                continue;
            }

            $obsId = sprintf('bbbbbbbb-%04d-4000-8000-000000000001', \count($obsIds) + 1);
            $obsIds[] = $obsId;

            $ioc = new ObservedIoc(
                $obsId,
                $message,
                $ind['id'],
                [
                    'type' => $ind['type'],
                    'value' => $ind['value'],
                    'value_norm' => $ind['value_norm'],
                    'extraction_method' => 'llm',
                    'source' => 'body',
                ],
                new \DateTimeImmutable(),
                0.95
            );

            $manager->persist($ioc);
        }

        $manager->flush();
    }
}
