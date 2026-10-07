<?php
declare(strict_types=1);
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../../config/app.php';
date_default_timezone_set('Asia/Shanghai');

final class SchoolApiError extends RuntimeException {
    public function __construct(public int $status, string $message, public mixed $details = null) { parent::__construct($message); }
}
function school_fail(int $status, string $message, mixed $details = null): never { throw new SchoolApiError($status,$message,$details); }
function school_db(): PDO {
    static $pdo;
    return $pdo ??= require __DIR__ . '/../../config/database.php';
}
function school_query(PDO $pdo, string $sql, array $args = []): PDOStatement {
    $stmt = $pdo->prepare($sql); $stmt->execute($args); return $stmt;
}
function school_one(PDO $pdo, string $sql, array $args = []): ?array {
    return school_query($pdo,$sql,$args)->fetch() ?: null;
}
function school_input(): array {
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) return $_POST;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) school_fail(413,'request too large');
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if (strlen($raw) > 1048576) school_fail(413,'request too large');
    if ($raw === '') return [];
    try { $data = json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException) { school_fail(400,'invalid JSON'); }
    if (!is_array($data) || !str_starts_with(ltrim($raw),'{')) school_fail(400,'JSON object required');
    return $data;
}
function school_text(array $data,string $key,int $max=255,bool $required=true): string {
    $v = $data[$key] ?? '';
    if (!is_string($v)) school_fail(422,"$key must be a string");
    $v = trim($v);
    if (($required && $v === '') || mb_strlen($v) > $max) school_fail(422,"invalid $key");
    return $v;
}
function school_int(array $data,string $key,int $min=1,int $max=PHP_INT_MAX): int {
    $v = filter_var($data[$key] ?? null,FILTER_VALIDATE_INT);
    if ($v === false || $v < $min || $v > $max) school_fail(422,"invalid $key");
    return $v;
}
function school_array(array $data,string $key): array {
    $v = $data[$key] ?? [];
    if (is_string($v)) { try { $v = json_decode($v,true,32,JSON_THROW_ON_ERROR); } catch (Throwable) { school_fail(422,"invalid $key"); } }
    if (!is_array($v) || !array_is_list($v)) school_fail(422,"$key must be a list");
    return $v;
}
function school_json(mixed $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function school_page(): array {
    $page = school_int(['page'=>$_GET['page'] ?? 1],'page',1,1000000);
    $limit = school_int(['limit'=>$_GET['limit'] ?? 20],'limit',1,100);
    return [$page,$limit,($page-1)*$limit];
}
function school_list(PDO $pdo,string $from,array $args,string $order): array {
    [$page,$limit,$offset] = school_page();
    $total = (int)school_query($pdo,"SELECT COUNT(*) $from",$args)->fetchColumn();
    $items = school_query($pdo,"SELECT * $from ORDER BY $order LIMIT $limit OFFSET $offset",$args)->fetchAll();
    return compact('items','page','limit','total');
}
function school_version(array $row,array $input): void {
    if ((int)$row['version'] !== school_int($input,'version')) school_fail(409,'record changed; reload before editing',['version'=>(int)$row['version']]);
}
function school_log(PDO $pdo,array $auth,string $resource,int $id,string $action,mixed $before,mixed $after): void {
    school_query($pdo,'INSERT INTO school_operation_logs (schoolID,adminID,resource,resourceID,action,beforeJSON,afterJSON) VALUES (?,?,?,?,?,?,?)',
        [$auth['schoolID'],$auth['adminID'] ?? null,$resource,$id,$action,$before === null ? null : school_json($before),$after === null ? null : school_json($after)]);
}
function school_history(PDO $pdo,array $auth,string $resource,int $id): array {
    return school_list($pdo,'FROM school_operation_logs WHERE schoolID=? AND resource=? AND resourceID=?',[$auth['schoolID'],$resource,$id],'logID DESC');
}
function school_transaction(PDO $pdo,callable $fn): mixed {
    $pdo->beginTransaction();
    try { $result=$fn(); $pdo->commit(); return $result; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function school_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer (sc_[a-f0-9]{64})$/D',$header,$matches)) school_fail(401,'school login required');
    return $matches[1];
}
function school_auth(PDO $pdo): array {
    $hash = hash('sha256',school_token());
    $account = school_one($pdo,'SELECT a.adminID,a.schoolID,a.loginName,s.expiresAt FROM school_admin_sessions s JOIN school_admin_accounts a ON a.adminID=s.adminID WHERE s.tokenHash=? AND s.expiresAt>NOW() AND a.enabled=1',[$hash]);
    if (!$account) school_fail(401,'school session expired');
    $account['tokenHash']=$hash;
    return $account;
}
function school_run(callable $fn,bool $authenticated=true): never {
    header('Cache-Control: private, no-store');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim',explode(',',getenv('CAMPURA_ADMIN_ORIGINS') ?: '')));
    if ($origin !== '') {
        if (!in_array($origin,$allowed,true)) respondJson(403,'origin not allowed',null,403);
        header('Access-Control-Allow-Origin: '.$origin); header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    try {
        $pdo=school_db(); $auth=$authenticated ? school_auth($pdo) : [];
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method,['GET','POST','PATCH'],true)) school_fail(405,'method not allowed');
        $result=$fn($pdo,$auth,$method,$method === 'GET' ? $_GET : school_input());
        respondJson(200,'success',$result);
    } catch (SchoolApiError $e) { respondJson($e->status,$e->getMessage(),$e->details,$e->status); }
    catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) respondJson(409,'duplicate record',null,409);
        error_log('school API database failure: '.($e->errorInfo[1] ?? 'unknown'));
        respondJson(500,'database operation failed',null,500);
    } catch (Throwable $e) { error_log('school API failure: '.get_class($e)); respondJson(500,'operation failed',null,500); }
}
function school_scope(PDO $pdo,int $schoolID,array $input,bool $requireExistingClass=true): array {
    $scope=['schoolID'=>$schoolID];
    foreach (['compoundID','departmentID','classID'] as $key) $scope[$key]=school_int($input,$key);
    if (!school_one($pdo,'SELECT d.departmentID FROM departments d JOIN compounds c ON c.compoundID=d.compoundID WHERE c.schoolID=? AND c.compoundID=? AND d.departmentID=?',[$schoolID,$scope['compoundID'],$scope['departmentID']])) school_fail(422,'invalid school department');
    if ($requireExistingClass && !school_one($pdo,'SELECT studentID FROM students WHERE schoolID=? AND compoundID=? AND departmentID=? AND classID=? LIMIT 1',array_values($scope))
        && !school_one($pdo,'SELECT tableID FROM course_tables WHERE schoolID=? AND compoundID=? AND departmentID=? AND classID=? LIMIT 1',array_values($scope))) school_fail(422,'unknown class');
    return $scope;
}
