<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/school/core.php';
try {
    $school=school_int(['schoolID'=>$argv[1] ?? null],'schoolID');
    $file=fopen($argv[2] ?? '', 'r');
    if (!$file || fgetcsv($file)!==['studentID','studentNumber']) school_fail(422,'CSV header must be studentID,studentNumber');
    $rows=[]; $numbers=[];
    while (($line=fgetcsv($file))!==false) {
        if ($line===[null]) continue;
        if (count($line)!==2 || count($rows)>=10000) school_fail(422,'invalid CSV or more than 10000 students');
        $id=school_int(['id'=>$line[0]],'id');
        $number=school_text(['number'=>$line[1]],'number',64);
        if (isset($rows[$id]) || isset($numbers[$number])) school_fail(422,'duplicate CSV identifier');
        $rows[$id]=$number; $numbers[$number]=true;
    }
    fclose($file); $pdo=school_db();
    school_transaction($pdo,function() use($pdo,$school,$rows) {
        foreach ($rows as $id=>$number) {
            $old=school_one($pdo,'SELECT studentID,studentNumber FROM students WHERE schoolID=? AND studentID=? FOR UPDATE',[$school,$id]);
            if (!$old) school_fail(422,'student does not belong to selected school');
            if ($old['studentNumber']!==null && $old['studentNumber']!==$number) school_fail(409,'existing student number differs; no values overwritten');
            if ($old['studentNumber']===$number) continue;
            school_query($pdo,'UPDATE students SET studentNumber=? WHERE studentID=?',[$number,$id]);
            school_log($pdo,['schoolID'=>$school],'student',$id,'number_import',$old,['studentNumber'=>$number]);
        }
    });
    echo count($rows)." student numbers verified/imported; credentials unchanged.\n";
} catch (Throwable $e) { fwrite(STDERR,$e instanceof SchoolApiError ? $e->getMessage()."\n" : "Import failed; transaction rolled back. Check file, schema and duplicate student numbers.\n"); exit(1); }
