<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escrow payouts: one signed money movement (worker payout or client refund)
 * per finished task. See docs/payouts.md.
 */
final class Version20261012195824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payout (escrow worker payout / client refund)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payout (id UUID NOT NULL, direction VARCHAR(16) DEFAULT NULL, from_address VARCHAR(34) DEFAULT NULL, to_address VARCHAR(34) DEFAULT NULL, amount_sun INT NOT NULL, wallet_index INT NOT NULL, status VARCHAR(16) NOT NULL, tx_id VARCHAR(64) DEFAULT NULL, raw_data JSON DEFAULT NULL, raw_data_hex TEXT DEFAULT NULL, expiration TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, batch_id VARCHAR(32) DEFAULT NULL, signature TEXT DEFAULT NULL, signer_address VARCHAR(34) DEFAULT NULL, error TEXT DEFAULT NULL, prepared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, broadcast_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, abandoned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, task_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_payout_task ON payout (task_id)');
        $this->addSql('CREATE INDEX idx_payout_batch_id ON payout (batch_id)');
        $this->addSql('CREATE INDEX idx_payout_status ON payout (status)');
        $this->addSql('ALTER TABLE payout ADD CONSTRAINT FK_4E2EA9028DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payout DROP CONSTRAINT FK_4E2EA9028DB60186');
        $this->addSql('DROP INDEX idx_payout_status');
        $this->addSql('DROP INDEX idx_payout_batch_id');
        $this->addSql('DROP INDEX uniq_payout_task');
        $this->addSql('DROP TABLE payout');
    }
}
