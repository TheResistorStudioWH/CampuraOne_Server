<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/auth/middleware.php';
require_once __DIR__ . '/../../includes/announcement_summary.php';
$payload = authMiddleware();
if (empty($payload['studentID'])) respondJson(403, 'student permission required', null, 403);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') respondJson(405, 'GET required', null, 405);
header('Cache-Control: private, no-store');

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $stmt = $pdo->prepare('SELECT studentID, schoolID, compoundID, departmentID, classID FROM students WHERE studentID = ?');
    $stmt->execute([(int)$payload['studentID']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) respondJson(403, 'student not found', null, 403);
    $stmt = $pdo->prepare('SELECT a.announceID, a.title, a.type, a.content, a.startTime, a.endTime, a.updatedAt, t.targetJSON FROM announcements a LEFT JOIN announcement_targets t ON t.targetID=(SELECT MIN(t2.targetID) FROM announcement_targets t2 WHERE t2.announceID=a.announceID) WHERE a.schoolID=? AND a.revokedAt IS NULL AND (a.startTime IS NULL OR a.startTime<=NOW()) AND (a.endTime IS NULL OR a.endTime>=NOW()) ORDER BY CASE a.type WHEN 1 THEN 0 WHEN 2 THEN 1 ELSE 2 END, a.updatedAt DESC, a.announceID DESC');
    $stmt->execute([(int)$student['schoolID']]);
    $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn($row) => summaryVisible($row['targetJSON'], $student)));
    $input = summaryInput($rows);
    if (!$input) respondJson(200, 'success', ['summary' => '暂无有效通知', 'sourceIDs' => [], 'isAI' => false]);

    // Outside the web root and Git. This file is provisioned on the deployment host only.
    $configFile = getenv('CAMPURA_AI_CONFIG') ?: '/www/server/campura-private/ai.json';
    // A host may restrict outside-root reads with open_basedir. Fail closed without
    // emitting PHP warnings into the JSON response or weakening that restriction.
    $config = @is_file($configFile) ? json_decode(@file_get_contents($configFile) ?: '{}', true, 512, JSON_THROW_ON_ERROR) : [];
    // PHP-FPM can inject credentials from a root-only file outside webroot,
    // avoiding any need to relax the site's open_basedir restriction.
    $environmentKey = getenv('CAMPURA_QWEN_API_KEY');
    if (is_string($environmentKey) && $environmentKey !== '') {
        $config = ['apiKey' => $environmentKey,
            'endpoint' => getenv('CAMPURA_QWEN_ENDPOINT') ?: 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
            'model' => getenv('CAMPURA_QWEN_MODEL') ?: 'qwen3.7-flash',
            'dailyCallLimit' => (int)(getenv('CAMPURA_QWEN_DAILY_LIMIT') ?: 30)];
    }
    if (empty($config['apiKey'])) respondJson(503, '通知总结尚未配置，请查看原文', null, 503);
    $cacheDir = $environmentKey ? '/tmp/campura-one-summary' : dirname($configFile) . '/summary-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true)) throw new RuntimeException('cache unavailable');
    $digest = hash('sha256', json_encode([$config['model'] ?? 'qwen3.7-flash', 'prompt-v4-grouped24', $input, count($rows)], JSON_UNESCAPED_UNICODE));
    $cacheFile = $cacheDir . '/' . $digest . '.json';
    // Global lock and daily paid-call cap bound both duplicate calls and account spending.
    $lock = fopen($cacheDir . '/generation.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) respondJson(429, '总结正在生成，请稍后重试', null, 429);
    $cached = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && ($cached['expiresAt'] ?? 0) > time()) respondJson(200, 'success', $cached['data']);
    $counterFile = $cacheDir . '/daily-' . date('Y-m-d') . '.txt';
    $count = is_file($counterFile) ? (int)file_get_contents($counterFile) : 0;
    if ($count >= min(100, max(1, (int)($config['dailyCallLimit'] ?? 30)))) respondJson(429, '今日总结额度已用完，请查看原文', null, 429);
    if (file_put_contents($counterFile, (string)($count + 1), LOCK_EX) === false) throw new RuntimeException('counter unavailable');
    $summary = requestQwenSummary($input, $config, count($rows));
    $result = ['summary' => $summary['summary'], 'sourceIDs' => $summary['sourceIDs'], 'isAI' => true,
        'generatedAt' => gmdate('c'), 'truncated' => count($rows) > count($input)];
    file_put_contents($cacheFile, json_encode(['expiresAt' => time() + 21600, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    respondJson(200, 'success', $result);
} catch (Throwable $e) {
    // Do not disclose provider responses, credentials, database details, or prompts.
    respondJson(503, '通知总结暂不可用，请查看原文', null, 503);
}
