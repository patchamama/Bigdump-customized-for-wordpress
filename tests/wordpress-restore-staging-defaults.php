<?php
// Compile trusted typed schemas without executing the app or connecting to a database.
if (in_array('--help',$argv,true)) {
    echo "Usage: php tests/wordpress-restore-staging-defaults.php [--existing]\n";
    echo "STAGING_DEFAULT_SOURCE selects a trusted runtime source; --existing checks unchanged defaults only.\n";
    exit(0);
}
$source=file_get_contents(getenv('STAGING_DEFAULT_SOURCE') ?: dirname(__DIR__).'/cms_tools.php');
foreach (['wp_restore_sql_charset','wp_restore_sql_collation','wp_restore_backup_quote','wp_restore_staging_literal','wp_restore_staging_create'] as $name) {
    $anchor='function '.$name.'(';
    if (substr_count($source,$anchor)!==1) throw new RuntimeException('Trusted function boundary missing');
    $start=strpos($source,$anchor); $end=strpos($source,"\nfunction ",$start+strlen($anchor));
    if ($end===false) throw new RuntimeException('Trusted function boundary invalid');
    eval(substr($source,$start,$end-$start));
}
function defaultCase(string $type, array $args, array $cell, string $expected): void {
    global $checks;
    $schema=['kind'=>'create','table'=>'source','columns'=>[['name'=>'payload','type'=>$type,'args'=>$args,
        'attributes'=>['default'=>$cell,'comment'=>['type'=>'bytes','base64'=>base64_encode('comment')]]]],
        'keys'=>[],'options'=>['ENGINE'=>'INNODB','CHARSET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']];
    $actual=wp_restore_staging_create($schema,'derived');
    $column='`payload` '.$type.($args?'('.implode(',',$args).')':'').' DEFAULT '.$expected." COMMENT 'comment'";
    $wanted='CREATE TABLE `derived` ('.$column.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if ($actual!==$wanted) { fwrite(STDERR,'FAIL staged default syntax: '.$type."\n"); exit(1); }
    $checks++;
}
$checks=0;
$bytes=['type'=>'bytes','base64'=>base64_encode("q'uote")];
if (!in_array('--existing',$argv,true)) {
    foreach (['LONGTEXT','TINYTEXT','TEXT','MEDIUMTEXT','TINYBLOB','BLOB','MEDIUMBLOB','LONGBLOB'] as $type) {
        defaultCase($type,[],$bytes,"('q''uote')");
        defaultCase($type,[],['type'=>'hex','value'=>'4100'],"(X'4100')");
        defaultCase($type,[],['type'=>'number','value'=>'0'],'(0)');
        defaultCase($type,[],['type'=>'null'],'NULL');
    }
}
defaultCase('VARCHAR',[32],$bytes,"'q''uote'");
defaultCase('INT',[],['type'=>'number','value'=>'-4'],'-4');
defaultCase('TIMESTAMP',[6],['type'=>'current_timestamp','precision'=>6],'CURRENT_TIMESTAMP(6)');
defaultCase('BIT',[9],['type'=>'hex','value'=>'0001'],"X'0001'");
echo 'PASS staged default cases: '.$checks."\n";
