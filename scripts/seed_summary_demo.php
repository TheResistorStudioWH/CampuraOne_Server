<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/config/database.php')) throw new RuntimeException('site root required');
$pdo = require $root . '/config/database.php';
$apply = in_array('--apply', $argv, true);
$classes = [1, 3, 301, 302, 1, 3];
$notices = [
    [0, '图书馆开放', '2026年9月25日至10月23日，图书馆一楼自习区每日开放至22:30，22:15停止入场。', null],
    [1, '停水演练', '【演练，非真实停水】9月26日14:00—16:00模拟宿舍供水维护，请勿据此实际停课或撤离。', null],
    [2, '项目路演报名', '## 创新项目路演\n报名截止：10月8日18:00。\n地点：创新中心203。\n每组3—5人，提交演示视频和项目简介。', 1],
    [0, '体育场预约', '9月27日起，体育馆羽毛球场预约于每日12:00开放，预约当日18:00后的场次。', null],
    [2, '奖学金材料演示', '## 材料提交（虚构演示）\n10月9日17:00前将材料交至教学楼A302。此通知不构成真实资格认定，请以学校正式公告为准。', 3],
    [1, '教室临时变更', '【演示】9月28日10:00的数据库原理课，从实训楼301改到教学楼B208，时间不变。', 301],
    [0, '食堂窗口营业', '9月25日至10月23日，二食堂三号窗口试营业，供应时间11:00—13:30、17:00—19:30。', null],
    [0, '社团招新', '10月10日14:00—17:00，创新中心一楼举行社团展示，无需报名。', null],
    [2, '实训机房规范', '## 机房使用\n实训楼402开放预约。不得自行安装软件。离开前备份个人文件并退出账户。未公布预约截止日期。', 302],
    [1, '网络维护演练', '【演练】10月11日00:00—02:00模拟校园网维护，演示环境可能中断，勿用于真实运维通知。', null],
    [0, '失物招领', '图书馆服务台收到一把黑色折叠伞，请失主描述特征后领取。本条不含姓名或联系方式。', null],
    [2, '竞赛培训', '## 软件设计竞赛培训\n10月12日19:00在教学楼A406开展。10月10日12:00前报名；地点与截止时间不同，请勿混淆。', 1],
    [0, '志愿服务', '10月14日15:00在图书馆大厅集合，参加图书整理体验。报名截止10月13日20:00。', 3],
    [1, '实验安全提醒', '【演示】进入实验室前需完成安全培训。未完成者请联系授课教师，不要自行处理设备故障。', 301],
    [0, '课程问答', '移动应用开发课程答疑安排在每周三16:00—17:00，实训楼405；具体周次以任课教师通知为准。', 302],
    [2, '实践周说明', '## 校园应用实践周\n10月19日至23日完成团队展示。提交代码、演示视频和个人分工说明，10月23日16:00截止。', null],
    [0, '未来通知不可见测试', '这条通知在2026年11月1日才生效，九月摘要不应包含它。', null],
    [0, '过期通知不可见测试', '这条通知已于2026年9月1日结束，摘要不应包含它。', null],
];
echo 'PLAN: 6 synthetic students/accounts; ', count($notices), " demo notices; existing users unchanged.\n";
if (!$apply) exit;
$backupDir = '/www/backup/campura-one/seed-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDir, 0700, true)) throw new RuntimeException('backup failed');
foreach (['students', 'app_users', 'announcements', 'announcement_targets'] as $table) {
    $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    if (file_put_contents($backupDir . '/' . $table . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('backup failed');
    chmod($backupDir . '/' . $table . '.json', 0600);
}
$original = $pdo->query("SELECT passwordHash FROM app_users WHERE userName='linxistudent'")->fetchColumn();
$accounts = [];
$pdo->beginTransaction();
try {
    foreach ($classes as $i => $class) {
        $name = 'campura_demo_' . sprintf('%02d', $i + 1);
        $check = $pdo->prepare('SELECT userID FROM app_users WHERE userName=?');
        $check->execute([$name]);
        if ($check->fetchColumn()) continue;
        $stmt = $pdo->prepare('INSERT INTO students (schoolID,studentName,gender,compoundID,departmentID,classID) VALUES (1,?,?,1,1,?)');
        $stmt->execute(['演示学生' . ($i + 1), '未说明', $class]);
        $studentID = (int)$pdo->lastInsertId();
        $password = 'Demo!' . bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('INSERT INTO app_users (userName,passwordHash,shopIDs,studentID) VALUES (?,?,?,?)');
        $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), '[]', $studentID]);
        $accounts[] = ['userName' => $name, 'password' => $password, 'studentID' => $studentID, 'classID' => $class];
    }
    foreach ($notices as $i => [$type, $title, $body, $class]) {
        $marker = '[演示20260924-' . sprintf('%02d', $i + 1) . ']';
        $check = $pdo->prepare('SELECT announceID FROM announcements WHERE content LIKE ?');
        $check->execute([$marker . '%']);
        if ($check->fetchColumn()) continue;
        $stmt = $pdo->prepare('INSERT INTO announcements (schoolID,content,type,startTime,endTime) VALUES (1,?,?,?,?)');
        $stmt->execute([$marker . ' ' . $title . "\n" . str_replace('\\n', "\n", $body), $type,
            $i === 16 ? '2026-11-01 00:00:00' : ($i === 17 ? '2026-08-01 00:00:00' : '2026-09-24 00:00:00'),
            $i === 17 ? '2026-09-01 00:00:00' : ($i === 16 ? '2026-11-30 23:59:59' : '2026-10-23 23:59:59')]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO announcement_targets (announceID,targetJSON) VALUES (?,?)');
        $stmt->execute([$id, json_encode(['allSchool' => $class === null, 'classIDs' => $class === null ? [] : [$class]])]);
    }
    if ($original !== $pdo->query("SELECT passwordHash FROM app_users WHERE userName='linxistudent'")->fetchColumn()) throw new RuntimeException('protected account changed');
    // Write passwords before commit so a failed write cannot create inaccessible accounts.
    if (file_put_contents($backupDir . '/demo-accounts.json', json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('account receipt failed');
    chmod($backupDir . '/demo-accounts.json', 0600);
    $pdo->commit();
    echo 'COMMITTED; account receipt and backups: ', $backupDir, PHP_EOL;
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }
