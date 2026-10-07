<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/school/auth.php';
try {
    $school=school_int(['schoolID'=>$argv[1] ?? null],'schoolID');
    $name=school_text(['loginName'=>$argv[2] ?? null],'loginName',100);
    // Pipe from a secret manager or read with a hidden shell prompt; never put passwords in argv.
    $password=school_password(['password'=>rtrim(stream_get_contents(STDIN),"\r\n")],'password');
    if (strlen($password)<12) school_fail(422,'password must be 12–72 bytes');
    $pdo=school_db();
    school_transaction($pdo,function() use($pdo,$school,$name,$password) {
        if (!school_one($pdo,'SELECT schoolID FROM schools WHERE schoolID=? FOR UPDATE',[$school])) school_fail(404,'school not found');
        if (school_one($pdo,'SELECT adminID FROM school_admin_accounts WHERE schoolID=? OR loginName=?',[$school,$name])) school_fail(409,'account exists; provisioning never replaces credentials');
        school_query($pdo,'INSERT INTO school_admin_accounts (schoolID,loginName,passwordHash) VALUES (?,?,?)',[$school,$name,password_hash($password,PASSWORD_DEFAULT)]);
        school_log($pdo,['schoolID'=>$school],'account',(int)$pdo->lastInsertId(),'provisioned',null,['loginName'=>$name]);
    });
    echo "School administrator created; no student accounts changed.\n";
} catch (Throwable $e) { fwrite(STDERR,$e instanceof SchoolApiError ? $e->getMessage()."\n" : "Provisioning failed; verify schema and unique account names.\n"); exit(1); }
