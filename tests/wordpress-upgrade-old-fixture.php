<?php
// Deterministic local contracts only: no download, database, or installation proof.
require __DIR__.'/../docker/wordpress-upgrade/old-fixture.php';
function check($condition) { if (!$condition) throw new RuntimeException('Assertion failed.'); }
foreach (['wordpress/wp-load.php','wordpress/wp-content/themes/twentytwentythree/assets/fonts/inter/Inter-VariableFont_slnt,wght.ttf','wordpress/wp-content/themes/twentytwentyfive/style.css','wordpress/wp-content/plugins/akismet/.htaccess'] as $path) {
    check(old_fixture_path($path) === substr($path,10));
}
foreach (['/wordpress/a','wordpress/../a','wordpress/a/../../b','wordpress//a','wordpress/a\\b','wordpress/a/../b','wordpress/a\0b','other/a'] as $path) {
    try { old_fixture_path($path); throw new LogicException('Accepted unsafe path.'); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
check(old_fixture_manifest('{"checksums":{"wp-load.php":"'.str_repeat('a',32).'"}}') === ['wp-load.php'=>str_repeat('a',32)]);
foreach (['{}','{"checksums":[]}','{"checksums":{"../a":"abc"}}','{"checksums":{"wp-load.php":"BAD"}}'] as $json) {
    try { old_fixture_manifest($json); throw new LogicException('Accepted invalid manifest.'); }
    catch (InvalidArgumentException $e) { /* expected */ }
}
require __DIR__.'/../docker/wordpress-upgrade/import.php';
$ddl='CREATE TABLE `old_posts` (`ID` bigint NOT NULL AUTO_INCREMENT, PRIMARY KEY (`ID`)) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
$expected='CREATE TABLE `old_posts` (`ID` bigint NOT NULL AUTO_INCREMENT, PRIMARY KEY (`ID`)) AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci ENGINE=InnoDB';
check(old_fixture_create_statement($ddl)===$expected);
check(upgrade_import_statements(old_fixture_create_statement($ddl).';','old_')===[$expected]);
try { old_fixture_create_statement($ddl.' DATA DIRECTORY="/outside"'); throw new LogicException('Accepted unsafe table options.'); }
catch (RuntimeException $e) { check(!($e instanceof LogicException)); }
foreach ([[],['invalid'],['prefetch','unexpected']] as $args) {
    $command=[PHP_BINARY,__DIR__.'/../docker/wordpress-upgrade/old-fixture.php',...$args];
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(stream_get_contents($pipes[1])===''); fclose($pipes[1]);
    check(stream_get_contents($pipes[2])==="Old fixture failed closed.\n"); fclose($pipes[2]);
    check(proc_close($process)===1);
}
$process=proc_open([PHP_BINARY,__DIR__.'/../docker/wordpress-upgrade/old-fixture.php','--help'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
check(stream_get_contents($pipes[1])==="Usage: old-fixture.php prefetch|prepare|install|--help (fixed /cache and /data only)\n"); fclose($pipes[1]);
check(stream_get_contents($pipes[2])===''); fclose($pipes[2]); check(proc_close($process)===0);
echo "old fixture contracts: ok\n";
