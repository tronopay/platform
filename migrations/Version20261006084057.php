<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006084057 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task ADD price_sun INT NOT NULL');
        $this->addSql('ALTER TABLE task ADD refund_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD webhook VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE task ALTER location TYPE geography(POINT,4326)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task DROP price_sun');
        $this->addSql('ALTER TABLE task DROP refund_address');
        $this->addSql('ALTER TABLE task DROP webhook');
        $this->addSql('ALTER TABLE task ALTER location TYPE geometry(POINT,4326)');
    }
}
