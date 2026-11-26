<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Tests\Unit\Util;

use OpenColibri\Starter\Core\Util\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Database::class)]
final class DatabaseTest extends TestCase
{
    private const string MIGRATION = <<<'PHP'
        final class Version20261126120000 extends AbstractMigration
        {
            public function up(Schema $schema): void
            {
                $this->addSql('DROP SEQUENCE messenger_messages_id_seq CASCADE');
                $this->addSql('CREATE SEQUENCE dedi_seo_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
                $this->addSql('CREATE SEQUENCE sylius_address_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
                $this->addSql('CREATE TABLE dedi_seo (id INT NOT NULL, PRIMARY KEY (id))');
                $this->addSql('ALTER TABLE sylius_product ADD seo_id INT DEFAULT NULL');
            }

            public function down(Schema $schema): void
            {
                $this->addSql('DROP SEQUENCE dedi_seo_id_seq CASCADE');
                $this->addSql('CREATE SEQUENCE messenger_messages_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
                $this->addSql('DROP TABLE dedi_seo');
                $this->addSql('ALTER TABLE sylius_product DROP CONSTRAINT FK_SEO');
                $this->addSql('ALTER TABLE sylius_product DROP seo_id');
            }
        }
        PHP;

    public function testKeepSequencesOfKeepsOnlyTheSequencesOfTheTables(): void
    {
        $code = Database::keepSequencesOf(['dedi_seo.*', 'sylius_product'], self::MIGRATION);

        static::assertStringContainsString("CREATE SEQUENCE dedi_seo_id_seq", $code);
        static::assertStringContainsString("DROP SEQUENCE dedi_seo_id_seq", $code);
        static::assertStringNotContainsString('messenger_messages_id_seq', $code);
        static::assertStringNotContainsString('sylius_address_id_seq', $code);
        static::assertStringContainsString("ALTER TABLE sylius_product ADD seo_id", $code);
    }

    public function testDropTablesLastMovesTheTableDropsToTheEndOfUpAndDown(): void
    {
        $code = Database::dropTablesLast(<<<'PHP'
            final class Version20261126130000 extends AbstractMigration
            {
                public function up(Schema $schema): void
                {
                    $this->addSql('DROP TABLE dedi_seo');
                    $this->addSql('ALTER TABLE sylius_product DROP CONSTRAINT FK_SEO');
                    $this->addSql('ALTER TABLE sylius_product DROP seo_id');
                }

                public function down(Schema $schema): void
                {
                    $this->addSql('CREATE TABLE dedi_seo (id INT NOT NULL, PRIMARY KEY (id))');
                    $this->addSql('ALTER TABLE sylius_product ADD seo_id INT DEFAULT NULL');
                }
            }
            PHP);

        static::assertStringContainsString(<<<'PHP'
                    $this->addSql('ALTER TABLE sylius_product DROP seo_id');
                    $this->addSql('DROP TABLE dedi_seo');
                }
            PHP, $code);

        $code = Database::dropTablesLast(self::MIGRATION);

        static::assertStringContainsString(<<<'PHP'
                    $this->addSql('ALTER TABLE sylius_product DROP seo_id');
                    $this->addSql('DROP TABLE dedi_seo');
                }
            PHP, $code);
        static::assertStringContainsString(<<<'PHP'
                    $this->addSql('CREATE TABLE dedi_seo (id INT NOT NULL, PRIMARY KEY (id))');
                    $this->addSql('ALTER TABLE sylius_product ADD seo_id INT DEFAULT NULL');
                }
            PHP, $code);
    }

    public function testGroupByTableGroupsTheStatementsOfEachTable(): void
    {
        $tables = Database::groupByTable(<<<'SQL'
            CREATE SCHEMA public;
            CREATE SEQUENCE dedi_seo_id_seq INCREMENT BY 1 MINVALUE 1 START 1;
            CREATE TABLE dedi_seo (id INT NOT NULL, PRIMARY KEY (id));
            CREATE UNIQUE INDEX UNIQ_SEO ON sylius_product (seo_id);
            ALTER TABLE sylius_product ADD CONSTRAINT FK_SEO FOREIGN KEY (seo_id) REFERENCES dedi_seo (id);
            SQL);

        static::assertSame(['dedi_seo', 'sylius_product'], array_keys($tables));
        static::assertStringContainsString('CREATE SEQUENCE dedi_seo_id_seq', $tables['dedi_seo']);
        static::assertStringContainsString('FK_SEO', $tables['sylius_product']);
    }

    public function testSkipWhenNothingToDropGuardsTheRemovalMigration(): void
    {
        $code = Database::skipWhenNothingToDrop(<<<'PHP'
                public function up(Schema $schema): void
                {
                    $this->addSql('ALTER TABLE sylius_product DROP CONSTRAINT FK_SEO');
                    $this->addSql('ALTER TABLE sylius_product DROP seo_id');
                    $this->addSql('DROP TABLE dedi_seo');
                }
            PHP, 'Remove the SEO plugin');

        static::assertStringContainsString(<<<'PHP'
                {
                    // Removed before it was deployed here: nothing to drop.
                    $this->skipIf(!($schema->hasTable('dedi_seo')
                        || ($schema->hasTable('sylius_product') && $schema->getTable('sylius_product')->hasColumn('seo_id'))), 'Remove the SEO plugin: none of its tables and columns exist here.');
                    $this->addSql('ALTER TABLE sylius_product DROP CONSTRAINT FK_SEO');
            PHP, $code);
        static::assertStringNotContainsString("hasColumn('CONSTRAINT')", $code);
    }

    public function testSkipWhenNothingToDropLeavesTheAddMigrationsAsTheyAre(): void
    {
        $code = <<<'PHP'
                public function up(Schema $schema): void
                {
                    $this->addSql('ALTER TABLE sylius_product ADD seo_id INT DEFAULT NULL');
                }
            PHP;

        static::assertSame($code, Database::skipWhenNothingToDrop($code, 'Add the SEO plugin'));
    }
}
