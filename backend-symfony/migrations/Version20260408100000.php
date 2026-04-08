<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add performance indexes for conversation list, message queries, and IOC lookups.
 * All indexes are read-only additions — no schema changes.
 */
final class Version20260408100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes: conversation(status,ts_last), message(conv_id,ts_msg), observed_ioc(indicator_id,ts_observed)';
    }

    public function up(Schema $schema): void
    {
        // Conversation: partial composite index for list + stale detection
        $this->addSql('CREATE INDEX idx_conv_status_ts_last ON conversation (status, ts_last DESC) WHERE deleted_at IS NULL');

        // Conversation: ts_first for analytics date-range queries
        $this->addSql('CREATE INDEX idx_conv_ts_first ON conversation (ts_first)');

        // Message: composite for conversation messages sorted by time
        $this->addSql('CREATE INDEX idx_message_conv_ts ON message (conv_id, ts_msg DESC)');

        // Message: ts_msg for analytics timeline queries
        $this->addSql('CREATE INDEX idx_message_ts_msg ON message (ts_msg)');

        // ObservedIoc: indicator_id for IOC detail + co-occurrence graph
        $this->addSql('CREATE INDEX idx_observed_ioc_indicator ON observed_ioc (indicator_id)');

        // ObservedIoc: ts_observed for IOC timeline queries
        $this->addSql('CREATE INDEX idx_observed_ioc_ts ON observed_ioc (ts_observed)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_conv_status_ts_last');
        $this->addSql('DROP INDEX IF EXISTS idx_conv_ts_first');
        $this->addSql('DROP INDEX IF EXISTS idx_message_conv_ts');
        $this->addSql('DROP INDEX IF EXISTS idx_message_ts_msg');
        $this->addSql('DROP INDEX IF EXISTS idx_observed_ioc_indicator');
        $this->addSql('DROP INDEX IF EXISTS idx_observed_ioc_ts');
    }
}
