<?php
declare(strict_types=1);
$repo = dirname(__DIR__);
$kind = is_file($repo . '/lib/site-vendercrm.php') ? 'pozo' : (is_file($repo . '/_includes/common.inc.php') ? 'prestamo' : 'seguro');
$base = sys_get_temp_dir() . '/vendercrm-bespoke-' . bin2hex(random_bytes(8));
mkdir($base); mkdir($base . '/public'); mkdir($base . '/public/lib'); mkdir($base . '/private');
$public = $base . '/public'; $private = $base . '/private/vendercrm.php'; $count = 0;
function writeCfg(string $path, array $cfg): void { file_put_contents($path, '<?php return ' . var_export($cfg, true) . ';'); }
function fixture(string $repo, string $public, string $kind): void {
    copy($repo . '/lib/vendercrm-config.php', $public . '/lib/vendercrm-config.php');
    if ($kind === 'pozo') { copy($repo . '/lib/site-vendercrm.php', $public . '/lib/site-vendercrm.php'); }
    elseif ($kind === 'prestamo') { mkdir($public . '/_includes'); copy($repo . '/_includes/common.inc.php', $public . '/_includes/common.inc.php'); }
    else { copy($repo . '/contacto-lib.php', $public . '/contacto-lib.php'); }
}
function runCase(string $public, string $kind, array $env, $expected, bool $reject = false, bool $mock = false, array $siteLegacy = []): void {
    global $count;
    $runner = dirname($public) . '/runner.php';
    file_put_contents($runner, <<<'PHP'
<?php
$c=json_decode(stream_get_contents(STDIN),true);
foreach(['VENDERCRM_URL','VENDERCRM_API_KEY','VENDERCRM_CONFIG_FILE','PRESTAMO_CONFIG'] as $n){putenv($n);}
foreach($c['env'] as $n=>$v){putenv($n.'='.$v);}
$root=$argv[1]; $_SERVER['DOCUMENT_ROOT']=$root;
try {
 if($c['kind']==='pozo'){
  require $root.'/lib/site-vendercrm.php';
  $s=pozo_crm_settings($root,$c['siteLegacy'],'https://generated.example.com',dirname($root).'/private/vendercrm.php',null,$c['mock']);
  $actual=[$s['url'],$s['key'],$s['valid']];
 }elseif($c['kind']==='prestamo'){
  require $root.'/_includes/common.inc.php'; $s=pm_load_config()['cfg'];
  $actual=[$s['vcrm_endpoint'],$s['vcrm_api_key'],pm_form_enabled(),$s['storage_dir'],$s['notify_email']];
 }else{
  require $root.'/contacto-lib.php'; $s=cl_config();
  $actual=$s===null?null:[$s['vcrm_endpoint']??null,$s['vcrm_api_key'],$s['notify_email'],$s['from_email']??null];
 }
 exit(!$c['reject']&&$actual===$c['expected']?0:1);
}catch(RuntimeException $e){exit($c['reject']?0:2);}
PHP
    );
    $p = proc_open([PHP_BINARY, $runner, $public], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    if (!is_resource($p)) { throw new RuntimeException('Synthetic process unavailable.'); }
    fwrite($pipes[0], json_encode(compact('kind','env','expected','reject','mock','siteLegacy'))); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($p)!==0 || $out!=='' || $err!=='') { throw new RuntimeException('Synthetic config case failed; no config values printed.'); }
    $count++;
}
$pair=['VENDERCRM_URL'=>'https://crm.example.com','VENDERCRM_API_KEY'=>'synthetic-canonical'];
try {
 fixture($repo,$public,$kind);
 if($kind==='pozo'){
  $expected=['https://crm.example.com','synthetic-canonical',true];
  runCase($public,$kind,[],['https://generated.example.com','',true]);
  runCase($public,$kind,['VENDERCRM_API_KEY'=>'synthetic-old-env'],['https://generated.example.com','synthetic-old-env',true]);
  runCase($public,$kind,$pair,$expected,false,false,['vendercrm_url'=>'https://stale.example.com','vendercrm_key'=>'synthetic-stale','resend_key'=>'synthetic-mail']);
  writeCfg($private,['url'=>'https://legacy.example.com','api_key'=>'synthetic-old-file']);
  runCase($public,$kind,[],['https://legacy.example.com','synthetic-old-file',true]);
  runCase($public,$kind,$pair,$expected);
  runCase($public,$kind,[],['https://site-legacy.example.com','synthetic-site',true],false,false,['vendercrm_url'=>'https://site-legacy.example.com','vendercrm_key'=>'synthetic-site']);
  unlink($private);
  runCase($public,$kind,['VENDERCRM_URL'=>'http://127.0.0.1:9000','VENDERCRM_API_KEY'=>'synthetic-mock'],['http://127.0.0.1:9000','synthetic-mock',true],false,true);
  runCase($public,$kind,['VENDERCRM_URL'=>'http://127.0.0.1:9000','VENDERCRM_API_KEY'=>'synthetic-mock'],['','',false]);
  runCase($public,$kind,['VENDERCRM_URL'=>'https://crm.example.com'],['','',false]);
  writeCfg($private,['VENDERCRM_API_KEY'=>'synthetic-incomplete']);
  runCase($public,$kind,[],['','',false]);
  writeCfg($private,['VENDERCRM_URL'=>'','VENDERCRM_API_KEY'=>'']);
  runCase($public,$kind,[],['','',false]);
 }elseif($kind==='prestamo'){
  $expected=['https://crm.example.com/api/v1/leads','synthetic-canonical',false,'synthetic-storage','synthetic-notify'];
  runCase($public,$kind,$pair,['https://crm.example.com/api/v1/leads','synthetic-canonical',false,'','']);
  writeCfg($public.'/config.php',['contact_form_enabled'=>false,'titular_name'=>'Synthetic owner','storage_dir'=>'synthetic-storage','notify_email'=>'synthetic-notify','vcrm_endpoint'=>'https://legacy.example.com/api/v1/leads','vcrm_api_key'=>'synthetic-legacy']);
  runCase($public,$kind,[],['https://legacy.example.com/api/v1/leads','synthetic-legacy',false,'synthetic-storage','synthetic-notify']);
  runCase($public,$kind,$pair,$expected);
  runCase($public,$kind,['VENDERCRM_URL'=>'https://crm.example.com'],null,true);
  writeCfg($public.'/config.php',['contact_form_enabled'=>true,'titular_name'=>'','storage_dir'=>'synthetic-storage','notify_email'=>'synthetic-notify']);
  runCase($public,$kind,$pair,$expected); // True flag alone cannot activate capture.
  writeCfg($public.'/config.php',['contact_form_enabled'=>true,'titular_name'=>'Synthetic owner','storage_dir'=>'synthetic-storage','notify_email'=>'synthetic-notify']);
  runCase($public,$kind,$pair,['https://crm.example.com/api/v1/leads','synthetic-canonical',true,'synthetic-storage','synthetic-notify']);
  writeCfg($public.'/config.php',['contact_form_enabled'=>false,'titular_name'=>'Synthetic owner','storage_dir'=>'synthetic-storage','notify_email'=>'synthetic-notify']);
 }else{
  $expected=['https://crm.example.com/api/v1/leads','synthetic-canonical','synthetic-notify','synthetic-sender'];
  runCase($public,$kind,$pair,null); // Canonical creds do not bypass notification gate.
  writeCfg($public.'/config.php',['vcrm_endpoint'=>'https://legacy.example.com/api/v1/leads','vcrm_api_key'=>'synthetic-legacy','notify_email'=>'synthetic-notify','from_email'=>'synthetic-sender']);
  runCase($public,$kind,[],['https://legacy.example.com/api/v1/leads','synthetic-legacy','synthetic-notify','synthetic-sender']);
  runCase($public,$kind,$pair,$expected);
  runCase($public,$kind,['VENDERCRM_URL'=>'https://crm.example.com'],null);
  if(file_get_contents($repo.'/contacto-lib.php')!==file_get_contents($repo.'/engine/php/contacto-lib.php')){throw new RuntimeException('Root/template helper diverged.');}
 }
 writeCfg($private,$pair); $original=file_get_contents($private);
 runCase($public,$kind,[],$expected);
 runCase($public,$kind,$pair,$expected);
 $failure=$kind==='pozo'?['','',false]:null;
 runCase($public,$kind,array_merge($pair,['VENDERCRM_API_KEY'=>'synthetic-conflict']),$failure,$kind==='prestamo');
 runCase($public,$kind,array_merge($pair,['VENDERCRM_URL'=>'https://crm.example.com/api/v1/leads']),$failure,$kind==='prestamo');
 writeCfg($public.'/unsafe.php',$pair);
 runCase($public,$kind,['VENDERCRM_CONFIG_FILE'=>$public.'/unsafe.php'],$failure,$kind==='prestamo');
 runCase($public,$kind,['VENDERCRM_CONFIG_FILE'=>$base.'/missing.php'],$failure,$kind==='prestamo');
 // Replace only helper deployment files: private bytes and unrelated site config persist.
 unlink($public.'/lib/vendercrm-config.php'); copy($repo.'/lib/vendercrm-config.php',$public.'/lib/vendercrm-config.php');
 runCase($public,$kind,[],$expected);
 if(file_get_contents($private)!==$original){throw new RuntimeException('Persistent file changed.');}
 echo "PASS: $count offline $kind configuration checks; no network or credential output.\n";
}finally{
 foreach(['lib/vendercrm-config.php','lib/site-vendercrm.php','_includes/common.inc.php','contacto-lib.php','config.php','unsafe.php'] as $file){if(is_file($public.'/'.$file)){unlink($public.'/'.$file);}}
 if(is_dir($public.'/_includes')){rmdir($public.'/_includes');} rmdir($public.'/lib'); rmdir($public);
 if(is_file($private)){unlink($private);} unlink($base.'/runner.php'); rmdir($base.'/private'); rmdir($base);
}
