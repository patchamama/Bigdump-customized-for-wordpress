<?php
// Called only by the owned native fixture after actual public backup/staging/assurance.
function replacementAuthorityFixture(string $case, PDO $pdo, array $proof, array $manifest): void {
    global $run, $private, $cookie, $lastHttp;
    $input=$proof+['replacement_confirmed'=>'1','scope_confirmed'=>'exact_original_old_set'];
    $refused=['error'=>'Replacement preparation failed. No table replacement was performed.','code'=>'replacement_prepare_failed'];
    $permanent=oracle($pdo); $path=ownedWork().'/state.json'; $checkpoint=hash_file('sha256',$path);
    $unchanged=static function() use($pdo,$permanent,$path,$checkpoint): void {
        global $run;
        [$status,$state]=request('wp_restore_state');
        check($status===200 && $state['run']===$run && oracle($pdo)===$permanent
            && hash_file('sha256',$path)===$checkpoint,'replacement refusal preserves exact public durable and permanent state including counters');
    };
    if ($case==='replacement-complete') {
        [$status,$body]=step('replacement_prepare',$input);
        check($status===200,'complete-path prerequisite has real public prepared authority'); $run=$body['run'];
        $logical=array_column($manifest['run']['values_manifest']['tables'],'name');
        [$status,$body]=step('replacement',$input);
        check($status===200,'real fixed finalizer completes the exact-set replacement'); $run=$body['run'];
        $expected=array_merge($logical,['_wpr_staging_owner']); sort($expected,SORT_STRING);
        $actual=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($actual,SORT_STRING);
        check($actual===$expected,'only the restored logical tables and the control table remain; every retired OLD table is dropped');
        check($pdo->query("SELECT phase FROM `_wpr_staging_owner` WHERE id=1")->fetchColumn()==='complete','durable journal records completion');
        check($run['preflight']['replacement']['status']==='complete' && $run['preflight']['replacement']['recovery_needed']===false
            && !$run['runtime_verified'],'public state reports completion without claiming runtime verification');
        $after=oracle($pdo); $checkpoint=hash_file('sha256',$path);
        [$status,$body]=step('replacement',$input);
        check($status===409 && $body['code']==='replacement_complete' && oracle($pdo)===$after && hash_file('sha256',$path)===$checkpoint,
            'completed replacement refuses replay without SQL or state change');
        [$status,$download]=request('wp_restore_backup_download',['csrf'=>$GLOBALS['csrf'],'run_id'=>$run['run_id']],false);
        check($status===200 && hash('sha256',$download)===$proof['artifact_sha256'],'completed replacement retains authenticated prior recovery download');
        global $site, $originals; check(($run['preflight']['prefix']['status']??null)==='remap' && $run['preflight']['prefix']['config']==='wp_' && $run['preflight']['prefix']['backup']==='old_','public preflight reports the backup prefix and its mapping to the wp-config prefix before any change');
        $trial=$site.'/wpr-trial-'.substr($run['run_id'],0,16); $place=['artifact_sha256'=>$proof['artifact_sha256']];
        [$status,$body]=step('placement_promote',$place+['promote_confirmed'=>'1','scope_confirmed'=>'trial_to_root','trial_sha256'=>str_repeat('0',64)]);
        check($status===400 && $body['code']==='placement_failed' && !file_exists($site.'/wp-load.php'),'promotion before any trial is refused without writing');
        for ($i=0;$i<20;$i++) {
            [$status,$body]=step('placement_trial',$place); check($status===200,'trial placement batch accepted'); $run=$body['run'];
            if ($run['preflight']['placement']['status']==='trial_ready') break;
        }
        check($run['preflight']['placement']['status']==='trial_ready' && $run['preflight']['placement']['held']===1,'trial ready with the held .htaccess counted');
        check(is_file($trial.'/wp-load.php') && str_contains(file_get_contents($trial.'/.htaccess'),'denied') && !file_exists($trial.'/wp-includes/.htaccess') && !file_exists($site.'/wp-load.php') && !file_exists($site.'/index.php'),'files exist only in the trial folder; nothing reached the app root');
        [$status,$body]=step('placement_promote',$place+['promote_confirmed'=>'1']);
        check($status===400 && !file_exists($site.'/wp-load.php'),'promotion without the exact scope confirmation is refused');
        $digest=$run['preflight']['placement']['trial_sha256'];
        [$status,$body]=step('placement_promote',$place+['promote_confirmed'=>'1','scope_confirmed'=>'trial_to_root','trial_sha256'=>str_repeat('0',64)]);
        check($status===400 && !file_exists($site.'/wp-load.php'),'promotion with a different trial digest than the inspected content is refused');
        $place['trial_sha256']=$digest;
        for ($i=0;$i<20;$i++) {
            [$status,$body]=step('placement_promote',$place+['promote_confirmed'=>'1','scope_confirmed'=>'trial_to_root']); check($status===200,'promotion batch accepted'); $run=$body['run'];
            if ($run['preflight']['placement']['status']==='promoted') break;
        }
        check($run['preflight']['placement']['status']==='promoted' && is_file($site.'/wp-load.php') && is_file($site.'/wp-includes/version.php') && !file_exists($trial),
            'confirmed promotion moved verified files to the root and removed the trial folder');
        check(!$run['runtime_verified'] && !isset($run['preflight']['runtime']),'placement itself makes no runtime verification claim');
        $verify=['verify_confirmed'=>'1'];
        [$status,$body]=step('verify_runtime',[]);
        check($status===400 && $body['code']==='runtime_verification_failed' && !isset($body['run']),'runtime verification needs its own confirmation');
        for ($i=0;$i<12;$i++) {
            [$status,$body]=step('verify_runtime',$verify); check($status===200,'runtime verification step accepted'); $run=$body['run'];
            if ($run['preflight']['runtime']['status']!=='running') break;
        }
        $rt=$run['preflight']['runtime'];
        check($rt['status']==='partial' && $rt['good']===['good/good.php'] && $rt['failed']===['bad/bad.php'] && $rt['skipped']===['missing/missing.php'] && !$run['runtime_verified'],
            'HTTP baseline passed; the good plugin stays, the plugin that broke the site was rolled back and the missing one skipped');
        check($pdo->query("SELECT option_value FROM wp_options WHERE option_name='active_plugins'")->fetchColumn()==='a:1:{i:0;s:13:"good/good.php";}','only the healthy plugin remains active in the restored database');
        check(hash_file('sha256',$site.'/wp-config.php')===$originals['wp-config.php'],'wp-config.php is never modified by placement');
        [$status,$state]=request('wp_restore_state');
        check($status===200 && $state['run']['preflight']['placement']['status']==='promoted','run remains loadable after the authorized configuration update');
        return;
    }
    if ($case==='replacement-partition') {
        check($pdo->query("SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA=DATABASE() AND PARTITION_NAME IS NOT NULL")->fetchColumn()==='2','real partitioned original OLD prerequisite');
        [$status,$body]=step('replacement_prepare',$input);
        check($status===400 && $body===$refused,'partitioned original OLD refused before any replacement');
        $unchanged(); return;
    }
    if ($case!=='replacement-terminal') {
        $invalid=[$proof,array_replace($input,['scope_confirmed'=>'namespace_wide']),
            array_replace($input,['artifact_sha256'=>str_repeat('0',64)]),array_replace($input,['plan_sha256'=>str_repeat('0',64)])];
        foreach (['sql','table','target_path','executor','mapping','status'] as $field) $invalid[]=$input+[$field=>'forbidden'];
        foreach ($invalid as $post) {
            [$status,$body]=step('replacement_prepare',$post);
            // This first rule supplies a genuine missing-operation RED on the baseline.
            if ($body!==$refused) echo 'REPLACEMENT_RED '.json_encode(['status'=>$status,'body'=>$body,'state_sha256'=>$checkpoint,
                'permanent_unchanged'=>oracle($pdo)===$permanent],JSON_THROW_ON_ERROR)."\n";
            check($status===400 && $body===$refused,'explicit scope artifact plan confirmation and server-derived targets required');
            $unchanged();
        }
        [$status,$body]=step('replacement_prepare',$input+['csrf'=>'wrong']);
        check($status===403 && $body===['error'=>'Invalid restore session.'],'replacement preparation retains independent CSRF'); $unchanged();
        [$status,$body]=step('replacement_prepare',array_replace($input,['revision'=>(string)($run['revision']+1)]));
        check($status===409 && $body===['error'=>'Stale restore revision.'],'replacement preparation retains revision fencing'); $unchanged();
        $prefix='_wpr_'.substr($run['run_id'],0,32).'_';
        $mapping=['old'=>['_wpr_business'=>$prefix.'r_1','legacy_native'=>$prefix.'r_2'],'new'=>[]];
        foreach ($manifest['run']['values_manifest']['tables'] as $index=>$table) $mapping['new'][$table['name']]=$prefix.($index+1);
        [$status,$body]=step('replacement_prepare',$input);
        check($status===200,'public replacement preparation accepted without destructive dispatch'); $run=$body['run'];
        $expected=['status'=>'prepared','scope'=>'exact_original_old_set','mapping_sha256'=>hash('sha256',json_encode($mapping,JSON_THROW_ON_ERROR)),
            'old_tables'=>2,'new_tables'=>count($mapping['new']),'requires_locked_recheck'=>true,'recovery_needed'=>false];
        check($run['preflight']['replacement']===$expected && !$run['replacement_enabled'] && !$run['runtime_verified'],
            'exact all-OLD including business-prefix mapping is private and preparation grants no replacement or runtime authority');
        check(oracle($pdo)===$permanent,'prepared authority changes no permanent schemas rows ownership journal or counters');
        [$status,$download]=request('wp_restore_backup_download',['csrf'=>$GLOBALS['csrf'],'run_id'=>$run['run_id']],false);
        check($status===200 && hash('sha256',$download)===$proof['artifact_sha256'],'prepared authority retains authenticated exact prior recovery download');
        return;
    }
    // Model future authenticated journal checkpoints as setup only. This is not a
    // crash/durability test and never executes pending SQL or deserializes input.
    preg_match('/^[^=;]+=([A-Za-z0-9,-]+)$/D',$cookie,$match); check(isset($match[1]),'owned fixture session identified');
    $sessionPath=$private.'/sess_'.$match[1];
    foreach (['pending_rename','retired','pending_drop','recovery_required','unexpected_phase','complete'] as $phase) {
        $old=file_get_contents($path); $seed=json_decode($old,true,64,JSON_THROW_ON_ERROR); $seed['replacement']['status']=$phase;
        $bytes=json_encode($seed,JSON_THROW_ON_ERROR); $session=file_get_contents($sessionPath);
        $before='wp_restore_state_hash|s:64:"'.hash('sha256',$old).'";'; $after='wp_restore_state_hash|s:64:"'.hash('sha256',$bytes).'";';
        check(substr_count($session,$before)===1,'exact fixture checkpoint authority located before terminal-state setup');
        file_put_contents($path,$bytes); file_put_contents($sessionPath,str_replace($before,$after,$session));
        [$status,$state]=request('wp_restore_state'); $run=$state['run']; $checkpoint=hash_file('sha256',$path);
        if (!isset($run['preflight']['replacement'])) echo 'REPLACEMENT_RED '.json_encode(['contract'=>'terminal-state','status'=>$status,'permanent_unchanged'=>oracle($pdo)===$permanent],JSON_THROW_ON_ERROR)."\n";
        check($status===200 && ($run['preflight']['replacement']['recovery_needed']??null)===($phase!=='complete'),
            'public state reports terminal uncertainty without treating preparation or retirement as completion');
        $expected=$phase==='complete'
            ? ['error'=>'Table replacement already completed. No database step was executed.','code'=>'replacement_complete']
            : ['error'=>'Table replacement is terminal or uncertain. Do not replay SQL; retain the verified backup and recover manually.','code'=>'replacement_recovery_required'];
        foreach (['files','sql','values','backup','backup_confirm','staging','assurance','replacement_prepare'] as $operation) {
            [$status,$body]=step($operation,$input);
            check($status===409 && $body===$expected,'terminal '.$phase.' refuses '.$operation.' without SQL replay');
            [$status,$state]=request('wp_restore_state');
            check($status===200 && $state['run']===$run && hash_file('sha256',$path)===$checkpoint && oracle($pdo)===$permanent,
                'terminal rejection preserves checkpoint counters and every permanent participant');
        }
        [$status,$download]=request('wp_restore_backup_download',['csrf'=>$GLOBALS['csrf'],'run_id'=>$run['run_id']],false);
        check($status===200 && hash('sha256',$download)===$proof['artifact_sha256'],'terminal checkpoint keeps authenticated artifact recovery available');
    }
}
