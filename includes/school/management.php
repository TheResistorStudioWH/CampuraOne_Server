<?php
declare(strict_types=1);
require_once __DIR__.'/schedules.php';
require_once __DIR__.'/notices.php';
function school_profile_row(PDO $pdo,int $school): array {
    $row=school_one($pdo,'SELECT schoolID,schoolName,schoolAddress,schoolLogo,version,updatedAt FROM schools WHERE schoolID=?',[$school]);
    if (!$row) school_fail(404,'school not found');
    $row['schoolAddress']=json_decode($row['schoolAddress'] ?: '[]',true); return $row;
}
function school_profile(PDO $pdo,array $auth,string $method,array $input): array {
    $school=(int)$auth['schoolID'];
    if ($method==='GET') return school_profile_row($pdo,$school);
    if (!in_array($method,['POST','PATCH'],true)) school_fail(405,'method not allowed');
    return school_transaction($pdo,function() use($pdo,$auth,$school,$method,$input) {
        school_one($pdo,'SELECT schoolID FROM schools WHERE schoolID=? FOR UPDATE',[$school]);
        $old=school_profile_row($pdo,$school); school_version($old,$input);
        if ($method==='PATCH') {
            $name=school_text($input,'schoolName',100);
            $address=school_array($input,'schoolAddress');
            if (!$address || count($address)>10) school_fail(422,'structured schoolAddress required');
            foreach ($address as $part) if (!is_string($part) || mb_strlen($part)>200) school_fail(422,'invalid address component');
            school_query($pdo,'UPDATE schools SET schoolName=?,schoolAddress=?,version=version+1 WHERE schoolID=?',[$name,school_json($address),$school]);
            $action='update';
        } else {
            if (($input['action'] ?? '')!=='upload_logo') school_fail(422,'invalid action');
            [$bytes,,$tmp]=school_file_bytes('file',MAX_IMAGE_UPLOAD_SIZE);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $size=getimagesize($tmp);
            if (!isset($extensions[$mime]) || !$size || $size[0]>8192 || $size[1]>8192) school_fail(422,'invalid logo');
            $path=school_store_file('school_logos',$bytes,$extensions[$mime]);
            school_query($pdo,'UPDATE schools SET schoolLogo=?,version=version+1 WHERE schoolID=?',[APP_BASE_URL.'/'.$path,$school]); $action='logo';
        }
        $new=school_profile_row($pdo,$school); school_log($pdo,$auth,'school',$school,$action,$old,$new); return $new;
    });
}
function school_directory(PDO $pdo,array $auth,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    $school=(int)$auth['schoolID'];
    return [
        'compounds'=>school_query($pdo,'SELECT compoundID,compoundName FROM compounds WHERE schoolID=? ORDER BY compoundID',[$school])->fetchAll(),
        'departments'=>school_query($pdo,'SELECT d.departmentID,d.departmentName,d.compoundID FROM departments d JOIN compounds c ON c.compoundID=d.compoundID WHERE c.schoolID=? ORDER BY d.departmentID',[$school])->fetchAll(),
        'classes'=>school_query($pdo,'SELECT DISTINCT compoundID,departmentID,classID FROM students WHERE schoolID=? UNION SELECT DISTINCT compoundID,departmentID,classID FROM course_tables WHERE schoolID=? ORDER BY compoundID,departmentID,classID',[$school,$school])->fetchAll(),
    ];
}
function school_students(PDO $pdo,array $auth,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    [$page,$limit,$offset]=school_page();
    $where='WHERE s.schoolID=?'; $args=[$auth['schoolID']];
    if (isset($input['studentNumber'])) { $where.=' AND s.studentNumber=?'; $args[]=school_text($input,'studentNumber',64); }
    if (isset($input['name'])) { $where.=" AND s.studentName LIKE ? ESCAPE '!'"; $args[]='%'.strtr(school_text($input,'name',100),['!'=>'!!','%'=>'!%','_'=>'!_']).'%'; }
    foreach (['compoundID','departmentID','classID'] as $key) if (isset($input[$key])) { $where.=" AND s.$key=?"; $args[]=school_int($input,$key); }
    $total=(int)school_query($pdo,"SELECT COUNT(*) FROM students s $where",$args)->fetchColumn();
    $items=school_query($pdo,"SELECT s.studentID,s.studentNumber,s.studentName,s.compoundID,s.departmentID,s.classID,c.compoundName,d.departmentName FROM students s JOIN compounds c ON c.compoundID=s.compoundID JOIN departments d ON d.departmentID=s.departmentID $where ORDER BY s.studentID LIMIT $limit OFFSET $offset",$args)->fetchAll();
    return compact('items','page','limit','total');
}
function school_ad_row(PDO $pdo,int $school,int $id,bool $lock=false): array {
    $row=school_one($pdo,'SELECT r.*,a.saleID,a.type,a.img,a.startTime,a.endTime,s.shopName,q.orderNumber,q.packageName,q.amount,q.currency FROM advertisement_reviews r JOIN advertisements a ON a.adID=r.adID JOIN main_shops s ON s.shopID=r.shopID LEFT JOIN advertisement_quotes q ON q.adID=r.adID WHERE r.schoolID=? AND r.adID=?'.($lock?' FOR UPDATE':''),[$school,$id]);
    if (!$row) school_fail(404,'advertisement not found');
    $row['paymentStatus']='not_integrated'; return $row;
}
function school_advertisements(PDO $pdo,array $auth,string $method,array $input): array {
    $school=(int)$auth['schoolID'];
    if ($method==='GET') {
        if (isset($input['id'])) {
            $id=school_int($input,'id'); $row=school_ad_row($pdo,$school,$id);
            return ($input['action'] ?? '')==='history' ? school_history($pdo,$auth,'advertisement',$id) : $row;
        }
        $from='FROM advertisement_reviews WHERE schoolID=?'; $args=[$school];
        if (isset($input['status']) && $input['status']!=='all') {
            if (!in_array($input['status'],['pending','approved','rejected','withdrawn'],true)) school_fail(422,'invalid status');
            $from.=' AND status=?'; $args[]=$input['status'];
        }
        $list=school_list($pdo,$from,$args,'submittedAt DESC,adID DESC');
        $list['items']=array_map(fn($r)=>school_ad_row($pdo,$school,(int)$r['adID']),$list['items']); return $list;
    }
    if ($method!=='POST') school_fail(405,'POST required');
    return school_transaction($pdo,function() use($pdo,$school,$auth,$input) {
        $id=school_int($input,'id'); $old=school_ad_row($pdo,$school,$id,true); school_version($old,$input);
        $action=school_text($input,'action',30);
        $allowed=['approve'=>['pending'],'reject'=>['pending'],'edit_reason'=>['rejected'],'undo_rejection'=>['rejected'],'withdraw'=>['approved'],'resubmit'=>['withdrawn']];
        if (!isset($allowed[$action]) || !in_array($old['status'],$allowed[$action],true)) school_fail(409,'action not allowed in current state');
        $reason=school_text($input,'reason',2000,$action==='withdraw');
        $status=match($action) {'approve'=>'approved','reject','edit_reason'=>'rejected','withdraw'=>'withdrawn',default=>'pending'};
        if (in_array($status,['approved','pending'],true)) $reason='';
        school_query($pdo,'UPDATE advertisement_reviews SET status=?,reason=?,version=version+1,reviewedAt=?,reviewerID=? WHERE adID=?',[$status,$reason,$status==='pending'?null:date('Y-m-d H:i:s'),$status==='pending'?null:$auth['adminID'],$id]);
        $new=school_ad_row($pdo,$school,$id);
        school_log($pdo,$auth,'advertisement',$id,$action,$old,$new); return $new;
    });
}
function school_event_preview(string $bytes,int $limit=5): array {
    $calendar=school_parse_ics($bytes,true); $items=[];
    foreach ($calendar->select('VEVENT') as $event) {
        $date=school_event_start($event);
        if (school_event_end($event)<new DateTimeImmutable('today')) continue;
        $items[]=['uid'=>(string)$event->UID,'title'=>(string)$event->SUMMARY,'startTime'=>$date->format(DATE_ATOM),'endTime'=>school_event_end($event)->format(DATE_ATOM),'location'=>(string)($event->LOCATION ?? '')];
    }
    usort($items,fn($a,$b)=>strcmp($a['startTime'],$b['startTime'])); return array_slice($items,0,$limit);
}
function school_dashboard(PDO $pdo,array $auth,string $method,array $input): array {
    if ($method!=='GET') school_fail(405,'GET required');
    $school=(int)$auth['schoolID'];
    $stats=school_one($pdo,"SELECT COUNT(*) total,COALESCE(SUM(status='pending'),0) pendingCount,COALESCE(SUM(status='approved'),0) approvedCount,COALESCE(SUM(status='rejected'),0) rejectedCount,COALESCE(AVG(CASE WHEN status='pending' THEN TIMESTAMPDIFF(SECOND,submittedAt,NOW())/3600 END),0) averageWaitingHours FROM advertisement_reviews WHERE schoolID=?",[$school]);
    $den=(int)$stats['approvedCount']+(int)$stats['rejectedCount'];
    $stats['approvalRate']=$den ? round(100*(int)$stats['approvedCount']/$den,1) : null;
    $stats['activeAds']=school_query($pdo,"SELECT a.type,COUNT(*) count FROM advertisements a JOIN advertisement_reviews r ON r.adID=a.adID WHERE r.schoolID=? AND r.status='approved' AND a.startTime<=NOW() AND (a.endTime IS NULL OR a.endTime>=NOW()) GROUP BY a.type",[$school])->fetchAll();
    $stats['quoteTotal']=school_query($pdo,'SELECT COALESCE(SUM(q.amount),0) FROM advertisement_quotes q JOIN advertisement_reviews r ON r.adID=q.adID WHERE r.schoolID=?',[$school])->fetchColumn();
    $stats['rejectionReasons']=school_query($pdo,"SELECT reason,COUNT(*) count FROM advertisement_reviews WHERE schoolID=? AND status='rejected' GROUP BY reason ORDER BY count DESC",[$school])->fetchAll();
    $stats['reviewTrend']=school_query($pdo,"SELECT DATE(createdAt) date,action,COUNT(*) count FROM school_operation_logs WHERE schoolID=? AND resource='advertisement' AND action IN ('approve','reject') AND createdAt>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(createdAt),action ORDER BY date",[$school])->fetchAll();
    $stats['processedThisWeek']=array_sum(array_column($stats['reviewTrend'],'count'));
    foreach (['currentOnlineStudents','impressions','clicks','totalRevenue'] as $key) $stats[$key]=null;
    $stats['unavailableMetrics']=['currentOnlineStudents'=>'尚未接入','impressions'=>'尚未接入','clicks'=>'尚未接入','totalRevenue'=>'尚未接入'];
    $notices=school_query($pdo,'SELECT announceID,title,content,type,startTime,endTime,revokedAt,version FROM announcements WHERE schoolID=? ORDER BY updatedAt DESC,announceID DESC LIMIT 5',[$school])->fetchAll();
    $term=school_current_term($pdo,$school); $calendar=[]; $timetable=null;
    if ($term) {
        if ($term['calendarVersionID']) {
            $v=school_one($pdo,'SELECT compiledPath FROM school_calendar_versions WHERE semesterID=? AND versionID=?',[$term['semesterID'],$term['calendarVersionID']]);
            $calendar=school_event_preview(school_read_file($v['compiledPath'],'school_calendars'));
        }
        $row=school_one($pdo,'SELECT * FROM course_tables WHERE schoolID=? AND semesterID=? ORDER BY CRC32(CONCAT(tableID,CURDATE())) LIMIT 1',[$school,$term['semesterID']]);
        if ($row) $timetable=['tableID'=>(int)$row['tableID'],'compoundID'=>(int)$row['compoundID'],'departmentID'=>(int)$row['departmentID'],'classID'=>(int)$row['classID'],'events'=>school_event_preview(school_read_file($row['content'],'course_tables'))];
    }
    return ['date'=>date('Y-m-d'),'school'=>school_profile_row($pdo,$school),'semester'=>$term,'notices'=>$notices,'calendarEvents'=>$calendar,'timetable'=>$timetable,'adStats'=>$stats];
}
