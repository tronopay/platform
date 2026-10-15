<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009142509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_result (id UUID NOT NULL, message TEXT NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE task_result_file (id UUID NOT NULL, original_name VARCHAR(255) NOT NULL, virtual_path VARCHAR(255) NOT NULL, mime_type VARCHAR(255) NOT NULL, size INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, result_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5AD1806C7A7B643 ON task_result_file (result_id)');
        $this->addSql('CREATE TABLE worker (id UUID NOT NULL, tron_address VARCHAR(34) NOT NULL, password VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9FB2BF6252BEC388 ON worker (tron_address)');
        $this->addSql('ALTER TABLE task_result_file ADD CONSTRAINT FK_5AD1806C7A7B643 FOREIGN KEY (result_id) REFERENCES task_result (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD execution_deadline_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD assigned_to_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD result_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES worker (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB257A7B643 FOREIGN KEY (result_id) REFERENCES task_result (id) NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_527EDB257A7B643 ON task (result_id)');
        $this->addSql('CREATE INDEX IDX_527EDB25F4BD7827 ON task (assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task_result_file DROP CONSTRAINT FK_5AD1806C7A7B643');
        $this->addSql('DROP TABLE task_result');
        $this->addSql('DROP TABLE task_result_file');
        $this->addSql('DROP TABLE worker');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25F4BD7827');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB257A7B643');
        $this->addSql('DROP INDEX UNIQ_527EDB257A7B643');
        $this->addSql('DROP INDEX IDX_527EDB25F4BD7827');
        $this->addSql('ALTER TABLE task DROP assigned_at');
        $this->addSql('ALTER TABLE task DROP execution_deadline_at');
        $this->addSql('ALTER TABLE task DROP assigned_to_id');
        $this->addSql('ALTER TABLE task DROP result_id');
    }
}
