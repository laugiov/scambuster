<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'preprod:copy-conversations',
    description: 'Copie les conversations de preprod vers dev pour tests API'
)]
class PreprodCopyConversationsCommand extends Command
{
    private const PREPROD_DSN = 'postgresql://scambuster:postgres@postgres-preprod:5432/scambuster_preprod';

    public function __construct(
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('📋 Copie Conversations Preprod → Dev');

        // 1. Vérifier connexion preprod
        $io->section('1. Connexion à preprod');

        try {
            $preprodConn = \Doctrine\DBAL\DriverManager::getConnection([
                'url' => self::PREPROD_DSN,
            ]);

            $preprodCount = (int) $preprodConn->fetchOne('SELECT COUNT(*) FROM conversation');
            $io->success(sprintf('Connecté à preprod: %d conversations trouvées', $preprodCount));
        } catch (\Exception $e) {
            $io->error('Impossible de se connecter à preprod: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Nettoyer dev
        $io->section('2. Nettoyage de la base dev');

        try {
            $this->connection->executeStatement('TRUNCATE TABLE message CASCADE');
            $this->connection->executeStatement('TRUNCATE TABLE conversation CASCADE');
            $this->connection->executeStatement('TRUNCATE TABLE persona_performance_stats CASCADE');
            $io->success('Base dev nettoyée');
        } catch (\Exception $e) {
            $io->error('Erreur lors du nettoyage: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 3. Copier conversations
        $io->section('3. Copie des conversations');

        try {
            // Copier conversations
            $convCopied = $this->connection->executeStatement("
                INSERT INTO conversation (
                    conv_id, primary_channel_id, scam_type_id, account_id, persona_id,
                    status, score_risk, ts_first, ts_last, stix_id,
                    created_at, updated_at, deleted_at, delivery, tlp,
                    engagement_duration_sec, turns_count, reward_value
                )
                SELECT
                    c.conv_id, c.primary_channel_id, c.scam_type_id, c.account_id, c.persona_id,
                    c.status, c.score_risk, c.ts_first, c.ts_last, c.stix_id,
                    c.created_at, c.updated_at, c.deleted_at, c.delivery, c.tlp,
                    c.engagement_duration_sec, c.turns_count, c.reward_value
                FROM dblink(
                    '" . self::PREPROD_DSN . "',
                    'SELECT conv_id, primary_channel_id, scam_type_id, account_id, persona_id,
                            status, score_risk, ts_first, ts_last, stix_id,
                            created_at, updated_at, deleted_at, delivery, tlp,
                            engagement_duration_sec, turns_count, reward_value
                     FROM conversation'
                ) AS c(
                    conv_id uuid, primary_channel_id bigint, scam_type_id bigint, account_id uuid, persona_id uuid,
                    status text, score_risk int, ts_first timestamp, ts_last timestamp, stix_id text,
                    created_at timestamp, updated_at timestamp, deleted_at timestamp, delivery text, tlp text,
                    engagement_duration_sec int, turns_count int, reward_value numeric(5,4)
                )
            ");

            $io->success(sprintf('%d conversations copiées', $convCopied));
        } catch (\Exception $e) {
            $io->error('Erreur lors de la copie: ' . $e->getMessage());
            $io->note('Assurez-vous que l\'extension dblink est installée: CREATE EXTENSION IF NOT EXISTS dblink;');
            return Command::FAILURE;
        }

        // 4. Copier messages
        $io->section('4. Copie des messages');

        try {
            $msgCopied = $this->connection->executeStatement("
                INSERT INTO message (
                    msg_id, conv_id, channel_id, direction_id,
                    lang_detect, subject, body_text, body_html,
                    headers, composite_hash, vector_id,
                    reply_to, ts_msg, ts_ingest, created_at, updated_at, deleted_at
                )
                SELECT
                    m.msg_id, m.conv_id, m.channel_id, m.direction_id,
                    m.lang_detect, m.subject, m.body_text, m.body_html,
                    m.headers, m.composite_hash, m.vector_id,
                    m.reply_to, m.ts_msg, m.ts_ingest, m.created_at, m.updated_at, m.deleted_at
                FROM dblink(
                    '" . self::PREPROD_DSN . "',
                    'SELECT msg_id, conv_id, channel_id, direction_id,
                            lang_detect, subject, body_text, body_html,
                            headers, composite_hash, vector_id,
                            reply_to, ts_msg, ts_ingest, created_at, updated_at, deleted_at
                     FROM message'
                ) AS m(
                    msg_id uuid, conv_id uuid, channel_id bigint, direction_id bigint,
                    lang_detect text, subject text, body_text text, body_html text,
                    headers jsonb, composite_hash text, vector_id text,
                    reply_to text, ts_msg timestamp, ts_ingest timestamp, created_at timestamp, updated_at timestamp, deleted_at timestamp
                )
            ");

            $io->success(sprintf('%d messages copiés', $msgCopied));
        } catch (\Exception $e) {
            $io->warning('Erreur lors de la copie des messages: ' . $e->getMessage());
        }

        // 5. Statistiques finales
        $io->section('5. Statistiques finales');

        $devCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM conversation');
        $devMsgCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message');

        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Conversations en dev', $devCount],
                ['Messages en dev', $devMsgCount],
            ]
        );

        $io->success('✅ Copie terminée avec succès !');
        $io->note('Les conversations de preprod sont maintenant accessibles via l\'API dev');

        return Command::SUCCESS;
    }
}
