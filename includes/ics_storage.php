<?php
declare(strict_types=1);

/** Transitional contract: DB content holds a relative .ics path; API content remains ICS. */
function readCourseICS(string $stored): array
{
    if (str_starts_with(ltrim($stored), 'BEGIN:VCALENDAR')) {
        return ['content' => $stored, 'icsPath' => null];
    }
    if (!preg_match('~^uploads/course_tables/[a-zA-Z0-9_-]+\.ics$~D', $stored)) {
        throw new RuntimeException('invalid course file path');
    }
    $root = realpath(__DIR__ . '/../uploads/course_tables');
    $file = realpath(__DIR__ . '/../' . $stored);
    if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
        || !is_file($file) || filesize($file) > 5 * 1024 * 1024) {
        throw new RuntimeException('course file unavailable');
    }
    $content = file_get_contents($file);
    if ($content === false || !str_starts_with(ltrim($content), 'BEGIN:VCALENDAR')
        || !str_contains($content, 'END:VCALENDAR')) {
        throw new RuntimeException('invalid course calendar');
    }
    return ['content' => $content, 'icsPath' => '/' . $stored];
}
