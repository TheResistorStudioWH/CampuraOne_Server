<?php
declare(strict_types=1);
// CLI only. Usage: php migrate_course_files.php /absolute/site/root [--apply]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/config/database.php')) throw new RuntimeException('site root required');
$apply = in_array('--apply', $argv, true);
$pdo = require $root . '/config/database.php';
require_once $root . '/includes/ics_storage.php';
$demo = file_get_contents(__DIR__ . '/demo-course.ics');
if (!$demo || !str_contains($demo, 'END:VCALENDAR')) throw new RuntimeException('demo file missing');
preg_match_all('/BEGIN:VEVENT\r?\n.*?END:VEVENT/s', $demo, $matches);
if (count($matches[0]) !== 69) throw new RuntimeException('unexpected demo event count');
$rows = $pdo->query('SELECT * FROM course_tables ORDER BY updatedAt DESC, tableID DESC')->fetchAll(PDO::FETCH_ASSOC);
$seen = [];
$plan = [];
foreach ($rows as $row) {
    $ics = readCourseICS($row['content'])['content'];
    $scope = implode('-', [$row['schoolID'], $row['compoundID'], $row['departmentID'], $row['classID']]);
    // Only the verified demonstration school's four existing class scopes get new demo events.
    $addDemo = !isset($seen[$scope]) && (int)$row['schoolID'] === 1 && (int)$row['compoundID'] === 1
        && (int)$row['departmentID'] === 1 && in_array((int)$row['classID'], [1, 3, 301, 302], true);
    $seen[$scope] = true;
    if ($addDemo) {
        $extra = [];
        foreach ($matches[0] as $event) {
            preg_match('/^UID:(.+)$/m', $event, $uid);
            if (!str_contains($ics, trim($uid[1]))) $extra[] = $event;
        }
        if ($extra) $ics = preg_replace('/END:VCALENDAR\s*$/', implode("\r\n", $extra) . "\r\nEND:VCALENDAR\r\n", $ics);
    }
    $path = 'uploads/course_tables/course-' . (int)$row['tableID'] . '-' . substr(hash('sha256', $ics), 0, 20) . '.ics';
    $plan[] = ['row' => $row, 'path' => $path, 'ics' => $ics];
    echo 'table=', $row['tableID'], ' class=', $row['classID'], ' events=', substr_count($ics, 'BEGIN:VEVENT'), ' path=', $path, PHP_EOL;
}
if (!$apply) { echo "DRY RUN ONLY\n"; exit; }
$backupDir = '/www/backup/campura-one/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDir, 0700, true)) throw new RuntimeException('backup failed');
if (file_put_contents($backupDir . '/course_tables.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('backup failed');
chmod($backupDir . '/course_tables.json', 0600);
$directory = $root . '/uploads/course_tables';
if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('cannot create calendar folder');
$pdo->beginTransaction();
try {
    $check = $pdo->prepare('SELECT content FROM course_tables WHERE tableID=? FOR UPDATE');
    $update = $pdo->prepare('UPDATE course_tables SET content=?, updatedAt=? WHERE tableID=?');
    foreach ($plan as $item) {
        $row = $item['row'];
        $check->execute([$row['tableID']]);
        if ($check->fetchColumn() !== $row['content']) throw new RuntimeException('concurrent modification; retry');
        $file = $root . '/' . $item['path'];
        if (!is_file($file)) {
            $temporary = tempnam($directory, 'stage-');
            if (file_put_contents($temporary, $item['ics'], LOCK_EX) === false || !rename($temporary, $file)) throw new RuntimeException('calendar write failed');
            chmod($file, 0644);
        }
        if (hash_file('sha256', $file) !== hash('sha256', $item['ics'])) throw new RuntimeException('file verification failed');
        // Preserve ordering semantics and existing history timestamps during the storage migration.
        $update->execute([$item['path'], $row['updatedAt'], $row['tableID']]);
    }
    $pdo->commit();
    echo 'COMMITTED; rollback source: ', $backupDir, '/course_tables.json', PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
