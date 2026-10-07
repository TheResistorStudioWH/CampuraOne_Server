<?php
declare(strict_types=1);
require_once __DIR__.'/schedules.php';
require_once __DIR__.'/../announcement_access.php';
require_once __DIR__.'/../ics_storage.php';
function school_api_student(PDO $pdo): array {
    require_once __DIR__.'/../auth/middleware.php';
    $claims=authMiddleware();
    if (empty($claims['studentID'])) school_fail(403,'student permission required');
    $student=school_one($pdo,'SELECT studentID,schoolID,compoundID,departmentID,classID FROM students WHERE studentID=?',[(int)$claims['studentID']]);
    if (!$student) school_fail(403,'student not found'); return $student;
}
function school_student_announcements(PDO $pdo,array $unused,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    $student=school_api_student($pdo);
    if (isset($input['schoolID']) && school_int($input,'schoolID')!==(int)$student['schoolID']) school_fail(403,'school mismatch');
    $args=[$student['schoolID']];
    $sql='SELECT a.*,t.targetJSON FROM announcements a LEFT JOIN announcement_targets t ON t.targetID=(SELECT MIN(t2.targetID) FROM announcement_targets t2 WHERE t2.announceID=a.announceID) WHERE a.schoolID=? AND a.revokedAt IS NULL AND (a.startTime IS NULL OR a.startTime<=NOW()) AND (a.endTime IS NULL OR a.endTime>=NOW())';
    if (isset($input['type'])) { $sql.=' AND a.type=?'; $args[]=school_int($input,'type',0,2); }
    $sql.=' ORDER BY CASE a.type WHEN 2 THEN 0 WHEN 1 THEN 1 ELSE 2 END,a.createdAt DESC,a.announceID DESC';
    $out=[];
    foreach (school_query($pdo,$sql,$args) as $row) {
        $target=announcementTarget($row['targetJSON']);
        if (!announcementVisibleTo($target,$student)) continue;
        $row['content']=announcementContent($row); $row['target']=announcementClientTarget($target,$student);
        unset($row['targetJSON'],$row['revokedAt']);
        foreach (['announceID','schoolID','type','version'] as $key) $row[$key]=(int)$row[$key];
        $out[]=$row;
    }
    return $out;
}
function school_student_ads(PDO $pdo,array $unused,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    $sql="SELECT a.* FROM advertisements a LEFT JOIN advertisement_reviews r ON r.adID=a.adID WHERE (r.adID IS NULL OR r.status='approved') AND a.startTime<=NOW() AND (a.endTime IS NULL OR a.endTime>=NOW())";
    $args=[];
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $student=school_api_student($pdo);
        $sql.=' AND (r.schoolID=? OR r.adID IS NULL)'; $args[]=$student['schoolID'];
    }
    if (isset($input['type'])) {
        $type=strtoupper(school_text($input,'type',1));
        if (!in_array($type,['S','L'],true)) school_fail(422,'type must be S or L');
        $sql.=' AND a.type=?'; $args[]=$type;
    }
    if (isset($input['saleID'])) { $sql.=' AND a.saleID=?'; $args[]=school_int($input,'saleID'); }
    $rows=school_query($pdo,$sql.' ORDER BY a.startTime DESC,a.adID DESC',$args)->fetchAll();
    foreach ($rows as &$row) { $row['adID']=(int)$row['adID']; $row['saleID']=(int)$row['saleID']; } unset($row);
    return $rows;
}
function school_student_course(PDO $pdo,array $unused,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    $school=school_int($input,'schoolID'); $scope=school_scope($pdo,$school,$input,false);
    $term=school_current_term($pdo,$school,isset($input['semesterID'])?school_int($input,'semesterID'):null);
    $where='schoolID=? AND compoundID=? AND departmentID=? AND classID=?';
    $row=$term ? school_one($pdo,"SELECT * FROM course_tables WHERE $where AND semesterID=? ORDER BY updatedAt DESC,tableID DESC LIMIT 1",[...array_values($scope),$term['semesterID']]) : null;
    if (!$row && !isset($input['semesterID'])) $row=school_one($pdo,"SELECT * FROM course_tables WHERE $where AND semesterID IS NULL ORDER BY updatedAt DESC,tableID DESC LIMIT 1",array_values($scope));
    if (!$row) school_fail(404,'course table not found');
    $file=readCourseICS($row['content']);
    if (($input['download'] ?? '')==='1') school_download($file['content'],'course-table-'.$row['tableID']);
    $row['content']=$file['content']; $row['icsPath']=$file['icsPath'];
    foreach (['tableID','schoolID','compoundID','departmentID','classID','version'] as $key) $row[$key]=(int)$row[$key];
    return $row;
}
function school_effective_calendar(PDO $pdo,int $school,?int $semester=null): array {
    $term=school_current_term($pdo,$school,$semester);
    if ($term && $term['calendarVersionID']) {
        $version=school_one($pdo,'SELECT * FROM school_calendar_versions WHERE semesterID=? AND versionID=?',[$term['semesterID'],$term['calendarVersionID']]);
        return ['schoolID'=>$school,'content'=>school_read_file($version['compiledPath'],'school_calendars'),'icsPath'=>'/'.$version['compiledPath'],'updatedAt'=>$term['updatedAt'],'semesterID'=>(int)$term['semesterID']];
    }
    if ($semester!==null) school_fail(404,'calendar not uploaded');
    $row=school_one($pdo,'SELECT schoolCalendar,updatedAt FROM schools WHERE schoolID=?',[$school]);
    if (!$row) school_fail(404,'school not found');
    $stored=(string)$row['schoolCalendar']; $path=null;
    if ($stored!=='' && !str_starts_with(ltrim($stored),'BEGIN:VCALENDAR')) { $path='/'.ltrim($stored,'/'); $stored=school_read_file($stored,'school_calendars'); }
    return ['schoolID'=>$school,'content'=>$stored,'icsPath'=>$path,'updatedAt'=>$row['updatedAt']];
}
function school_student_calendar(PDO $pdo,array $unused,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    $data=school_effective_calendar($pdo,school_int($input,'schoolID'),isset($input['semesterID'])?school_int($input,'semesterID'):null);
    if (($input['download'] ?? '')==='1') school_download($data['content'],'school-calendar-'.$data['schoolID']);
    return $data;
}
