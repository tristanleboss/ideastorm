<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912021645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attachment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(180) NOT NULL, size INTEGER NOT NULL, duration_seconds INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, idea_id INTEGER NOT NULL, uploader_id INTEGER DEFAULT NULL, CONSTRAINT FK_795FD9BB5B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_795FD9BB16678C77 FOREIGN KEY (uploader_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_795FD9BB5B6FEF7D ON attachment (idea_id)');
        $this->addSql('CREATE INDEX IDX_795FD9BB16678C77 ON attachment (uploader_id)');
        $this->addSql('CREATE TABLE comment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, created_at DATETIME NOT NULL, edited_at DATETIME DEFAULT NULL, idea_id INTEGER NOT NULL, author_id INTEGER NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_9474526C5B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9474526C727ACA70 FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_comment_idea_created ON comment (idea_id, created_at)');
        $this->addSql('CREATE INDEX IDX_9474526C5B6FEF7D ON comment (idea_id)');
        $this->addSql('CREATE INDEX IDX_9474526CF675F31B ON comment (author_id)');
        $this->addSql('CREATE INDEX IDX_9474526C727ACA70 ON comment (parent_id)');
        $this->addSql('CREATE TABLE idea (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(180) NOT NULL, body CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, version_label VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INTEGER NOT NULL, author_id INTEGER NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_A8BCA45166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A8BCA45F675F31B FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A8BCA45727ACA70 FOREIGN KEY (parent_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_idea_project_created ON idea (project_id, created_at)');
        $this->addSql('CREATE INDEX IDX_A8BCA45166D1F9C ON idea (project_id)');
        $this->addSql('CREATE INDEX IDX_A8BCA45F675F31B ON idea (author_id)');
        $this->addSql('CREATE INDEX IDX_A8BCA45727ACA70 ON idea (parent_id)');
        $this->addSql('CREATE TABLE project (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, summary VARCHAR(400) NOT NULL, description CLOB DEFAULT NULL, status VARCHAR(20) NOT NULL, accent_color VARCHAR(7) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_slug ON project (slug)');
        $this->addSql('CREATE TABLE project_membership (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, role VARCHAR(20) NOT NULL, focus VARCHAR(180) DEFAULT NULL, joined_at DATETIME NOT NULL, project_id INTEGER NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_9E59A9B7166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9E59A9B7A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_membership_project_user ON project_membership (project_id, user_id)');
        $this->addSql('CREATE INDEX IDX_9E59A9B7166D1F9C ON project_membership (project_id)');
        $this->addSql('CREATE INDEX IDX_9E59A9B7A76ED395 ON project_membership (user_id)');
        $this->addSql('CREATE TABLE social_identity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, provider VARCHAR(40) NOT NULL, provider_user_id VARCHAR(191) NOT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_E4A47DE8A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_provider_user ON social_identity (provider, provider_user_id)');
        $this->addSql('CREATE INDEX IDX_E4A47DE8A76ED395 ON social_identity (user_id)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(120) NOT NULL, password VARCHAR(255) DEFAULT NULL, avatar_url VARCHAR(512) DEFAULT NULL, roles CLOB NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
        $this->addSql('CREATE TABLE vote (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value SMALLINT NOT NULL, cast_at DATETIME NOT NULL, idea_id INTEGER NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_5A1085645B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5A108564A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vote_idea_user ON vote (idea_id, user_id)');
        $this->addSql('CREATE INDEX IDX_5A1085645B6FEF7D ON vote (idea_id)');
        $this->addSql('CREATE INDEX IDX_5A108564A76ED395 ON vote (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE attachment');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE idea');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_membership');
        $this->addSql('DROP TABLE social_identity');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE vote');
    }
}
