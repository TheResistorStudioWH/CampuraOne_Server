<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('CAMPURA_DB_NAME')!=='campura_school_test' || getenv('CAMPURA_DB_HOST')!=='db') throw new RuntimeException('isolated test database required');
$pdo=require __DIR__.'/../config/database.php';
if (!$pdo->query("SHOW TABLES LIKE 'schools'")->fetch()) {
    // Import schema only. Never import real user records, password hashes, or ICS data.
    $dump=file_get_contents(__DIR__.'/fixtures/legacy-schema.sql');
    preg_match_all('/CREATE TABLE\s+.*?;\s/si',$dump,$creates);
    preg_match_all('/ALTER TABLE\s+.*?;\s/si',$dump,$alters);
    foreach ([...$creates[0],...$alters[0]] as $statement) $pdo->exec($statement);
}
echo "Isolated legacy schema ready (no production records imported).\n";
