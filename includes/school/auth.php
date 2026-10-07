<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
function school_password(array $input,string $key): string {
    $value=$input[$key] ?? null;
    if (!is_string($value) || $value==='' || strlen($value)>72 || str_contains($value,"\0")) school_fail(422,'invalid password');
    return $value; // Password whitespace is significant.
}
function school_login(PDO $pdo,array $input): array {
    $name=school_text($input,'loginName',100); $password=school_password($input,'password');
    if (isset($input['rememberMe']) && !is_bool($input['rememberMe'])) school_fail(422,'rememberMe must be boolean');
    $key=hash('sha256',strtolower($name).'|'.($_SERVER['REMOTE_ADDR'] ?? ''));
    return school_transaction($pdo,function() use($pdo,$name,$password,$key,$input) {
        school_query($pdo,'INSERT IGNORE INTO school_login_attempts (attemptKey,attempts,windowStart) VALUES (?,0,NOW())',[$key]);
        $attempt=school_one($pdo,'SELECT * FROM school_login_attempts WHERE attemptKey=? FOR UPDATE',[$key]);
        if (strtotime($attempt['windowStart']) < time()-900) {
            school_query($pdo,'UPDATE school_login_attempts SET attempts=0,windowStart=NOW() WHERE attemptKey=?',[$key]); $attempt['attempts']=0;
        }
        if ((int)$attempt['attempts'] >= 10) school_fail(429,'too many attempts; retry after 15 minutes');
        $account=school_one($pdo,'SELECT * FROM school_admin_accounts WHERE loginName=? FOR UPDATE',[$name]);
        if (!$account || !(int)$account['enabled'] || !password_verify($password,$account['passwordHash'])) {
            school_query($pdo,'UPDATE school_login_attempts SET attempts=attempts+1 WHERE attemptKey=?',[$key]);
            // Commit failed-attempt count before reporting failure.
            $pdo->commit();
            school_fail(401,'invalid login credentials');
        }
        school_query($pdo,'DELETE FROM school_login_attempts WHERE attemptKey=?',[$key]);
        $token='sc_'.bin2hex(random_bytes(32));
        $expires=date('Y-m-d H:i:s',time()+(!empty($input['rememberMe']) ? 30*86400 : 43200));
        school_query($pdo,'INSERT INTO school_admin_sessions (tokenHash,adminID,expiresAt) VALUES (?,?,?)',[hash('sha256',$token),$account['adminID'],$expires]);
        return ['token'=>$token,'expiresAt'=>$expires,'admin'=>['adminID'=>(int)$account['adminID'],'schoolID'=>(int)$account['schoolID'],'loginName'=>$account['loginName']]];
    });
}
function school_auth_action(string $action): never {
    school_run(function(PDO $pdo,array $auth,string $method,array $input) use($action) {
        if ($action === 'login' && $method === 'POST') return school_login($pdo,$input);
        if ($action === 'session' && $method === 'GET') { unset($auth['tokenHash']); return $auth; }
        if ($action === 'logout' && $method === 'POST') {
            school_query($pdo,'DELETE FROM school_admin_sessions WHERE tokenHash=?',[$auth['tokenHash']]); return ['loggedOut'=>true];
        }
        if ($action === 'password' && $method === 'POST') {
            $old=school_password($input,'currentPassword'); $new=school_password($input,'newPassword');
            if (strlen($new)<12 || strlen($new)>72) school_fail(422,'new password must be 12–72 bytes');
            return school_transaction($pdo,function() use($pdo,$auth,$old,$new) {
                $row=school_one($pdo,'SELECT passwordHash FROM school_admin_accounts WHERE adminID=? FOR UPDATE',[$auth['adminID']]);
                if (!password_verify($old,$row['passwordHash'])) school_fail(403,'current password incorrect');
                school_query($pdo,'UPDATE school_admin_accounts SET passwordHash=? WHERE adminID=?',[password_hash($new,PASSWORD_DEFAULT),$auth['adminID']]);
                school_query($pdo,'DELETE FROM school_admin_sessions WHERE adminID=?',[$auth['adminID']]);
                school_log($pdo,$auth,'account',(int)$auth['adminID'],'password_changed',null,null);
                return ['loginRequired'=>true];
            });
        }
        school_fail(405,'method not allowed');
    },$action !== 'login');
}
