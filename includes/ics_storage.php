<?php
declare(strict_types=1);

/** Transitional contract: DB content holds a relative .ics path; API content remains ICS. */
function readCourseICS(string $stored): array
{
    if (str_starts_with(ltrim($stored), 'BEGIN:VCALENDAR')) {
        return ['content' => $stored, 'icsPath' => null];
    }
    require_once __DIR__ . '/school/files.php';
    $content = school_read_file($stored, 'course_tables');
    if ($content === false || !str_starts_with(ltrim($content), 'BEGIN:VCALENDAR')
        || !str_contains($content, 'END:VCALENDAR')) {
        throw new RuntimeException('invalid course calendar');
    }
    return ['content' => $content, 'icsPath' => '/' . ltrim($stored, '/')];
}
