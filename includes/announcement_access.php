<?php
declare(strict_types=1);
function announcementVisibleTo(?array $target,array $student): bool {
    if ($target === null) return true;
    if (($target['allSchool'] ?? false) === true) return true;
    foreach (['compoundIDs'=>'compoundID','departmentIDs'=>'departmentID','classIDs'=>'classID','studentIDs'=>'studentID'] as $list=>$key) {
        if (isset($student[$key]) && in_array((int)$student[$key],array_map('intval',(array)($target[$list] ?? [])),true)) return true;
    }
    foreach ((array)($target['classScopes'] ?? []) as $scope) {
        if ((int)($scope['compoundID'] ?? 0)===(int)($student['compoundID'] ?? -1)
            && (int)($scope['departmentID'] ?? 0)===(int)($student['departmentID'] ?? -1)
            && (int)($scope['classID'] ?? 0)===(int)($student['classID'] ?? -1)) return true;
    }
    return false;
}
function announcementTarget(?string $raw): ?array {
    if ($raw === null || $raw === '') return null;
    $target=json_decode($raw,true);
    // Malformed targeting is not a public announcement.
    return is_array($target) ? $target : ['allSchool'=>false];
}
function announcementContent(array $row): string {
    $title=trim((string)($row['title'] ?? ''));
    if ($title==='') return (string)$row['content'];
    return ((int)$row['type']===2 ? '# '.$title."\n\n" : $title."\n").$row['content'];
}
function announcementClientTarget(?array $target,array $student): ?array {
    if ($target === null) return null;
    $result=['allSchool'=>($target['allSchool'] ?? false) === true,'areaIDs'=>[],'departmentIDs'=>[],'classIDs'=>[],'studentIDs'=>[]];
    foreach (['areaIDs','departmentIDs','classIDs','studentIDs'] as $key) $result[$key]=array_map('intval',(array)($target[$key] ?? []));
    // Existing iOS performs a second, legacy audience check. Include the authorized
    // viewer in the effective audience when new compound/composite-class rules match.
    if (announcementVisibleTo($target,$student) && isset($student['studentID'])) $result['studentIDs']=array_values(array_unique([...$result['studentIDs'],(int)$student['studentID']]));
    return $result;
}
