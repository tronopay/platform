<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escrow payouts redesign (docs/payouts-redesign.md): the payout row no longer
 * stores the unsigned transaction bytes, the signature or the implicit expiry.
 * Instead it keeps only the intent when it is prepared and, once the
 * settlement machine reports back, the txId, the real fee, the block number
 * and why it failed. The machine holds keys and builds the transaction itself.
 */
final class Version20261013170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payout: intent/report redesign (drop raw tx/signature/expiration, add fee/block/failure/confirmed_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payout ADD failure_kind VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD fee_sun INT DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD block_number BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE payout DROP raw_data');
        $this->addSql('ALTER TABLE payout DROP raw_data_hex');
        $this->addSql('ALTER TABLE payout DROP expiration');
        $this->addSql('ALTER TABLE payout DROP signature');
        $this->addSql('ALTER TABLE payout DROP signer_address');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payout ADD raw_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD raw_data_hex TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD expiration TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD signature TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE payout ADD signer_address VARCHAR(34) DEFAULT NULL');
        $this->addSql('ALTER TABLE payout DROP failure_kind');
        $this->addSql('ALTER TABLE payout DROP fee_sun');
        $this->addSql('ALTER TABLE payout DROP block_number');
        $this->addSql('ALTER TABLE payout DROP confirmed_at');
    }
}
