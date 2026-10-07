<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/school/core.php';
try {
    $school=school_int(['id'=>$argv[1] ?? null],'id'); $shop=school_int(['id'=>$argv[2] ?? null],'id');
    if (($argv[3] ?? '')!=='--demo') school_fail(422,'Usage: php scripts/seed_school_demo.php SCHOOL_ID SHOP_ID --demo');
    $pdo=school_db();
    school_transaction($pdo,function() use($pdo,$school,$shop) {
        $owner=school_one($pdo,'SELECT shopID,adIDs FROM main_shops WHERE schoolID=? AND shopID=? FOR UPDATE',[$school,$shop]);
        if (!$owner) school_fail(404,'shop must belong to school');
        $ids=json_decode($owner['adIDs'] ?: '[]',true,32,JSON_THROW_ON_ERROR);
        if (!is_array($ids) || !array_is_list($ids)) school_fail(422,'invalid existing shop adIDs');
        foreach (['L','S','L'] as $index=>$type) {
            $order='CAMPURA-DEMO-'.$school.'-'.$shop.'-'.($index+1);
            if (school_one($pdo,'SELECT adID FROM advertisement_quotes WHERE orderNumber=?',[$order])) continue;
            school_query($pdo,'INSERT INTO promotion_events (saleRule,startTime,endTime) VALUES (?,NOW(),DATE_ADD(NOW(),INTERVAL 30 DAY))',['[演示申请] 开业首周85折；不产生真实交易']);
            $sale=(int)$pdo->lastInsertId();
            school_query($pdo,'INSERT INTO advertisements (saleID,type,img,startTime,endTime) VALUES (?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 30 DAY))',[$sale,$type,APP_BASE_URL.'/tests/fixtures/demo-ad.svg']);
            $id=(int)$pdo->lastInsertId(); $ids[]=$id;
            school_query($pdo,"INSERT INTO advertisement_reviews (adID,schoolID,shopID,status,reason,isDemo) VALUES (?,?,?,'pending','',1)",[$id,$school,$shop]);
            school_query($pdo,'INSERT INTO advertisement_quotes (adID,orderNumber,packageName,amount) VALUES (?,?,?,?)',[$id,$order,'演示套餐 · '.$type,[120,60,180][$index]]);
            school_log($pdo,['schoolID'=>$school],'advertisement',$id,'demo_created',null,['isDemo'=>true,'orderNumber'=>$order,'status'=>'pending']);
        }
        school_query($pdo,'UPDATE main_shops SET adIDs=? WHERE shopID=?',[school_json($ids),$shop]);
    });
    echo "Demo applications ready (idempotent, pending review, no payments).\n";
} catch (Throwable $e) { fwrite(STDERR,$e instanceof SchoolApiError ? $e->getMessage()."\n" : "Demo seed failed; transaction rolled back.\n"); exit(1); }
