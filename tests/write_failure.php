<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('CAMPURA_DB_NAME')!=='campura_school_test' || getenv('CAMPURA_DB_HOST')!=='db') throw new RuntimeException('isolated test database required');
// /proc cannot host uploaded files even when the container runs as root.
putenv('CAMPURA_UPLOAD_ROOT=/proc/campura-test-unwritable');
require_once __DIR__.'/../includes/school/files.php';
$pdo=school_db(); $id=(int)$argv[1]; $school=(int)$argv[2];
$before=school_one($pdo,'SELECT content,version FROM course_tables WHERE tableID=? AND schoolID=?',[$id,$school]);
try {
    school_transaction($pdo,function() use($pdo,$id) {
        school_query($pdo,'UPDATE course_tables SET version=version+1 WHERE tableID=?',[$id]);
        school_store_file('course_tables','unwritable');
    });
    throw new LogicException('write unexpectedly succeeded');
} catch (RuntimeException) {
    $after=school_one($pdo,'SELECT content,version FROM course_tables WHERE tableID=?',[$id]);
    if ($after!==$before) throw new LogicException('failed write changed active record');
}
echo "Write-failure rollback verified.\n";
