<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
function school_file_bytes(string $field,int $limit): array {
    $file=$_FILES[$field] ?? null;
    if (!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) school_fail(422,"valid $field upload required");
    if ((int)$file['size']>$limit) school_fail(413,'file too large');
    $bytes=file_get_contents($file['tmp_name']);
    if ($bytes===false || strlen($bytes)>$limit) school_fail(413,'file unavailable or too large');
    $name=basename((string)$file['name']);
    if (mb_strlen($name)>255) school_fail(422,'filename too long');
    return [$bytes,$name,$file['tmp_name']];
}
function school_store_file(string $area,string $bytes,string $extension='ics'): string {
    if (!in_array($area,['course_tables','school_calendars','school_logos'],true)) throw new LogicException('invalid storage area');
    $dir=UPLOAD_ROOT.'/'.$area;
    if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('storage unavailable');
    $name=bin2hex(random_bytes(16)).'.'.$extension;
    $temporary=$dir.'/'.$name.'.tmp';
    if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes) || !rename($temporary,$dir.'/'.$name)) throw new RuntimeException('file write failed');
    return 'uploads/'.$area.'/'.$name;
}
function school_read_file(string $path,string $area): string {
    $path=ltrim($path,'/');
    if (!preg_match('~^uploads/'.preg_quote($area,'~').'/[a-zA-Z0-9_-]+\.ics$~D',$path)) throw new RuntimeException('invalid ICS path');
    $root=realpath(UPLOAD_ROOT.'/'.$area);
    $file=realpath(UPLOAD_ROOT.'/'.substr($path,8));
    if (!$root || !$file || !str_starts_with($file,$root.'/') || !is_file($file) || filesize($file)>5*1024*1024) throw new RuntimeException('ICS file unavailable');
    $bytes=file_get_contents($file);
    if ($bytes===false) throw new RuntimeException('ICS read failed');
    return $bytes;
}
function school_download(string $content,string $name): never {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$name.'.ics"');
    header('X-Content-Type-Options: nosniff'); echo $content; exit;
}
