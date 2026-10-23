<?php
// Included by site-report.php after authentication and report generation.
$snapshot = json_decode($body, true);
$export = ['repository'=>'https://github.com/example/private-audit.git', 'token'=>'SYNTHETIC_TOKEN_123', 'branch'=>'wordpress-report'];
foreach ($headers as $header) {
    if (stripos($header, 'X-Site-Report-Id: ') === 0) $export['report_id'] = trim(substr($header, strlen('X-Site-Report-Id: ')));
    if (stripos($header, 'X-Site-Report-CSRF: ') === 0) $export['csrf'] = trim(substr($header, strlen('X-Site-Report-CSRF: ')));
}
check(isset($export['report_id'], $export['csrf']), 'generation supplies snapshot and CSRF bindings');
$ops = fn() => is_file($site.'/github-ops.json') ? json_decode(file_get_contents($site.'/github-ops.json'), true) : [];
$before = $ops();
[$denied, $deniedHeaders] = request('?action=site_report_github', array_merge($export, ['csrf'=>'bad']));
check(str_contains($deniedHeaders[0], '403') && $ops() === $before, 'CSRF rejection has no remote side effects');
foreach (['http://github.com/example/repo','https://github.com.evil.test/example/repo','https://name:secret@github.com/example/repo','https://github.com/example/repo?token=oops'] as $url) {
    [$denied, $deniedHeaders] = request('?action=site_report_github', array_merge($export, ['repository'=>$url]));
    check(str_contains($deniedHeaders[0], '400') && $ops() === $before, 'reject unsafe repository without API calls');
}
foreach (['../main','bad.lock','-branch','foo//bar','foo@{bar','foo bar'] as $branch) {
    [$denied, $deniedHeaders] = request('?action=site_report_github', array_merge($export, ['branch'=>$branch]));
    check(str_contains($deniedHeaders[0], '400') && $ops() === $before, 'reject unsafe branch without API calls');
}
foreach (['public','auth','redirect','empty'] as $scenario) {
    $start=count($ops());
    [$denied, $deniedHeaders] = request('?action=site_report_github', array_merge($export, ['fixture_scenario'=>$scenario]));
    $new=array_slice($ops(),$start);
    check((json_decode($denied,true)['ok'] ?? null) === false, 'remote '.$scenario.' rejected');
    check(count(array_filter($new, fn($op)=>$op['method'] !== 'GET')) === 0 && !str_contains($denied,'SYNTHETIC_TOKEN'), 'remote '.$scenario.' has no writes or reflected secrets');
}
[$denied] = request('?action=site_report_github', array_merge($export,['branch'=>'main']));
check((json_decode($denied,true)['ok'] ?? null) === false, 'default branch protected');
$start=count($ops());
[$sent, $sentHeaders] = request('?action=site_report_github', $export);
$result=json_decode($sent,true);
check(($result['ok'] ?? false) && $result['filename'] === 'wp.example.test.json', 'private export succeeds: ' . $sent);
$writes=array_values(array_filter(array_slice($ops(),$start),fn($op)=>$op['method'] !== 'GET'));
check(count($writes) === 2 && $writes[0]['payload']['ref'] === 'refs/heads/wordpress-report', 'creates default report branch');
check($writes[1]['path'] === '/repos/example/private-audit/contents/wp.example.test.json' && $writes[1]['payload']['branch'] === 'wordpress-report', 'writes domain JSON in selected branch');
check(json_decode(base64_decode($writes[1]['payload']['content']),true) === $snapshot && !isset($writes[1]['payload']['sha']), 'new file matches reviewed snapshot exactly');
$start=count($ops());
[$sent] = request('?action=site_report_github', array_merge($export,['fixture_scenario'=>'existing','branch'=>'audits/production']));
$writes=array_values(array_filter(array_slice($ops(),$start),fn($op)=>$op['method'] !== 'GET'));
check((json_decode($sent,true)['ok'] ?? false) && count($writes) === 1 && $writes[0]['payload']['sha'] === str_repeat('a',40) && $writes[0]['payload']['branch'] === 'audits/production', 'editable existing branch updates using file SHA');
[$denied] = request('?action=site_report_github', array_merge($export,['fixture_scenario'=>'conflict']));
check((json_decode($denied,true)['ok'] ?? null) === false, 'write conflicts reported without retry');
$before=$ops();
[$denied] = request('?action=site_report_github', array_merge($export,['report_id'=>'stale']));
check((json_decode($denied,true)['ok'] ?? null) === false && $before === $ops(), 'stale report cannot publish');
