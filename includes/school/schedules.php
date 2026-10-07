<?php
declare(strict_types=1);
require_once __DIR__.'/ics.php';
function school_term(PDO $pdo,int $school,int $id,bool $lock=false): array {
    $term=school_one($pdo,'SELECT * FROM academic_semesters WHERE schoolID=? AND semesterID=?'.($lock?' FOR UPDATE':''),[$school,$id]);
    if (!$term) school_fail(404,'semester not found'); return $term;
}
function school_current_term(PDO $pdo,int $school,?int $id=null): ?array {
    if ($id!==null) return school_term($pdo,$school,$id);
    return school_one($pdo,'SELECT * FROM academic_semesters WHERE schoolID=? AND startDate<=CURDATE() ORDER BY startDate DESC LIMIT 1',[$school]);
}
function school_date(array $input,string $key): string {
    $value=school_text($input,$key,10);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value) school_fail(422,"invalid $key");
    return $value;
}
function school_semesters(PDO $pdo,array $auth,string $method,array $input): array {
    $school=(int)$auth['schoolID'];
    if ($method==='GET') return isset($input['id']) ? school_term($pdo,$school,school_int($input,'id')) : school_list($pdo,'FROM academic_semesters WHERE schoolID=?',[$school],'startDate DESC');
    if (!in_array($method,['POST','PATCH'],true)) school_fail(405,'method not allowed');
    return school_transaction($pdo,function() use($pdo,$school,$auth,$method,$input) {
        // Serialize semester edits with uploads and other semester edits.
        school_one($pdo,'SELECT schoolID FROM schools WHERE schoolID=? FOR UPDATE',[$school]);
        $old=$method==='PATCH' ? school_term($pdo,$school,school_int($input,'id'),true) : null;
        if ($old) school_version($old,$input);
        $year=school_text($input,'schoolYear',9);
        if (!preg_match('/^(\d{4})-(\d{4})$/D',$year,$m) || (int)$m[2] !== (int)$m[1]+1) school_fail(422,'schoolYear must be consecutive years');
        $season=$input['season'] ?? '';
        if (!in_array($season,['spring','autumn'],true)) school_fail(422,'invalid season');
        $start=school_date($input,'startDate'); $end=school_date($input,'endDate'); $first=school_date($input,'firstMonday');
        $startDate=new DateTimeImmutable($start); $endDate=new DateTimeImmutable($end); $monday=new DateTimeImmutable($first);
        if ($start<'1900-01-01' || $end>='2100-01-01' || $end<$start || $startDate->diff($endDate)->days>370
            || $monday->format('N')!=='1' || $monday>$endDate || $monday<$startDate->modify('-6 days')) school_fail(422,'invalid semester dates or first Monday');
        if (school_one($pdo,'SELECT semesterID FROM academic_semesters WHERE schoolID=? AND semesterID<>? AND startDate<=? AND endDate>=?',[$school,$old['semesterID'] ?? 0,$end,$start])) school_fail(409,'semesters overlap');
        if ($old) {
            $id=(int)$old['semesterID'];
            school_query($pdo,'UPDATE academic_semesters SET schoolYear=?,season=?,startDate=?,endDate=?,firstMonday=?,version=version+1 WHERE semesterID=?',[$year,$season,$start,$end,$first,$id]);
        } else {
            school_query($pdo,'INSERT INTO academic_semesters (schoolID,schoolYear,season,startDate,endDate,firstMonday) VALUES (?,?,?,?,?,?)',[$school,$year,$season,$start,$end,$first]);
            $id=(int)$pdo->lastInsertId();
        }
        $new=school_term($pdo,$school,$id);
        if ($old && ($old['startDate']!==$start || $old['endDate']!==$end || $old['firstMonday']!==$first)) {
            foreach (school_query($pdo,'SELECT * FROM course_tables WHERE schoolID=? AND semesterID=? FOR UPDATE',[$school,$id]) as $row) {
                $path=school_store_file('course_tables',school_compile_table($pdo,$row,$new));
                school_query($pdo,'UPDATE course_tables SET content=?,version=version+1 WHERE tableID=?',[$path,$row['tableID']]);
            }
            if ($new['calendarVersionID']) {
                $version=school_one($pdo,'SELECT * FROM school_calendar_versions WHERE semesterID=? AND versionID=?',[$id,$new['calendarVersionID']]);
                [$from,$to]=school_term_dates($new);
                $path=school_store_file('school_calendars',school_render_events(school_expand_ics(school_read_file($version['filePath'],'school_calendars'),$from,$to),'calendar-'.$id));
                school_query($pdo,'UPDATE school_calendar_versions SET compiledPath=? WHERE versionID=?',[$path,$version['versionID']]);
            }
        }
        school_log($pdo,$auth,'semester',$id,$old?'update':'create',$old,$new); return $new;
    });
}
function school_table_row(PDO $pdo,int $school,int $id,bool $lock=false): array {
    $row=school_one($pdo,'SELECT * FROM course_tables WHERE schoolID=? AND tableID=?'.($lock?' FOR UPDATE':''),[$school,$id]);
    if (!$row) school_fail(404,'timetable not found');
    $row['overrides']=school_query($pdo,'SELECT weekNo,versionID FROM course_week_overrides WHERE tableID=? ORDER BY weekNo',[$id])->fetchAll();
    return $row;
}
function school_timetables(PDO $pdo,array $auth,string $method,array $input): array {
    $school=(int)$auth['schoolID'];
    if ($method==='GET') {
        if (isset($input['id'])) {
            $id=school_int($input,'id'); $row=school_table_row($pdo,$school,$id);
            if (($input['action'] ?? '')==='download') {
                require_once __DIR__.'/../ics_storage.php';
                school_download(readCourseICS($row['content'])['content'],'course-table-'.$id);
            }
            if (($input['action'] ?? '')==='history') return school_list($pdo,'FROM course_table_versions WHERE tableID=?',[$id],'versionID DESC');
            return $row;
        }
        $from='FROM course_tables WHERE schoolID=?'; $args=[$school];
        $term=school_current_term($pdo,$school,isset($input['semesterID'])?school_int($input,'semesterID'):null);
        if ($term) { $from.=' AND semesterID=?'; $args[]=$term['semesterID']; }
        else $from.=' AND semesterID IS NULL';
        return school_list($pdo,$from,$args,'updatedAt DESC,tableID DESC');
    }
    if ($method!=='POST') school_fail(405,'POST required');
    return school_transaction($pdo,function() use($pdo,$auth,$school,$input) {
        $action=$input['action'] ?? '';
        $term=school_term($pdo,$school,school_int($input,'semesterID'),true);
        if (isset($input['id'])) $old=school_table_row($pdo,$school,school_int($input,'id'),true);
        else {
            $scope=school_scope($pdo,$school,$input);
            $old=school_one($pdo,'SELECT * FROM course_tables WHERE schoolID=? AND semesterID=? AND compoundID=? AND departmentID=? AND classID=? FOR UPDATE',[$school,$term['semesterID'],$scope['compoundID'],$scope['departmentID'],$scope['classID']]);
        }
        if ($old) {
            if ((int)$old['semesterID']!==(int)$term['semesterID']) school_fail(422,'timetable belongs to another semester');
            school_version($old,$input); $id=(int)$old['tableID'];
        } else {
            if ($action!=='upload_base' || school_int($input,'version',0)!==0) school_fail(409,'new timetable requires upload_base and version 0');
            school_query($pdo,"INSERT INTO course_tables (schoolID,semesterID,compoundID,departmentID,classID,content) VALUES (?,?,?,?,?,'')",[$school,$term['semesterID'],$scope['compoundID'],$scope['departmentID'],$scope['classID']]);
            $id=(int)$pdo->lastInsertId();
        }
        if (in_array($action,['upload_base','upload_temporary'],true)) {
            [$bytes,$name]=school_file_bytes('file',MAX_ICS_UPLOAD_SIZE);
            school_parse_ics($bytes);
            if ($action==='upload_temporary') school_template_events($bytes);
            $path=school_store_file('course_tables',$bytes);
            school_query($pdo,'INSERT INTO course_table_versions (tableID,kind,filePath,originalName) VALUES (?,?,?,?)',[$id,$action==='upload_base'?'base':'temporary',$path,$name]);
            $versionID=(int)$pdo->lastInsertId();
            if ($action==='upload_base') school_query($pdo,'UPDATE course_tables SET baseVersionID=? WHERE tableID=?',[$versionID,$id]);
            else {
                $weeks=school_array($input,'weeks'); if (!$weeks || count($weeks)>54) school_fail(422,'select 1–54 weeks');
                foreach ($weeks as $raw) {
                    $week=school_int(['week'=>$raw],'week',1,54); school_week($term,$week);
                    school_query($pdo,'INSERT INTO course_week_overrides (tableID,weekNo,versionID) VALUES (?,?,?) ON DUPLICATE KEY UPDATE versionID=VALUES(versionID)',[$id,$week,$versionID]);
                }
            }
        } elseif ($action==='restore_base') {
            $versionID=school_int($input,'versionID');
            if (!school_one($pdo,"SELECT versionID FROM course_table_versions WHERE tableID=? AND versionID=? AND kind='base'",[$id,$versionID])) school_fail(404,'base version not found');
            school_query($pdo,'UPDATE course_tables SET baseVersionID=? WHERE tableID=?',[$versionID,$id]);
        } elseif ($action==='cancel_temporary') {
            $weeks=school_array($input,'weeks'); if (!$weeks || count($weeks)>54) school_fail(422,'select weeks');
            foreach ($weeks as $raw) {
                $week=school_int(['week'=>$raw],'week',1,54); school_week($term,$week);
                school_query($pdo,'DELETE FROM course_week_overrides WHERE tableID=? AND weekNo=?',[$id,$week]);
            }
        } else school_fail(422,'invalid action');
        $row=school_table_row($pdo,$school,$id);
        $path=school_store_file('course_tables',school_compile_table($pdo,$row,$term));
        school_query($pdo,'UPDATE course_tables SET content=?,version=version+1,updatedAt=NOW() WHERE tableID=?',[$path,$id]);
        $new=school_table_row($pdo,$school,$id);
        school_log($pdo,$auth,'timetable',$id,$action,$old,$new); return $new;
    });
}
function school_calendars(PDO $pdo,array $auth,string $method,array $input): array {
    $school=(int)$auth['schoolID'];
    if ($method==='GET') {
        $term=school_current_term($pdo,$school,isset($input['semesterID'])?school_int($input,'semesterID'):null);
        if (!$term) school_fail(404,'no started semester; specify semesterID');
        if (($input['action'] ?? '')==='history') return school_list($pdo,'FROM school_calendar_versions WHERE semesterID=?',[$term['semesterID']],'versionID DESC');
        $version=$term['calendarVersionID'] ? school_one($pdo,'SELECT * FROM school_calendar_versions WHERE semesterID=? AND versionID=?',[$term['semesterID'],$term['calendarVersionID']]) : null;
        if (($input['action'] ?? '')==='download') {
            if (!$version) school_fail(404,'calendar not uploaded');
            school_download(school_read_file($version['compiledPath'],'school_calendars'),'calendar-'.$term['semesterID']);
        }
        return ['semester'=>$term,'calendar'=>$version];
    }
    if ($method!=='POST') school_fail(405,'POST required');
    return school_transaction($pdo,function() use($pdo,$auth,$school,$input) {
        $term=school_term($pdo,$school,school_int($input,'semesterID'),true); school_version($term,$input);
        $action=$input['action'] ?? ''; [$from,$to]=school_term_dates($term);
        if ($action==='upload') {
            [$bytes,$name]=school_file_bytes('file',MAX_ICS_UPLOAD_SIZE);
            $compiled=school_render_events(school_expand_ics($bytes,$from,$to),'calendar-'.$term['semesterID']);
            $path=school_store_file('school_calendars',$bytes); $effective=school_store_file('school_calendars',$compiled);
            school_query($pdo,'INSERT INTO school_calendar_versions (semesterID,filePath,compiledPath,originalName) VALUES (?,?,?,?)',[$term['semesterID'],$path,$effective,$name]); $id=(int)$pdo->lastInsertId();
        } elseif ($action==='restore') {
            $id=school_int($input,'versionID');
            $version=school_one($pdo,'SELECT * FROM school_calendar_versions WHERE semesterID=? AND versionID=?',[$term['semesterID'],$id]);
            if (!$version) school_fail(404,'calendar version not found');
            $effective=school_store_file('school_calendars',school_render_events(school_expand_ics(school_read_file($version['filePath'],'school_calendars'),$from,$to),'calendar-'.$term['semesterID']));
            school_query($pdo,'UPDATE school_calendar_versions SET compiledPath=? WHERE versionID=?',[$effective,$id]);
        } else school_fail(422,'invalid action');
        school_query($pdo,'UPDATE academic_semesters SET calendarVersionID=?,version=version+1 WHERE semesterID=?',[$id,$term['semesterID']]);
        $new=school_term($pdo,$school,(int)$term['semesterID']);
        school_log($pdo,$auth,'calendar',(int)$term['semesterID'],$action,$term,$new);
        return ['semester'=>$new,'calendar'=>school_one($pdo,'SELECT * FROM school_calendar_versions WHERE versionID=?',[$id])];
    });
}
