<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__); $count=0;
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path=$file->getPathname();
    if ($file->getExtension()!=='php' || str_contains($path,'/vendor/') || str_contains($path,'/.git/')) continue;
    $process=proc_open([PHP_BINARY,'-l',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) { fwrite(STDERR,$out.$err); exit(1); } ++$count;
}
$api=json_decode(file_get_contents($root.'/docs/openapi-school.json'),true,512,JSON_THROW_ON_ERROR);
function validateRefs(mixed $node,array $root): void {
    if (!is_array($node)) return;
    if (isset($node['$ref'])) {
        $target=$root;
        foreach (explode('/',substr($node['$ref'],2)) as $key) {
            if (!array_key_exists($key,$target)) throw new RuntimeException('unresolved OpenAPI reference');
            $target=$target[$key];
        }
    }
    foreach ($node as $child) validateRefs($child,$root);
}
validateRefs($api,$api);
echo "$count PHP files passed lint; OpenAPI JSON and local references valid.\n";
