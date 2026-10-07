<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$pdo = require __DIR__ . '/../config/database.php';
$sql = file_get_contents(__DIR__ . '/../migrations/001_school_console.sql');
// Existing management tables may come from an interrupted run, but must not be
// silently mistaken for another application's similarly named tables.
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',$sql,$definitions,PREG_SET_ORDER);
$existing=array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),0);
foreach ($definitions as $definition) {
    $table=$definition[1];
    if (!in_array($table,$existing,true)) continue;
    $actual=array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(),null,'Field');
    preg_match_all('/^  (\w+) (INT|BIGINT|TINYINT|VARCHAR\(\d+\)|CHAR\(\d+\)|DATETIME|DATE|JSON|TEXT|DECIMAL\(\d+,\d+\)|ENUM\([^\n]+?\))/m',$definition[2],$columns,PREG_SET_ORDER);
    foreach ($columns as [, $name,$expected]) {
        $type=strtolower($actual[$name]['Type'] ?? '');
        $type=preg_replace('/^(int|bigint|tinyint)\(\d+\)$/','$1',$type);
        if ($type!==strtolower($expected)) throw new RuntimeException("Management table schema conflict: $table.$name");
    }
}
// MySQL 5.7 DDL implicitly commits. Each operation is restartable; never drop user data.
$required = [
    'schools' => ['schoolID','schoolName','schoolAddress','schoolLogo','schoolCalendar'],
    'students' => ['studentID','schoolID','studentName','compoundID','departmentID','classID'],
    'compounds' => ['compoundID','schoolID'],
    'departments' => ['departmentID','compoundID'],
    'course_tables' => ['tableID','schoolID','compoundID','departmentID','classID','content'],
    'announcements' => ['announceID','schoolID','content','type','startTime','endTime'],
    'announcement_targets' => ['targetID','announceID','targetJSON'],
    'advertisements' => ['adID','saleID','type','img','startTime','endTime'],
    'main_shops' => ['shopID','schoolID','adIDs'],
];
foreach ($required as $table => $columns) {
    $info=$pdo->query("SHOW COLUMNS FROM $table")->fetchAll();
    $actual = array_column($info, 'Field');
    if (array_diff($columns, $actual)) throw new RuntimeException("Schema preflight failed: $table");
    foreach ($info as $column) {
        if (in_array($column['Field'],$columns,true) && str_ends_with($column['Field'],'ID') && !preg_match('/^int(?:\(11\))?$/i',$column['Type'])) {
            throw new RuntimeException("Incompatible signed INT key: $table.".$column['Field']);
        }
    }
    $engine=$pdo->query("SHOW TABLE STATUS LIKE '$table'")->fetch();
    if (strtolower($engine['Engine'] ?? '')!=='innodb') throw new RuntimeException("InnoDB required: $table");
}
$adds = [
    'schools' => ['version' => 'INT NOT NULL DEFAULT 1'],
    'students' => ['studentNumber' => 'VARCHAR(64) DEFAULT NULL'],
    'announcements' => ['title' => 'VARCHAR(200) DEFAULT NULL', 'revokedAt' => 'DATETIME DEFAULT NULL', 'version' => 'INT NOT NULL DEFAULT 1'],
    'course_tables' => ['semesterID' => 'INT DEFAULT NULL', 'baseVersionID' => 'INT DEFAULT NULL', 'version' => 'INT NOT NULL DEFAULT 1'],
];
// Inspect existing incremental columns before the first ALTER. Never reinterpret a
// similarly named field whose type belongs to a different deployment.
foreach ($adds as $table=>$columns) {
    $info=array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(),null,'Field');
    foreach ($columns as $name=>$definition) if (isset($info[$name])) {
        $expected=strtolower(explode(' ',$definition)[0]);
        $actual=strtolower($info[$name]['Type']);
        if ($expected==='int') $actual=preg_replace('/^int\(\d+\)$/','int',$actual);
        if ($actual!==$expected) throw new RuntimeException("Incremental column type conflict: $table.$name");
    }
}
if (in_array('studentNumber',array_column($pdo->query('SHOW COLUMNS FROM students')->fetchAll(),'Field'),true)
    && $pdo->query('SELECT schoolID FROM students WHERE studentNumber IS NOT NULL GROUP BY schoolID,studentNumber HAVING COUNT(*)>1 LIMIT 1')->fetch()) {
    throw new RuntimeException('Duplicate school/studentNumber; resolve manually before migration');
}
foreach ($adds as $table => $columns) {
    $actual = array_column($pdo->query("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $actual, true)) $pdo->exec("ALTER TABLE $table ADD $name $definition");
    }
}
foreach ([
    ['students', 'school_student_number', '(schoolID,studentNumber)'],
    ['course_tables', 'school_term_class', '(schoolID,semesterID,compoundID,departmentID,classID)'],
] as [$table,$name,$columns]) {
    $keys=array_values(array_filter($pdo->query("SHOW INDEX FROM $table")->fetchAll(),fn($key)=>$key['Key_name']===$name));
    if (!$keys) $pdo->exec("CREATE UNIQUE INDEX $name ON $table $columns");
    else {
        usort($keys,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        if ((int)$keys[0]['Non_unique']!==0 || array_column($keys,'Column_name')!==explode(',',trim($columns,'()'))) {
            throw new RuntimeException("Unique index definition conflict: $table.$name");
        }
    }
}
foreach (explode(';', $sql) as $statement) if (trim($statement)) $pdo->exec($statement);
$owners = [];
foreach ($pdo->query('SELECT shopID,schoolID,adIDs FROM main_shops') as $shop) {
    foreach ((array)json_decode($shop['adIDs'] ?: '[]', true) as $id) $owners[(int)$id][] = $shop;
}
foreach ($pdo->query('SELECT adID FROM advertisements WHERE adID NOT IN (SELECT adID FROM advertisement_reviews)') as $ad) {
    $matches = $owners[(int)$ad['adID']] ?? [];
    if (count($matches) !== 1) {
        echo "UNASSIGNED advertisement ", $ad['adID'], ": owner missing or ambiguous; left unchanged\n";
        continue;
    }
    $shop = $matches[0];
    $pdo->prepare("INSERT INTO advertisement_reviews (adID,schoolID,shopID,status,reason) VALUES (?,?,?,'approved','Legacy advertisement migration')")
        ->execute([$ad['adID'],$shop['schoolID'],$shop['shopID']]);
}
$pdo->exec("INSERT IGNORE INTO school_schema_migrations (migration) VALUES ('001_school_console')");
echo "School console migration complete. Legacy ICS and credentials unchanged.\n";
