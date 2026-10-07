<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('CAMPURA_DB_NAME')!=='campura_school_test' || getenv('CAMPURA_DB_HOST')!=='db') throw new RuntimeException('isolated test database required');
require_once __DIR__.'/../includes/school/management.php';
require_once __DIR__.'/../includes/school/student_compat.php';
require_once __DIR__.'/../includes/auth/jwt.php';
require_once __DIR__.'/../includes/announcement_summary.php';
$pdo=school_db(); $assertions=0; $run=bin2hex(random_bytes(5));
function check(bool $ok,string $label): void {
    global $assertions;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    ++$assertions;
}
function request(string $path,string $method='GET',array $data=[],?string $token=null,int $expected=200,?string $file=null): mixed {
    $headers=['Accept: application/json'];
    if ($token) $headers[]='Authorization: Bearer '.$token;
    if ($method==='GET') { $path.=(str_contains($path,'?')?'&':'?').http_build_query($data); $body=''; }
    elseif ($file!==null) {
        $boundary='campura'.bin2hex(random_bytes(12)); $body='';
        foreach ($data as $k=>$v) $body.="--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n".(is_array($v)?school_json($v):(string)$v)."\r\n";
        $body.="--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"fixture.ics\"\r\nContent-Type: text/calendar\r\n\r\n$file\r\n--$boundary--\r\n";
        $headers[]='Content-Type: multipart/form-data; boundary='.$boundary;
    } else { $body=school_json((object)$data); $headers[]='Content-Type: application/json'; }
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>30]]);
    $raw=file_get_contents('http://127.0.0.1:8080/'.$path,false,$context);
    preg_match('/\s(\d{3})\s/',$http_response_header[0] ?? '',$m); $status=(int)($m[1] ?? 0);
    $json=json_decode($raw ?: '',true);
    if ($status!==$expected) throw new RuntimeException("$method $path expected $expected, got $status: ".($json['message'] ?? 'non-JSON response'));
    check(is_array($json) && isset($json['code'],$json['message']) && array_key_exists('data',$json),'response envelope '.$path);
    check((int)$json['code']===$expected,'response code '.$path);
    return $json['data'];
}
function cli(array $args, string $input=''): string {
    $p=proc_open([PHP_BINARY,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    fwrite($pipes[0],$input); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p)!==0) throw new RuntimeException('CLI failed '.$args[0].': '.$out.$err);
    return $out;
}
function downloadICS(string $path,array $data,?string $token=null): string {
    $headers=$token ? ['Authorization: Bearer '.$token] : [];
    $context=stream_context_create(['http'=>['header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>15]]);
    $body=file_get_contents('http://127.0.0.1:8080/'.$path.'?'.http_build_query($data),false,$context);
    check(str_contains($http_response_header[0] ?? '',' 200 '),'download HTTP success');
    check(str_contains(strtolower(implode("\n",$http_response_header)),'content-type: text/calendar'),'calendar download MIME');
    check(str_contains($body ?: '','BEGIN:VCALENDAR'),'calendar download content');
    return $body;
}
function insertRow(string $table,array $values): int {
    global $pdo;
    // The verified legacy students table uses explicit IDs, not AUTO_INCREMENT.
    if ($table==='students') $values=['studentID'=>(int)school_query($pdo,'SELECT COALESCE(MAX(studentID),0)+1 FROM students')->fetchColumn(),...$values];
    school_query($pdo,'INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
    return $table==='students' ? (int)$values['studentID'] : (int)$pdo->lastInsertId();
}
function eventICS(string $summary='基础课',string $extra=''): string {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Campura test//CN\r\nBEGIN:VEVENT\r\nUID:base-test\r\nDTSTAMP:20261001T000000Z\r\nDTSTART;TZID=Asia/Shanghai:20261005T090000\r\nDTEND;TZID=Asia/Shanghai:20261005T103000\r\nSUMMARY:$summary\r\n$extra"."END:VEVENT\r\nEND:VCALENDAR\r\n";
}
function events(string $ics): array {
    $out=[];
    foreach (school_parse_ics($ics,true)->select('VEVENT') as $event) $out[]=['uid'=>(string)$event->UID,'name'=>(string)$event->SUMMARY,'date'=>$event->DTSTART->getDateTime()->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i')];
    return $out;
}
try {
    $password=' A'.bin2hex(random_bytes(10)).' '; // Significant edge whitespace.
    $schools=[]; $compounds=[]; $departments=[]; $students=[]; $tokens=[];
    for ($n=0;$n<2;$n++) {
        $schools[$n]=insertRow('schools',['schoolName'=>"隔离测试-$run-$n",'passwordHash'=>'unused-test-only','schoolAddress'=>'["湖北省","武汉市","江夏区"]','schoolCalendar'=>eventICS('旧校历')]);
        $compounds[$n]=insertRow('compounds',['schoolID'=>$schools[$n],'compoundName'=>'同名学院']);
        $departments[$n]=insertRow('departments',['compoundID'=>$compounds[$n],'departmentName'=>'同名系']);
        $students[$n]=insertRow('students',['schoolID'=>$schools[$n],'compoundID'=>$compounds[$n],'departmentID'=>$departments[$n],'classID'=>1,'studentName'=>'同名同学','studentNumber'=>'000001']);
        cli(['scripts/provision_school_admin.php',(string)$schools[$n],"test-$run-$n"],$password."\n");
        $login=request('api/auth/school/login.php','POST',['loginName'=>"test-$run-$n",'password'=>$password,'rememberMe'=>$n===1]);
        $tokens[$n]=$login['token'];
        $expected=$n===1?30*86400:43200;
        check(abs(strtotime($login['expiresAt'])-time()-$expected)<10,'session duration');
        check(school_one($pdo,'SELECT tokenHash FROM school_admin_sessions WHERE tokenHash=?',[hash('sha256',$tokens[$n])])!==null,'only hashed session stored');
    }
    [$s1,$s2]=$schools; [$t1,$t2]=$tokens;
    $deptOther=insertRow('departments',['compoundID'=>$compounds[0],'departmentName'=>'另一系']);
    $studentOther=insertRow('students',['schoolID'=>$s1,'compoundID'=>$compounds[0],'departmentID'=>$deptOther,'classID'=>1,'studentName'=>'同名同学']);
    $protectedHash=password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT);
    $user=insertRow('app_users',['userName'=>"test-linxistudent-$run",'studentID'=>$students[0],'passwordHash'=>$protectedHash]);
    $studentToken=generateJWT(['userID'=>$user,'studentID'=>$students[0]]);
    $otherToken=generateJWT(['userID'=>$user,'studentID'=>$studentOther]);
    request('api/school/profile.php',token:$studentToken,expected:401);
    $profile=request('api/school/profile.php',token:$t1);
    check((int)$profile['schoolID']===$s1,'session school authoritative');
    request('api/school/profile.php','PATCH',['schoolName'=>'测试学校','schoolAddress'=>['湖北省','武汉市','江夏区'],'version'=>$profile['version']],$t1);
    request('api/school/profile.php','PATCH',['schoolName'=>'不应覆盖','schoolAddress'=>['湖北省'],'version'=>$profile['version']],$t1,409);
    $profile=request('api/school/profile.php',token:$t1);
    request('api/school/profile.php','POST',['action'=>'upload_logo','version'=>$profile['version']],$t1,422,'not an image');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=');
    $profile=request('api/school/profile.php','POST',['action'=>'upload_logo','version'=>$profile['version']],$t1,file:$png);
    check(str_ends_with($profile['schoolLogo'],'.png'),'logo real MIME validation');
    $logoPath=parse_url($profile['schoolLogo'],PHP_URL_PATH);
    check(file_get_contents('http://127.0.0.1:8080'.$logoPath)===$png,'uploaded logo URL is readable');
    $list=request('api/school/students.php',data:['name'=>'同名'],token:$t1);
    check($list['total']===2,'same-name search school isolation');
    $list=request('api/school/students.php',data:['studentNumber'=>'000001'],token:$t1);
    check(count($list['items'])===1 && $list['items'][0]['studentNumber']==='000001','leading zero student number');
    request('api/school/students.php',data:['limit'=>101],token:$t1,expected:422);
    request('api/school/directory.php',token:$t1);
    $csv=tempnam(sys_get_temp_dir(),'numbers-');
    file_put_contents($csv,"studentID,studentNumber\n$studentOther,000002\n");
    cli(['scripts/import_student_numbers.php',(string)$s1,$csv]);
    cli(['scripts/import_student_numbers.php',(string)$s1,$csv]); unlink($csv);
    check(school_one($pdo,'SELECT studentNumber FROM students WHERE studentID=?',[$studentOther])['studentNumber']==='000002','number import idempotent');

    $notice=['title'=>'查寝安排','content'=>'1、19、21栋查寝。','type'=>1,'publishMode'=>'now','endTime'=>(new DateTimeImmutable('+1 day'))->format(DATE_ATOM),'target'=>['allSchool'=>false,'classScopes'=>[['compoundID'=>$compounds[0],'departmentID'=>$departments[0],'classID'=>1]]]];
    $n=request('api/school/announcements.php','POST',$notice,$t1);
    request('api/school/announcements.php',data:['id'=>$n['announceID']],token:$t2,expected:404);
    request('api/school/announcements.php','POST',['action'=>'revoke','id'=>$n['announceID'],'version'=>$n['version']],$t2,404);
    $visible=request('api/download/announcements.php',data:['schoolID'=>$s1,'studentID'=>$studentOther],token:$studentToken);
    check(count($visible)===1 && str_contains($visible[0]['content'],'查寝安排'),'student compatibility title and content');
    check(count(request('api/download/announcements.php',data:['schoolID'=>$s1],token:$otherToken))===0,'same classID different department excluded');
    request('api/download/announcements.php',data:['schoolID'=>$s2],token:$studentToken,expected:403);
    check(summaryVisible(school_json($n['target']),['studentID'=>$students[0],'compoundID'=>$compounds[0],'departmentID'=>$departments[0],'classID'=>1]),'summary same recipient rule');
    $updated=request('api/school/announcements.php','PATCH',array_merge($notice,['id'=>$n['announceID'],'version'=>$n['version'],'content'=>'更新正文']),$t1);
    request('api/school/announcements.php','PATCH',array_merge($notice,['id'=>$n['announceID'],'version'=>$n['version']]),$t1,409);
    request('api/school/announcements.php','POST',['action'=>'revoke','id'=>$n['announceID'],'version'=>$updated['version']],$t1);
    check(count(request('api/download/announcements.php',token:$studentToken))===0,'revoked excluded from student API');
    $summary=request('api/download/announcement_summary.php',token:$studentToken);
    check($summary['sourceIDs']===[],'revoked excluded from actual summary endpoint without AI request');
    check((int)school_query($pdo,'SELECT COUNT(*) FROM announcements WHERE announceID=? AND revokedAt IS NULL',[$n['announceID']])->fetchColumn()===0,'revoked excluded by summary SQL predicate');
    $history=request('api/school/announcements.php',data:['id'=>$n['announceID'],'action'=>'history'],token:$t1);
    check($history['total']===3,'notice audit history');
    $scheduled=array_merge($notice,['publishMode'=>'scheduled','startTime'=>(new DateTimeImmutable('+1 hour'))->format(DATE_ATOM)]);
    request('api/school/announcements.php','POST',$scheduled,$t1);
    check(count(request('api/download/announcements.php',token:$studentToken))===0,'scheduled invisible before release');
    $bad=$notice; $bad['target']=['allSchool'=>false,'studentIDs'=>[$students[1]]];
    request('api/school/announcements.php','POST',$bad,$t1,422);
    echo "PASS auth, profile, directory, students and announcements\n";

    $term=request('api/school/semesters.php','POST',['schoolYear'=>'2026-2027','season'=>'autumn','startDate'=>'2026-10-05','endDate'=>'2026-11-29','firstMonday'=>'2026-10-05'],$t1);
    $termID=$term['semesterID'];
    request('api/school/semesters.php',data:['id'=>$termID],token:$t2,expected:404);
    $scope=['schoolID'=>$s1,'compoundID'=>$compounds[0],'departmentID'=>$departments[0],'classID'=>1];
    $base=eventICS('基础课',"RRULE:FREQ=WEEKLY;COUNT=8\r\nEXDATE;TZID=Asia/Shanghai:20261012T090000\r\n");
    $table=request('api/school/timetables.php','POST',array_merge($scope,['action'=>'upload_base','version'=>0,'semesterID'=>$termID]),$t1,file:$base);
    $tableID=$table['tableID']; $baseVersion=$table['baseVersionID'];
    request('api/school/timetables.php',data:['id'=>$tableID],token:$t2,expected:404);
    request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'cancel_temporary','version'=>$table['version'],'weeks'=>[3]],$t2,404);
    $course=request('api/download/course_table.php',data:$scope);
    $e=events($course['content']);
    check(count($e)===7,'weekly recurrence and EXDATE expanded');
    check(count(array_unique(array_column($e,'uid')))===7,'concrete UID uniqueness');
    check(!str_contains($course['content'],'RRULE') && !str_contains($course['content'],'EXDATE'),'no unsupported rules in client');
    $uidBefore=$e[0]['uid'];
    $temp=eventICS('临时课');
    $table=request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'upload_temporary','version'=>$table['version'],'weeks'=>[3,5]],$t1,file:$temp);
    $e=events(request('api/download/course_table.php',data:$scope)['content']);
    check(count(array_filter($e,fn($r)=>$r['name']==='临时课'))===2,'multiple temporary weeks');
    check(in_array('2026-10-19 09:00',array_column($e,'date'),true) && in_array('2026-11-02 09:00',array_column($e,'date'),true),'template maps across months');
    check(array_values(array_filter($e,fn($r)=>$r['date']==='2026-10-05 09:00'))[0]['uid']===$uidBefore,'unaffected UID stable');
    $table=request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'upload_base','version'=>$table['version']],$t1,file:eventICS('新基础课',"RRULE:FREQ=WEEKLY;COUNT=8\r\n"));
    $e=events(request('api/download/course_table.php',data:$scope)['content']);
    check(count($e)===8 && count(array_filter($e,fn($r)=>$r['name']==='临时课'))===2,'new base retains overrides');
    $table=request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'cancel_temporary','version'=>$table['version'],'weeks'=>[3,5]],$t1);
    $e=events(request('api/download/course_table.php',data:$scope)['content']);
    check(count(array_filter($e,fn($r)=>$r['name']==='新基础课'))===8,'cancel reverts current base');
    $table=request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'restore_base','version'=>$table['version'],'versionID'=>$baseVersion],$t1);
    $before=request('api/download/course_table.php',data:$scope)['content'];
    check(downloadICS('api/download/course_table.php',[...$scope,'download'=>'1'])===$before,'student ICS export preserved');
    check(downloadICS('api/school/timetables.php',['id'=>$tableID,'action'=>'download'],$t1)===$before,'admin ICS download');
    request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'upload_base','version'=>$table['version']],$t1,422,'not an ICS');
    request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'upload_temporary','version'=>$table['version'],'weeks'=>[54]],$t1,422,$temp);
    check(request('api/download/course_table.php',data:$scope)['content']===$before,'failed upload preserves live ICS');
    $empty="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//CN\r\nEND:VCALENDAR\r\n";
    $table=request('api/school/timetables.php','POST',['id'=>$tableID,'semesterID'=>$termID,'action'=>'upload_temporary','version'=>$table['version'],'weeks'=>[3]],$t1,file:$empty);
    check(count(events(request('api/download/course_table.php',data:$scope)['content']))===6,'empty template clears entire selected week');
    [$from,$to]=school_term_dates($term);
    check(count(school_expand_ics(eventICS('单双周',"RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=4\r\n"),$from,$to))===4,'alternating weeks');
    try { school_expand_ics(eventICS('非法循环',"RRULE:FREQ=SECONDLY;COUNT=9999999\r\n"),$from,$to); throw new RuntimeException('high-frequency recurrence accepted'); }
    catch (SchoolApiError $error) { check($error->status===422,'high-frequency recurrence rejected before expansion'); }
    try { school_template_events(eventICS('跨周',"RDATE;TZID=Asia/Shanghai:20261013T090000\r\n")); throw new RuntimeException('multi-week template accepted'); }
    catch (SchoolApiError $error) { check($error->status===422,'multi-week template rejected'); }
    try { school_parse_ics(str_replace('20261005','20260230',eventICS())); throw new RuntimeException('invalid normalized date accepted'); }
    catch (SchoolApiError $error) { check($error->status===422,'February 30 rejected rather than normalized'); }
    try { school_parse_ics(str_replace('Asia/Shanghai','Not/A_Timezone',eventICS())); throw new RuntimeException('unknown timezone accepted'); }
    catch (SchoolApiError $error) { check($error->status===422,'unknown timezone rejected'); }
    $allDay=str_replace(['DTSTART;TZID=Asia/Shanghai:20261005T090000','DTEND;TZID=Asia/Shanghai:20261005T103000'],['DTSTART;VALUE=DATE:20261011','DTEND;VALUE=DATE:20261012'],eventICS('周日全天'));
    check(count(school_template_events($allDay)[0])===1,'Sunday all-day event ends exactly at source week boundary');
    $floating=str_replace(';TZID=Asia/Shanghai','',eventICS('浮动时间'));
    check(str_contains(school_render_events(school_expand_ics($floating,$from,$to),'floating'),'DTSTART:20261005T010000Z'),'floating time interpreted as Shanghai');
    $alarm=str_replace("END:VEVENT\r\n","BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT10M\r\nDESCRIPTION:提醒\r\nEND:VALARM\r\nEND:VEVENT\r\n",eventICS());
    check(!str_contains(school_render_events(school_expand_ics($alarm,$from,$to),'alarm'),'VALARM'),'effective ICS flat for old mobile parsers');
    $exception=str_replace("END:VCALENDAR\r\n","BEGIN:VEVENT\r\nUID:base-test\r\nRECURRENCE-ID;TZID=Asia/Shanghai:20261019T090000\r\nDTSTAMP:20261001T000000Z\r\nDTSTART;TZID=Asia/Shanghai:20261020T130000\r\nDTEND;TZID=Asia/Shanghai:20261020T143000\r\nSUMMARY:调课\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",$base);
    $expanded=school_render_events(school_expand_ics($exception,$from,$to),'test-exception');
    check(count(events($expanded))===7 && in_array('2026-10-20 13:00',array_column(events($expanded),'date'),true),'recurrence exception moved correctly');
    $calendar=request('api/school/calendar.php','POST',['action'=>'upload','semesterID'=>$termID,'version'=>$term['version']],$t1,file:$base);
    $calendarVersion=$calendar['calendar']['versionID'];
    $calendar=request('api/school/calendar.php','POST',['action'=>'upload','semesterID'=>$termID,'version'=>$calendar['semester']['version']],$t1,file:$temp);
    $calendar=request('api/school/calendar.php','POST',['action'=>'restore','semesterID'=>$termID,'version'=>$calendar['semester']['version'],'versionID'=>$calendarVersion],$t1);
    check(count(events(request('api/download/school_calendar.php',data:['schoolID'=>$s1])['content']))===7,'calendar restore');
    $schoolProfile=request('api/download/school_profile.php',data:['schoolID'=>$s1]);
    check(count(events($schoolProfile['schoolCalendar']))===7 && is_array($schoolProfile['schoolAddress']),'old school profile contract resolves effective calendar');
    check(count(events(downloadICS('api/school/calendar.php',['semesterID'=>$termID,'action'=>'download'],$t1)))===7,'admin calendar export');
    $future=request('api/school/semesters.php','POST',['schoolYear'=>'2098-2099','season'=>'spring','startDate'=>'2099-01-05','endDate'=>'2099-03-01','firstMonday'=>'2099-01-05'],$t1);
    request('api/school/calendar.php','POST',['action'=>'upload','semesterID'=>$future['semesterID'],'version'=>$future['version']],$t1,file:$empty);
    check(school_current_term($pdo,$s1)['semesterID']==$termID,'future term does not replace current');
    check(count(events(request('api/download/school_calendar.php',data:['schoolID'=>$s1])['content']))===7,'future calendar does not replace current');
    check(str_contains(request('api/download/school_calendar.php',data:['schoolID'=>$s2])['content'],'旧校历'),'legacy inline calendar');
    $legacy=insertRow('course_tables',['schoolID'=>$s2,'compoundID'=>$compounds[1],'departmentID'=>$departments[1],'classID'=>1,'content'=>$temp]);
    check(str_contains(request('api/download/course_table.php',data:['schoolID'=>$s2,'compoundID'=>$compounds[1],'departmentID'=>$departments[1],'classID'=>1])['content'],'临时课'),'legacy inline timetable');
    school_query($pdo,'UPDATE course_tables SET content=? WHERE tableID=?',[school_store_file('course_tables',$temp),$legacy]);
    check(str_contains(request('api/download/course_table.php',data:['schoolID'=>$s2,'compoundID'=>$compounds[1],'departmentID'=>$departments[1],'classID'=>1])['content'],'临时课'),'legacy path timetable');
    cli(['tests/write_failure.php',(string)$tableID,(string)$s1]);
    echo "PASS semester, recurrence, temporary weeks, calendar and legacy ICS\n";

    $shop=insertRow('main_shops',['shopName'=>'隔离演示商户','schoolID'=>$s1,'accountID'=>$user,'adIDs'=>'[]']);
    cli(['scripts/seed_school_demo.php',(string)$s1,(string)$shop,'--demo']);
    cli(['scripts/seed_school_demo.php',(string)$s1,(string)$shop,'--demo']);
    $ads=request('api/school/advertisements.php',token:$t1);
    check($ads['total']===3,'demo seed idempotent');
    $ad=$ads['items'][0]; $adID=$ad['adID'];
    request('api/school/advertisements.php',data:['id'=>$adID],token:$t2,expected:404);
    request('api/school/advertisements.php','POST',['action'=>'approve','id'=>$adID,'version'=>$ad['version']],$t2,404);
    $adPresent=fn()=>in_array((int)$adID,array_column(request('api/download/advertisements.php',token:$studentToken),'adID'),true);
    check(!$adPresent(),'pending ad invisible');
    $ad=request('api/school/advertisements.php','POST',['action'=>'approve','id'=>$adID,'version'=>$ad['version']],$t1);
    check($adPresent(),'approved ad visible');
    request('api/school/advertisements.php','POST',['action'=>'withdraw','id'=>$adID,'version'=>$ad['version']],$t1,422);
    $ad=request('api/school/advertisements.php','POST',['action'=>'withdraw','id'=>$adID,'version'=>$ad['version'],'reason'=>'测试主动下架'],$t1);
    check(!$adPresent(),'withdrawn ad invisible');
    $ad=request('api/school/advertisements.php','POST',['action'=>'resubmit','id'=>$adID,'version'=>$ad['version']],$t1);
    $ad=request('api/school/advertisements.php','POST',['action'=>'reject','id'=>$adID,'version'=>$ad['version'],'reason'=>'图片需修改'],$t1);
    $ad=request('api/school/advertisements.php','POST',['action'=>'edit_reason','id'=>$adID,'version'=>$ad['version'],'reason'=>'补充活动日期'],$t1);
    $ad=request('api/school/advertisements.php','POST',['action'=>'undo_rejection','id'=>$adID,'version'=>$ad['version']],$t1);
    check($ad['status']==='pending','undo rejection');
    $dashboard=request('api/school/dashboard.php',token:$t1);
    foreach (['currentOnlineStudents','impressions','clicks','totalRevenue'] as $metric) check($dashboard['adStats'][$metric]===null,'unavailable metric '.$metric);
    check((float)$dashboard['adStats']['quoteTotal']===360.0,'quote not revenue');
    $sale=insertRow('promotion_events',['saleRule'=>'仅隔离测试旧广告']);
    $ownedLegacy=insertRow('advertisements',['saleID'=>$sale,'type'=>'S','img'=>'/tests/fixtures/demo-ad.svg']);
    $unassignedLegacy=insertRow('advertisements',['saleID'=>$sale,'type'=>'L','img'=>'/tests/fixtures/demo-ad.svg']);
    $shopAds=json_decode(school_one($pdo,'SELECT adIDs FROM main_shops WHERE shopID=?',[$shop])['adIDs'],true);
    $shopAds[]=$ownedLegacy;
    school_query($pdo,'UPDATE main_shops SET adIDs=? WHERE shopID=?',[school_json($shopAds),$shop]);
    $report=cli(['scripts/migrate_school_console.php']); cli(['scripts/migrate_school_console.php']);
    check(school_ad_row($pdo,$s1,$ownedLegacy)['status']==='approved','legacy owned ad retains approved behavior');
    check(school_one($pdo,'SELECT adID FROM advertisement_reviews WHERE adID=?',[$unassignedLegacy])===null,'unknown owner not guessed');
    check(str_contains($report,'UNASSIGNED advertisement '.$unassignedLegacy),'unassigned legacy ad reported');
    $publicAds=array_column(request('api/download/advertisements.php',token:$studentToken),'adID');
    check(in_array($ownedLegacy,$publicAds,true) && in_array($unassignedLegacy,$publicAds,true),'legacy public ads preserved');
    check(school_ad_row($pdo,$s1,(int)$adID)['status']==='pending','migration never autoapproves managed pending ad');
    check(school_one($pdo,'SELECT passwordHash FROM app_users WHERE userID=?',[$user])['passwordHash']===$protectedHash,'student credentials untouched');
    for ($i=0;$i<10;$i++) request('api/auth/school/login.php','POST',['loginName'=>"missing-$run",'password'=>'test-only-invalid'],expected:401);
    request('api/auth/school/login.php','POST',['loginName'=>"missing-$run",'password'=>'test-only-invalid'],expected:429);
    request('api/auth/school/logout.php','POST',[],$t2);
    request('api/auth/school/session.php',token:$t2,expected:401);
    $extra=request('api/auth/school/login.php','POST',['loginName'=>"test-$run-0",'password'=>$password]);
    request('api/auth/school/password.php','POST',['currentPassword'=>$password,'newPassword'=>'B'.bin2hex(random_bytes(12))],$t1);
    request('api/auth/school/session.php',token:$t1,expected:401);
    request('api/auth/school/session.php',token:$extra['token'],expected:401);
    echo "PASS advertisements, dashboard, migration and session revocation\n";
    echo "PASS $assertions assertions. No production data or paid AI calls used.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n"); exit(1); }
