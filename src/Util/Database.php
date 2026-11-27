<?php

declare(strict_types=1);

namespace OpenColibri\Starter\Core\Util;

use OpenColibri\Starter\Core\App;
use Symfony\Component\Process\Process;

use function Castor\fs;
use function Castor\io;
use function Castor\run;

final readonly class Database
{
    public static function migrate(App $app): void
    {
        if (Runner::isLocal()) {
            // No "{app}:db:migrate" Docker task next to the application: migrate directly.
            Runner::run($app, 'php bin/console doctrine:migrations:migrate -n --allow-no-migration --all-or-nothing');

            return;
        }

        run(['castor', $app->name() . ':db:migrate']);
    }

    public static function diff(App $app, ?string $namespace = 'DoctrineMigrations'): Process
    {
        $command = 'bin/console doctrine:migrations:diff';

        if (null !== $namespace) {
            $command .= ' --namespace=' . $namespace;
        }

        return Docker::run($app, $command);
    }

    /**
     * The SQL creating each table of the Doctrine mapping (with its indexes, foreign keys and id sequence), by table
     * name: taken before a plugin is added or removed, it tells generatePluginMigration() which tables the plugin
     * changes, without any list to maintain.
     *
     * @return array<string, string>
     */
    public static function mappedSchema(App $app): array
    {
        return self::groupByTable(Docker::run($app, 'bin/console doctrine:schema:create --dump-sql --no-interaction --no-ansi')->getOutput());
    }

    /**
     * @return array<string, string> the statements of the SQL, one per line, grouped by the table they create or change
     */
    public static function groupByTable(string $sql): array
    {
        $tables = [];

        foreach (explode("\n", $sql) as $statement) {
            if (preg_match('/^(?:CREATE TABLE|ALTER TABLE|COMMENT ON TABLE|CREATE (?:UNIQUE )?INDEX \w+ ON|COMMENT ON COLUMN) (\w+)/i', $statement, $matches)
                || preg_match('/^CREATE SEQUENCE (\w+)_id_seq\b/i', $statement, $matches)) {
                $table = strtolower($matches[1]);
                $tables[$table] = ($tables[$table] ?? '') . trim($statement) . "\n";
            }
        }

        ksort($tables);

        return $tables;
    }

    /**
     * Generates a migration of the tables a plugin changed since the mapped schema taken before it was added or
     * removed (see mappedSchema()): the schema drift of the other tables stays out of it, and the migration follows the
     * plugin version and the database platform. Removing a plugin generates a migration too, rather than rolling back
     * the plugin ones: the migrations stay in the application, so every environment where the plugin was deployed
     * drops its tables as well, and skips it where the plugin never was (see skipWhenNothingToDrop()).
     *
     * @param array<string, string> $before
     * @param list<string>          $forgottenMigrations the migrations of a removed plugin (see pluginMigrations()), no
     *                                                   longer executed once its tables are dropped: a reinstall runs them
     *
     * @return string|null the path of the migration, null when the plugin changes no table and forgets no migration
     */
    public static function generatePluginMigration(App $app, string $description, array $before, array $forgottenMigrations = []): ?string
    {
        $after = self::mappedSchema($app);
        $tables = array_values(array_filter(
            array_unique([...array_keys($before), ...array_keys($after)]),
            static fn(string $table): bool => ($before[$table] ?? null) !== ($after[$table] ?? null),
        ));
        $migrations = self::migrationFiles($app);

        if ([] !== $tables) {
            // Tables another package updated by Composer changed too would show up here: list them, to spot it.
            io()->note(\sprintf('%s: migration of the tables %s.', $description, implode(', ', $tables)));
        }

        $tables = array_map(static fn(string $table): string => preg_quote($table, '/'), $tables);

        if ([] !== $tables) {
            // The tables and their id sequences (PostgreSQL): the sequences of the Sylius tables a plugin adds columns to
            // exist.
            Docker::run($app, \sprintf(
                'bin/console doctrine:migrations:diff --no-interaction --allow-empty-diff --namespace=DoctrineMigrations --filter-expression=%s',
                escapeshellarg(\sprintf('/^(%s)(_id_seq)?$/', implode('|', $tables))),
            ));
        }

        if ([] !== $forgottenMigrations && [] === array_diff(self::migrationFiles($app), $migrations)) {
            Docker::run($app, 'bin/console doctrine:migrations:generate --no-interaction --namespace=DoctrineMigrations');
        }

        $created = array_values(array_diff(self::migrationFiles($app), $migrations));

        if ([] === $created) {
            return null;
        }

        $path = $created[0];
        $code = fs()->readFile($path);
        $code = preg_replace(
            '/(function getDescription\(\): string\s*\{\s*return )\'\';/',
            '${1}' . var_export($description, true) . ';',
            $code,
            1,
        ) ?? $code;
        $code = self::skipWhenNothingToDrop(self::dropTablesLast(self::keepSequencesOf($tables, $code)), $description);

        if ([] !== $forgottenMigrations) {
            [$table, $column] = self::migrationsStorage($app);
            $code = self::appendToUp($code, implode("\n", array_map(
                static fn(string $migration): string => \sprintf(
                    '        $this->addSql(%s, [%s]);',
                    var_export(\sprintf('DELETE FROM %s WHERE %s = ?', $table, $column), true),
                    var_export($migration, true),
                ),
                $forgottenMigrations,
            )));
        }

        try {
            self::checkPluginMigration($code, $tables, $description, $forgottenMigrations);
        } catch (\RuntimeException $exception) {
            // Out of the migrations folder, so the next migrate does not run it.
            fs()->rename($path, $path . '.rejected', true);

            throw new \RuntimeException(\sprintf('The migration generated for "%s" was set aside in "%s.rejected": %s', $description, $path, $exception->getMessage()), 0, $exception);
        }

        fs()->dumpFile($path, $code);

        return $path;
    }

    /**
     * The generated migration is reworked with regular expressions on the Doctrine Migrations template: check the
     * result regardless of them, so a template they no longer match fails instead of leaving the migration unfixed.
     *
     * @param list<string> $tables              regular expressions of the table names
     * @param list<string> $forgottenMigrations
     *
     * @throws \RuntimeException when the migration is not the expected one
     */
    public static function checkPluginMigration(string $code, array $tables, string $description, array $forgottenMigrations = []): void
    {
        $errors = [];

        if (!str_contains($code, var_export($description, true))) {
            $errors[] = 'its description is not set';
        }

        preg_match_all('/\bSEQUENCE\s+(?:IF\s+EXISTS\s+)?[`"]?(\w+)/i', $code, $sequences);
        $allowed = \sprintf('/^(%s)_id_seq$/i', implode('|', $tables ?: ['(?!)']));

        foreach (array_unique($sequences[1]) as $sequence) {
            if (!preg_match($allowed, $sequence)) {
                $errors[] = \sprintf('it changes the sequence "%s" of another table', $sequence);
            }
        }

        // The statements of each method: a "function" line starts a new one.
        $methods = [];
        $method = null;

        foreach (explode("\n", $code) as $line) {
            if (preg_match('/\bfunction\s+(\w+)\s*\(/', $line, $matches)) {
                $method = $matches[1];
            } elseif (null !== $method && str_contains($line, 'addSql(')) {
                $methods[$method][] = $line;
            }
        }

        if (!str_contains($code, 'function up(') || !str_contains($code, 'function down(')) {
            $errors[] = 'its up() and down() methods are not found';
        }

        foreach ($methods as $name => $statements) {
            $dropped = false;

            foreach ($statements as $statement) {
                if (preg_match('/DROP\s+TABLE/i', $statement)) {
                    $dropped = true;
                } elseif ($dropped && !preg_match('/DELETE\s+FROM/i', $statement)) {
                    $errors[] = \sprintf('%s() drops a table before other statements', $name);

                    break;
                }
            }
        }

        $drops = preg_grep('/DROP\s+TABLE|ALTER\s+TABLE\s+\w+\s+DROP\s+(?!CONSTRAINT|FOREIGN\s+KEY|INDEX|PRIMARY)/i', $methods['up'] ?? []);

        if ([] !== $drops && !str_contains($code, 'skipIf(')) {
            $errors[] = 'up() drops tables or columns without skipping where they do not exist';
        }

        foreach ($forgottenMigrations as $migration) {
            if (!str_contains($code, var_export($migration, true))) {
                $errors[] = \sprintf('it does not forget the migration "%s"', $migration);
            }
        }

        if ([] !== $errors) {
            throw new \RuntimeException(implode(', ', $errors) . '.');
        }
    }

    /**
     * @param list<string> $descriptions the descriptions generatePluginMigration() gave them
     *
     * @return list<string> the paths of the migrations generated for a plugin, in their order
     */
    public static function generatedMigrations(App $app, array $descriptions): array
    {
        $markers = array_map(static fn(string $description): string => var_export($description, true), $descriptions);

        return array_values(array_filter(
            self::migrationFiles($app),
            static fn(string $path): bool => [] !== array_filter($markers, static fn(string $marker): bool => str_contains(fs()->readFile($path), 'return ' . $marker . ';')),
        ));
    }

    /**
     * Rolls back the generated migrations of a plugin that never left this application, the last one first, and
     * deletes them: nothing else ran them, so no environment needs a migration dropping its tables.
     *
     * @param list<string> $paths see generatedMigrations()
     */
    public static function discardGeneratedMigrations(App $app, array $paths): void
    {
        $listed = Docker::run($app, 'bin/console doctrine:migrations:list --no-interaction --no-ansi')->getOutput();

        foreach (array_reverse($paths) as $path) {
            $migration = 'DoctrineMigrations\\' . basename($path, '.php');

            if (preg_match(\sprintf('/^\|\s*%s\s+\|\s+migrated\s+\|/m', preg_quote($migration, '/')), $listed)) {
                Docker::run($app, \sprintf('bin/console doctrine:migrations:execute --down --no-interaction %s', escapeshellarg($migration)));
            }

            fs()->remove($path);
        }
    }

    /**
     * Deletes a generated migration that ran, without rolling it back: its changes stay, as if made without a migration.
     *
     * @param string $path see generatePluginMigration()
     */
    public static function forgetGeneratedMigration(App $app, string $path): void
    {
        Docker::run($app, \sprintf('bin/console doctrine:migrations:version --delete --no-interaction %s', escapeshellarg('DoctrineMigrations\\' . basename($path, '.php'))));
        fs()->remove($path);
    }

    /**
     * @return list<string> the migrations of the namespace a plugin ships, executed or not
     */
    public static function pluginMigrations(App $app, string $namespace): array
    {
        preg_match_all(
            \sprintf('/^\|\s*(%s\\\\[^\s|]+)\s+\|/m', preg_quote($namespace, '/')),
            Docker::run($app, 'bin/console doctrine:migrations:list --no-interaction --no-ansi')->getOutput(),
            $matches,
        );

        return $matches[1];
    }

    /**
     * A plugin removed before it was deployed somewhere leaves a migration dropping tables or columns that this
     * environment never had: skip it there. A partial state still fails, being an inconsistency to look at.
     */
    public static function skipWhenNothingToDrop(string $code, string $description): string
    {
        if (!preg_match('/function up\(Schema \$schema\): void\s*\{\n(.*?)\n    \}/s', $code, $up)) {
            return $code;
        }

        $checks = [];

        preg_match_all("/addSql\\('DROP TABLE (\\w+)'/", $up[1], $tables);

        foreach ($tables[1] as $table) {
            $checks[] = \sprintf('$schema->hasTable(%s)', var_export($table, true));
        }

        preg_match_all("/addSql\\('ALTER TABLE (\\w+) DROP (?!CONSTRAINT|FOREIGN KEY|INDEX|PRIMARY)(?:COLUMN )?(\\w+)'/", $up[1], $columns, \PREG_SET_ORDER);

        foreach ($columns as [, $table, $column]) {
            $checks[] = \sprintf('($schema->hasTable(%1$s) && $schema->getTable(%1$s)->hasColumn(%2$s))', var_export($table, true), var_export($column, true));
        }

        if ([] === $checks) {
            return $code;
        }

        $skip = \sprintf(
            "        // Removed before it was deployed here: nothing to drop.\n        \$this->skipIf(!(%s), %s);\n",
            implode("\n            || ", $checks),
            var_export(\sprintf('%s: none of its tables and columns exist here.', $description), true),
        );

        return preg_replace('/(function up\(Schema \$schema\): void\s*\{\n)/', '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $skip), $code, 1) ?? $code;
    }

    private static function appendToUp(string $code, string $lines): string
    {
        return preg_replace_callback(
            '/(function up\(Schema \$schema\): void\s*\{\n.*?)(\n    \})/s',
            static fn(array $matches): string => $matches[1] . "\n" . $lines . $matches[2],
            $code,
            1,
        ) ?? $code;
    }

    /**
     * @return array{string, string} the table and the version column the executed migrations are stored in
     */
    private static function migrationsStorage(App $app): array
    {
        $config = Docker::run($app, 'bin/console debug:config doctrine_migrations storage --no-ansi')->getOutput();

        return [
            preg_match('/^\s*table_name:\s*(\w+)/m', $config, $table) ? $table[1] : 'doctrine_migration_versions',
            preg_match('/^\s*version_column_name:\s*(\w+)/m', $config, $column) && 'null' !== $column[1] ? $column[1] : 'version',
        ];
    }

    /**
     * The filter only applies to the database schema, not to the mapping: the diff creates the id sequences (PostgreSQL)
     * of every other table, and drops the sequences no entity maps (messenger_messages). Keep the ones of the tables.
     *
     * @param list<string> $tables regular expressions of the table names
     */
    public static function keepSequencesOf(array $tables, string $code): string
    {
        $sequences = \sprintf('/^(%s)_id_seq$/', implode('|', $tables));

        return preg_replace_callback(
            '/^ *\$this->addSql\(\'(?:CREATE|DROP) SEQUENCE (\w+)[^\n]*\n/m',
            static fn(array $matches): string => preg_match($sequences, $matches[1]) ? $matches[0] : '',
            $code,
        ) ?? $code;
    }

    /**
     * DBAL drops the tables before it alters the other ones, so a table that another one still references (the
     * foreign key of a column a plugin adds to a Sylius table) cannot be dropped: drop the tables last, in up() (the
     * migration of a removed plugin) as in down() (the one of an added plugin).
     */
    public static function dropTablesLast(string $code): string
    {
        return preg_replace_callback(
            '/(function (?:up|down)\(Schema \$schema\): void\s*\{\n)(.*?)(\n    \})/s',
            static function (array $matches): string {
                $lines = explode("\n", $matches[2]);
                $drops = array_filter($lines, static fn(string $line): bool => str_contains($line, "addSql('DROP TABLE "));

                return $matches[1] . implode("\n", [...array_diff_key($lines, $drops), ...$drops]) . $matches[3];
            },
            $code,
        ) ?? $code;
    }

    /**
     * @return list<string> the paths of the migrations of the application, in their order
     */
    private static function migrationFiles(App $app): array
    {
        $files = glob($app->directory() . '/migrations/Version*.php') ?: [];
        sort($files);

        return $files;
    }

    public static function rollbackPluginMigrations(App $app, string $namespace): void
    {
        $output = Docker::run(
            $app,
            'bin/console doctrine:migrations:list --no-interaction --no-ansi',
        )->getOutput();

        preg_match_all(
            \sprintf(
                '/^\|\s*(%s\\\[^\s|]+)\s+\|\s+migrated\s+\|/m',
                preg_quote($namespace, '/'),
            ),
            $output,
            $matches,
        );

        $migrations = array_reverse($matches[1]);

        if ([] === $migrations) {
            io()->warning(\sprintf(
                'No executed migrations found for "%s".',
                $namespace,
            ));

            return;
        }

        foreach ($migrations as $migration) {
            io()->info(\sprintf(
                'Executing down for %s',
                $migration,
            ));

            Docker::run($app, \sprintf(
                'bin/console doctrine:migrations:execute --down --no-interaction %s',
                escapeshellarg($migration),
            ));
        }

        io()->success('Plugin migrations rolled back.');
    }
}
