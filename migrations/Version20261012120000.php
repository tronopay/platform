<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Worker geo-location (PostGIS geography point + when it was last updated).
 */
final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add worker.location (geography POINT) and worker.location_updated_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker ADD location geography(POINT,4326) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_worker_location_sp ON worker USING gist (location)');
        $this->addSql('ALTER TABLE worker ADD location_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE worker DROP location_updated_at');
        $this->addSql('DROP INDEX idx_worker_location_sp');
        $this->addSql('ALTER TABLE worker DROP location');
    }
}
