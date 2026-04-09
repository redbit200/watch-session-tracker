<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260409170023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sessions and events tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                session_id      VARCHAR(255)     NOT NULL,
                user_id         VARCHAR(255)     NOT NULL,
                stream_event_id VARCHAR(255)     NOT NULL,
                state           VARCHAR(50)      NOT NULL,
                started_at      DATETIME         NOT NULL,
                last_event_at   DATETIME         NOT NULL,
                last_position   DOUBLE PRECISION,
                quality         VARCHAR(50),
                PRIMARY KEY (session_id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE events (
                id              INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
                event_id        VARCHAR(255) NOT NULL,
                session_id      VARCHAR(255) NOT NULL REFERENCES sessions(session_id),
                event_type      VARCHAR(50)  NOT NULL,
                event_timestamp DATETIME     NOT NULL,
                received_at     DATETIME     NOT NULL,
                payload         CLOB         NOT NULL,
                CONSTRAINT uniq_event_id UNIQUE (event_id)
            )
        SQL);

        // Covers the active-count query: filter by stream_event_id + state + last_event_at
        $this->addSql('CREATE INDEX idx_sessions_stream_event ON sessions (stream_event_id, state, last_event_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE sessions');
    }
}
