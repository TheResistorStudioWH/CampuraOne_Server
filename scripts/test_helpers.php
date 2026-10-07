<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ics_storage.php';
require_once __DIR__ . '/../includes/announcement_summary.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$ics = file_get_contents(__DIR__ . '/demo-course.ics');
check(readCourseICS($ics)['content'] === $ics, 'legacy compatibility');
foreach (['../../config/database.php', 'https://example.com/a.ics', '/etc/passwd', 'uploads/course_tables/../../config/database.php'] as $path) {
    try { readCourseICS($path); throw new LogicException('unsafe path accepted'); }
    catch (RuntimeException $e) { check(!($e instanceof LogicException), 'path rejection'); }
}
$student = ['studentID' => 901, 'departmentID' => 1, 'classID' => 301];
check(summaryVisible(null, $student), 'legacy target');
check(summaryVisible('{"allSchool":true}', $student), 'school target');
check(summaryVisible('{"classIDs":[301]}', $student), 'class target');
check(!summaryVisible('{"classIDs":[302]}', $student), 'other class hidden');
check(!summaryVisible('{"studentIDs":[902]}', $student), 'other student hidden');
check(!summaryVisible('{broken', $student), 'invalid target hidden');
$rows = [];
for ($i = 0; $i < 40; $i++) $rows[] = ['announceID' => $i, 'type' => 0, 'content' => str_repeat('字', 2000), 'startTime' => null, 'endTime' => null];
$input = summaryInput($rows);
check(count($input) === 10, 'bounded prompt');
check(array_sum(array_map(fn($row) => mb_strlen($row['content']), $input)) === 18000, 'prompt character cap');
$sources = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]];
$grouped = formatQwenSummary('{"summary":"1、19、21栋查寝。","sourceIDs":[1,2,3]}', $sources, 4);
check($grouped['summary'] === '1、19、21栋查寝。还有另外1条通知。', 'grouped notices count as three sources');
check(formatQwenSummary('{"summary":"今晚停水","sourceIDs":[1,1]}', $sources, 40)['summary'] === '今晚停水。还有另外39条通知。', 'deduplicate sources and count beyond input cap');
check(formatQwenSummary('{"summary":"1、19、21栋查寝。","sourceIDs":[1,2,3]}', $sources, 3)['summary'] === '1、19、21栋查寝。', 'no omitted suffix when all covered');
foreach (['{"summary":"查寝","sourceIDs":[999]}', '{"summary":"查寝","sourceIDs":[]}', '{"summary":"查寝","sourceIDs":["1"]}', json_encode(['summary' => str_repeat('字', 25), 'sourceIDs' => [1]])] as $invalid) {
    try { formatQwenSummary($invalid, $sources, 4); throw new LogicException('invalid summary accepted'); }
    catch (RuntimeException $e) { check(!($e instanceof LogicException), 'reject invalid summary'); }
}
echo "PASS: legacy ICS, traversal rejection, audience isolation, prompt limits, compact grouped summaries\n";
