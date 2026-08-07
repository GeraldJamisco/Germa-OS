<?php

declare(strict_types=1);

/**
 * Creates the germa_os database, applies database/schema.sql, and loads seed
 * data.
 *
 *   php bin/migrate.php              schema + baseline seed
 *   php bin/migrate.php --demo       ... plus the demo pipeline/opportunities
 *   php bin/migrate.php --fresh      drop and rebuild first
 */

require dirname(__DIR__) . '/bootstrap.php';

use Germa\Support\Config;
use Germa\Support\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/migrate.php is a CLI script.\n");
}

$args    = array_slice($argv, 1);
$fresh   = in_array('--fresh', $args, true);
$demo    = in_array('--demo', $args, true);
$dbName  = (string) Config::get('db.database', 'germa_os');

$out = static function (string $line): void {
    echo $line . PHP_EOL;
};

try {
    $server = Database::serverPdo();

    if ($fresh) {
        $out("Dropping database `$dbName` ...");
        $server->exec("DROP DATABASE IF EXISTS `$dbName`");
    }

    $server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $out("Database `$dbName` ready.");

    $files = [dirname(__DIR__) . '/database/schema.sql', dirname(__DIR__) . '/database/seed.sql'];

    foreach ($files as $file) {
        if (!is_file($file)) {
            $out("Skipping missing " . basename($file));
            continue;
        }

        $statements = splitSqlStatements((string) file_get_contents($file));
        $count      = 0;

        foreach ($statements as $statement) {
            Database::pdo()->exec($statement);
            $count++;
        }

        $out(sprintf('%-16s %d statements', basename($file), $count));
    }

    // The demo seeder needs loops and real dates, so it is PHP rather than SQL.
    if ($demo) {
        require dirname(__DIR__) . '/database/seed_demo.php';
        $out('seed_demo.php    demo dataset loaded');
    }

    $tables = (int) Database::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db', ['db' => $dbName], 0);
    $out("Done. $tables tables in `$dbName`.");
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * Strips line comments and splits on statement terminators. Adequate because
 * the schema contains no stored routines or semicolons inside literals.
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $kept  = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || $trimmed === '') {
            continue;
        }
        $kept[] = $line;
    }

    $clean      = implode("\n", $kept);
    $statements = array_map('trim', explode(';', $clean));

    return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
}
