<?php
declare(strict_types=1);
require_once __DIR__.'/files.php';
require_once __DIR__.'/../../vendor/autoload.php';
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

function school_ics_date(string $value): void {
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/D',$value,$m)
        || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])
        || (isset($m[4]) && ((int)$m[4]>23 || (int)$m[5]>59 || (int)$m[6]>59))) school_fail(422,'invalid ICS calendar date');
}
function school_event_start($event): DateTimeImmutable {
    return DateTimeImmutable::createFromInterface($event->DTSTART->getDateTime(new DateTimeZone('Asia/Shanghai')));
}
function school_parse_ics(string $bytes,bool $compiled=false): VCalendar {
    if (strlen($bytes)>($compiled ? 5*1024*1024 : MAX_ICS_UPLOAD_SIZE) || !mb_check_encoding($bytes,'UTF-8')) school_fail(422,'ICS exceeds size limit or is not UTF-8');
    try {
        $calendar=Reader::read($bytes);
        if (!$calendar instanceof VCalendar) school_fail(422,'VCALENDAR required');
        $events=$calendar->select('VEVENT');
        foreach ($calendar->getComponents() as $component) {
            if (!in_array($component->name,['VEVENT','VTIMEZONE'],true)) school_fail(422,'only VEVENT and VTIMEZONE components are supported');
        }
        if (count($events)>($compiled ? 10000 : 1000)) school_fail(422,'too many source events');
        foreach ($calendar->validate() as $issue) {
            // A deliberately empty template means "no classes this week". RFC
            // component-count validation is the only exception; all other errors remain fatal.
            if (!$events && $issue['node']===$calendar && $issue['message']==='An iCalendar object must have at least 1 component.') continue;
            if ($issue['level']>=3) school_fail(422,'invalid ICS structure');
        }
        foreach ($events as $event) {
            if (!isset($event->DTSTART) || !isset($event->SUMMARY) || !isset($event->UID)) school_fail(422,'events require UID, SUMMARY and DTSTART');
            foreach ($event->children() as $property) {
                if ($property->name==='RDATE' && !$property instanceof \Sabre\VObject\Property\ICalendar\DateTime) school_fail(422,'RDATE must contain dates or date-times');
                if (!$property instanceof \Sabre\VObject\Property\ICalendar\DateTime) continue;
                foreach ($property->getParts() as $part) school_ics_date($part);
                if (isset($property['TZID'])) \Sabre\VObject\TimeZoneUtil::getTimeZone((string)$property['TZID'],$calendar,true);
            }
            $start=school_event_start($event); $end=school_event_end($event);
            if ($end <= $start) school_fail(422,'event end must follow start');
            if (isset($event->RRULE)) {
                $rule=(string)$event->RRULE;
                if (!preg_match('/(?:^|;)FREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)(?:;|$)/',$rule)
                    || preg_match('/(?:^|;)(BYSECOND|BYMINUTE|BYHOUR)=/',$rule)) school_fail(422,'unsupported high-frequency recurrence');
                if (preg_match('/(?:^|;)UNTIL=([^;]+)/',$rule,$until)) school_ics_date($until[1]);
            }
        }
        return $calendar;
    } catch (SchoolApiError $e) { throw $e; }
    catch (Throwable) { school_fail(422,'invalid or unsupported ICS'); }
}
function school_event_end($event): DateTimeImmutable {
    if (isset($event->DTEND)) return DateTimeImmutable::createFromInterface($event->DTEND->getDateTime(new DateTimeZone('Asia/Shanghai')));
    $start=school_event_start($event);
    if (isset($event->DURATION)) return $start->add(new DateInterval((string)$event->DURATION));
    if (strlen((string)$event->DTSTART)===8) return $start->modify('+1 day');
    school_fail(422,'timed events require DTEND or DURATION');
}
function school_term_dates(array $term): array {
    return [new DateTimeImmutable($term['startDate'].' 00:00:00',new DateTimeZone('Asia/Shanghai')),
        (new DateTimeImmutable($term['endDate'].' 00:00:00',new DateTimeZone('Asia/Shanghai')))->modify('+1 day')];
}
function school_expand_ics(string $bytes,DateTimeImmutable $start,DateTimeImmutable $end): array {
    $calendar=school_parse_ics($bytes);
    // Limit work before expansion even for malicious recurrence combinations.
    if (count($calendar->select('VEVENT')) * max(1,(int)$start->diff($end)->days)>100000) school_fail(422,'calendar expansion too large');
    try {
        $expanded=$calendar->expand($start,$end,new DateTimeZone('Asia/Shanghai'));
        $events=[];
        foreach ($expanded->select('VEVENT') as $event) {
            if ((string)($event->STATUS ?? '')==='CANCELLED') continue;
            $at=school_event_start($event);
            if ($at<$start || $at>=$end) continue;
            $events[]=clone $event;
            if (count($events)>10000) school_fail(422,'expanded calendar exceeds 10000 events');
        }
        return $events;
    } catch (SchoolApiError $e) { throw $e; }
    catch (Throwable) { school_fail(422,'unsupported recurrence or timezone'); }
}
function school_render_events(array $events,string $namespace): string {
    $calendar=new VCalendar(['PRODID'=>'-//Campura One//School Console//CN','CALSCALE'=>'GREGORIAN']);
    $seen=[];
    foreach ($events as $source) {
        $event=clone $source;
        $start=school_event_start($event); $end=school_event_end($event);
        $uid=hash('sha256',$namespace.'|'.(string)$event->UID.'|'.$start->format('c')).'@campura.one';
        if (isset($seen[$uid])) school_fail(422,'duplicate event UID and start time');
        $seen[$uid]=true;
        $event->UID=$uid;
        $allDay=strlen((string)$event->DTSTART)===8;
        // Mobile parsers read flat VEVENT properties and cannot distinguish an
        // alarm's nested SUMMARY from the event's own SUMMARY.
        foreach (['RRULE','RDATE','EXDATE','RECURRENCE-ID','DURATION','DTSTART','DTEND','VALARM'] as $key) unset($event->$key);
        if ($allDay) {
            $event->add('DTSTART',$start->format('Ymd'),['VALUE'=>'DATE']);
            $event->add('DTEND',$end->format('Ymd'),['VALUE'=>'DATE']);
        } else {
            $event->add('DTSTART',$start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            $event->add('DTEND',$end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        }
        $calendar->add($event);
    }
    $bytes=$calendar->serialize();
    if (strlen($bytes)>5*1024*1024) school_fail(422,'compiled ICS too large');
    return $bytes;
}
function school_week(array $term,int $number): DateTimeImmutable {
    $start=new DateTimeImmutable($term['firstMonday']);
    $week=$start->modify('+'.(($number-1)*7).' days');
    if ($number<1 || $week>new DateTimeImmutable($term['endDate'])) school_fail(422,'week outside semester');
    return $week;
}
function school_template_events(string $bytes): array {
    $calendar=school_parse_ics($bytes); $week=null;
    foreach ($calendar->select('VEVENT') as $event) {
        $date=school_event_start($event)->setTimezone(new DateTimeZone('Asia/Shanghai'))->setTime(0,0);
        $monday=$date->modify('-'.((int)$date->format('N')-1).' days');
        if ($week && $week!=$monday) school_fail(422,'temporary ICS must describe one source week');
        $week=$monday;
    }
    if (!$week) return [[],null]; // An empty calendar explicitly cancels selected weeks.
    foreach ($calendar->select('VEVENT') as $event) {
        foreach ($event->select('RDATE') as $dates) {
            foreach ($dates->getDateTimes(new DateTimeZone('Asia/Shanghai')) as $date) {
                if ($date<$week || $date>=$week->modify('+7 days')) school_fail(422,'temporary RDATE outside source week');
            }
        }
    }
    $events=school_expand_ics($bytes,$week,$week->modify('+7 days'));
    foreach ($events as $event) if (school_event_end($event)>$week->modify('+7 days')) school_fail(422,'temporary event crosses source-week boundary');
    return [$events,$week];
}
function school_compile_table(PDO $pdo,array $row,array $term): string {
    [$start,$end]=school_term_dates($term); $tableID=(int)$row['tableID'];
    $base=school_one($pdo,"SELECT * FROM course_table_versions WHERE tableID=? AND versionID=? AND kind='base'",[$tableID,$row['baseVersionID']]);
    if (!$base) school_fail(409,'base timetable required');
    $events=school_expand_ics(school_read_file($base['filePath'],'course_tables'),$start,$end);
    $overrides=school_query($pdo,'SELECT o.weekNo,v.filePath FROM course_week_overrides o JOIN course_table_versions v ON v.versionID=o.versionID WHERE o.tableID=?',[$tableID])->fetchAll();
    $weeks=array_map('intval',array_column($overrides,'weekNo'));
    $first=new DateTimeImmutable($term['firstMonday']);
    $events=array_values(array_filter($events,function($event) use($weeks,$first) {
        $date=school_event_start($event)->setTimezone(new DateTimeZone('Asia/Shanghai'))->setTime(0,0);
        $week=(int)floor((int)$first->diff($date)->format('%r%a')/7)+1;
        return !in_array($week,$weeks,true);
    }));
    foreach ($overrides as $override) {
        $target=school_week($term,(int)$override['weekNo']);
        [$template,$sourceWeek]=school_template_events(school_read_file($override['filePath'],'course_tables'));
        foreach ($template as $source) {
            $event=clone $source;
            $shift=$target->getTimestamp()-$sourceWeek->getTimestamp();
            $at=school_event_start($event)->modify(($shift>=0?'+':'').$shift.' seconds');
            $until=school_event_end($event)->modify(($shift>=0?'+':'').$shift.' seconds');
            if ($at<$start || $at>=$end) continue;
            $allDay=strlen((string)$event->DTSTART)===8;
            unset($event->DTSTART,$event->DTEND,$event->DURATION);
            $event->add('DTSTART',$allDay?$at->format('Ymd'):$at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),$allDay?['VALUE'=>'DATE']:[]);
            $event->add('DTEND',$allDay?$until->format('Ymd'):$until->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),$allDay?['VALUE'=>'DATE']:[]);
            $events[]=$event;
        }
    }
    if (count($events)>10000) school_fail(422,'compiled event limit exceeded');
    return school_render_events($events,'course-'.$tableID);
}
