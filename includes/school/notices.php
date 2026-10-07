<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once __DIR__.'/../announcement_access.php';
function school_notice_target(PDO $pdo,int $school,array $input): array {
    $t=$input['target'] ?? null;
    if (!is_array($t) || !is_bool($t['allSchool'] ?? null)) school_fail(422,'target.allSchool boolean required');
    $result=['allSchool'=>$t['allSchool'],'compoundIDs'=>[],'departmentIDs'=>[],'classScopes'=>[],'studentIDs'=>[]];
    foreach (['compoundIDs','departmentIDs','studentIDs'] as $list) {
        foreach (school_array($t,$list) as $raw) {
            $id=school_int(['id'=>$raw],'id');
            $sql=match($list) {
                'compoundIDs'=>'SELECT compoundID FROM compounds WHERE schoolID=? AND compoundID=?',
                'departmentIDs'=>'SELECT d.departmentID FROM departments d JOIN compounds c ON c.compoundID=d.compoundID WHERE c.schoolID=? AND d.departmentID=?',
                'studentIDs'=>'SELECT studentID FROM students WHERE schoolID=? AND studentID=?',
            };
            if (!school_one($pdo,$sql,[$school,$id])) school_fail(422,'recipient does not belong to school');
            $result[$list][]=$id;
        }
        $result[$list]=array_values(array_unique($result[$list]));
    }
    foreach (school_array($t,'classScopes') as $scope) {
        if (!is_array($scope)) school_fail(422,'invalid class scope');
        $verified=school_scope($pdo,$school,$scope); unset($verified['schoolID']);
        $result['classScopes'][]=$verified;
    }
    $count=count($result['compoundIDs'])+count($result['departmentIDs'])+count($result['classScopes'])+count($result['studentIDs']);
    if ($count>500) school_fail(422,'too many recipient selectors');
    if ($result['allSchool'] && $count) school_fail(422,'allSchool cannot be combined with other selectors');
    if (!$result['allSchool'] && !$count) school_fail(422,'at least one recipient required');
    return $result;
}
function school_datetime(array $input,string $key): string {
    $raw=school_text($input,$key,40);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D',$raw)) school_fail(422,"$key requires ISO 8601 with timezone");
    try { $date=new DateTimeImmutable($raw); } catch(Throwable) { school_fail(422,"invalid $key"); }
    $errors=DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) school_fail(422,"invalid $key");
    return $date->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
}
function school_notice_row(PDO $pdo,int $school,int $id,bool $lock=false): array {
    $row=school_one($pdo,'SELECT * FROM announcements WHERE schoolID=? AND announceID=?'.($lock?' FOR UPDATE':''),[$school,$id]);
    if (!$row) school_fail(404,'announcement not found');
    $raw=school_query($pdo,'SELECT targetJSON FROM announcement_targets WHERE announceID=? ORDER BY targetID LIMIT 1',[$id])->fetchColumn();
    $row['target']=announcementTarget($raw === false ? null : $raw);
    $row['status']=$row['revokedAt'] ? 'revoked' : (($row['startTime'] && $row['startTime']>date('Y-m-d H:i:s')) ? 'scheduled' : (($row['endTime'] && $row['endTime']<date('Y-m-d H:i:s')) ? 'expired':'active'));
    return $row;
}
function school_notices(PDO $pdo,array $auth,string $method,array $input): array {
    $school=(int)$auth['schoolID'];
    if ($method==='GET') {
        if (isset($input['id'])) {
            $id=school_int($input,'id'); $row=school_notice_row($pdo,$school,$id);
            return ($input['action'] ?? '')==='history' ? school_history($pdo,$auth,'announcement',$id) : $row;
        }
        $from='FROM announcements WHERE schoolID=?'; $args=[$school];
        if (isset($input['type'])) { $from.=' AND type=?'; $args[]=school_int($input,'type',0,2); }
        $status=$input['status'] ?? 'all';
        $from.=match($status) {
            'all'=>'', 'revoked'=>' AND revokedAt IS NOT NULL',
            'scheduled'=>' AND revokedAt IS NULL AND startTime>NOW()',
            'expired'=>' AND revokedAt IS NULL AND endTime<NOW()',
            'active'=>' AND revokedAt IS NULL AND (startTime IS NULL OR startTime<=NOW()) AND (endTime IS NULL OR endTime>=NOW())',
            default=>throw new SchoolApiError(422,'invalid status'),
        };
        $list=school_list($pdo,$from,$args,'announceID DESC');
        $list['items']=array_map(fn($r)=>school_notice_row($pdo,$school,(int)$r['announceID']),$list['items']); return $list;
    }
    if (!in_array($method,['POST','PATCH'],true)) school_fail(405,'method not allowed');
    return school_transaction($pdo,function() use($pdo,$auth,$school,$method,$input) {
        $action=$input['action'] ?? ($method==='POST'?'create':'update');
        $old=null; $id=0;
        if ($action!=='create') {
            $id=school_int($input,'id'); $old=school_notice_row($pdo,$school,$id,true); school_version($old,$input);
        }
        if ($action==='revoke' && $method==='POST') {
            if ($old['revokedAt']) school_fail(409,'already revoked');
            school_query($pdo,'UPDATE announcements SET revokedAt=NOW(),version=version+1 WHERE announceID=?',[$id]);
        } elseif (($action==='create' && $method==='POST') || ($action==='update' && $method==='PATCH')) {
            if ($old && $old['revokedAt']) school_fail(409,'revoked announcement cannot be edited');
            $title=school_text($input,'title',200); $content=school_text($input,'content',100000);
            $type=school_int($input,'type',0,2); $mode=$input['publishMode'] ?? 'now';
            if (!in_array($mode,['now','scheduled'],true)) school_fail(422,'invalid publishMode');
            $now=date('Y-m-d H:i:s');
            $start=$mode==='now' ? ($old && $old['startTime']<=$now ? $old['startTime'] : $now) : school_datetime($input,'startTime');
            $end=school_datetime($input,'endTime');
            if ($end <= $start) school_fail(422,'endTime must follow startTime');
            $target=school_notice_target($pdo,$school,$input);
            if ($old) school_query($pdo,'UPDATE announcements SET title=?,content=?,type=?,startTime=?,endTime=?,version=version+1 WHERE announceID=?',[$title,$content,$type,$start,$end,$id]);
            else {
                school_query($pdo,'INSERT INTO announcements (schoolID,title,content,type,startTime,endTime) VALUES (?,?,?,?,?,?)',[$school,$title,$content,$type,$start,$end]); $id=(int)$pdo->lastInsertId();
            }
            school_query($pdo,'DELETE FROM announcement_targets WHERE announceID=?',[$id]);
            school_query($pdo,'INSERT INTO announcement_targets (announceID,targetJSON) VALUES (?,?)',[$id,school_json($target)]);
        } else school_fail(422,'invalid action/method');
        $new=school_notice_row($pdo,$school,$id); school_log($pdo,$auth,'announcement',$id,$action,$old,$new); return $new;
    });
}
