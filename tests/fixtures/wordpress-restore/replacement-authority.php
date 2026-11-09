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
