<?php
// Synthetic complete-column dumps. Expected frames are independent of runtime code.
function valueCell($value):array {
    return $value===null?['type'=>'null']:(is_int($value)?['type'=>'number','value'=>(string)$value]:['type'=>'bytes','base64'=>base64_encode($value)]);
}
function valueQuote(string $value):string {
    return "'".strtr($value,["\\"=>"\\\\","'"=>"\\'","\0"=>"\\0","\n"=>"\\n","\r"=>"\\r","\x1a"=>"\\Z"])."'";
}
function serializedString(string $value):string {return 's:'.strlen($value).':"'.$value.'";';}
function valuesFixture(string $case,string $target,string $marker):array {
    $source='https://old.example/root';$schemas=[];$rows=[];$expected=[];$sql="SET NAMES utf8mb4; SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $column=static fn($name,$type,$args=[])=>['name'=>$name,'type'=>$type,'args'=>$args,'attributes'=>[]];
    $names=['commentmeta','comments','links','options','postmeta','posts','termmeta','terms','term_relationships','term_taxonomy','usermeta','users'];
    foreach($names as$name){
        $columns=[$column('id','BIGINT'),$column('payload','LONGTEXT')];
        if($name==='options')$columns=[$column('option_id','BIGINT'),$column('option_name','VARCHAR',[191]),$column('option_value','LONGTEXT')];
        if($name==='posts')$columns=[$column('id','BIGINT'),$column('guid','VARCHAR',[255]),$column('payload','LONGTEXT')];
        if($name==='usermeta')$columns=[$column('user_id','BIGINT'),$column('meta_key','VARCHAR',[191]),$column('meta_value','LONGTEXT')];
        if($case==='missing-column'&&$name==='usermeta')$columns[1]['name']='wrong_key';
        $schemas['old_'.$name]=['kind'=>'create','table'=>'old_'.$name,'columns'=>$columns,'keys'=>[],'options'=>['ENGINE'=>'INNODB','CHARSET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']];
    }
    $schemas['old_posts']['columns'][1]['attributes']['default']=valueCell($source.'/?default=guid');
    $schemas['old_users']['columns'][1]['attributes']['default']=valueCell('DEFAULT_VALUE_SECRET');
    $schemas['old_options']['columns'][1]['attributes']['default']=valueCell('old_user_roles');
    $schemas['old_usermeta']['columns'][1]['attributes']['default']=valueCell('old_capabilities');
    if($case==='aggregate-growth'){
        $columns=[$column('id','BIGINT')];$choices=[];
        for($i=0;$i<256;$i++)$choices[]=valueCell($source.'/choice-'.$i);
        for($i=0;$i<64;$i++)$columns[]=$column('choice_'.$i,'ENUM',$choices);
        $schemas['old_bulk']=['kind'=>'create','table'=>'old_bulk','columns'=>$columns,'keys'=>[],'options'=>['ENGINE'=>'INNODB','CHARSET'=>'utf8mb4','COLLATE'=>'utf8mb4_unicode_ci']];
    }
    // A string default and ENUM values must migrate with the rows, without SQL editing.
    $schemas['old_links']['columns'][1]= $column('payload','ENUM',[valueCell($source),valueCell('other')]);
    $schemas['old_links']['columns'][1]['attributes']['default']=valueCell($source);
    if($case==='enum-collision')$schemas['old_links']['columns'][1]['args'][]=valueCell($target);
    $schemas['old_terms']['columns'][1]=$column('payload','SET',[valueCell($source.'/choice'),valueCell('other')]);
    $schemas['old_terms']['columns'][1]['attributes']['default']=valueCell($source.'/choice');
    if($case==='core-type')$schemas['old_options']['columns'][2]['type']='LONGBLOB';
    foreach($schemas as$schema){
        $parts=[];foreach($schema['columns']as$c){
            $args=$c['args']?'('.implode(',',array_map(fn($a)=>is_array($a)?valueQuote(base64_decode($a['base64'])):(string)$a,$c['args'])).')':'';
            $parts[]='`'.$c['name'].'` '.$c['type'].$args.(isset($c['attributes']['default'])?' DEFAULT '.valueQuote(base64_decode($c['attributes']['default']['base64'])):'');
        }
        $sql.='CREATE TABLE `'.$schema['table'].'` ('.implode(',',$parts).") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
    }
    $add=static function($table,$cells,$prepared=null)use(&$rows,&$expected,$schemas){
        $frame=['kind'=>'insert','table'=>'old_'.$table,'columns'=>array_column($schemas['old_'.$table]['columns'],'name'),'values'=>array_map(fn($v)=>is_array($v)?$v:valueCell($v),$cells)];$rows[]=$frame;
        if($prepared!==false){$frame['table']='wp_'.$table;if($prepared!==null)$frame['values']=array_map(fn($v)=>is_array($v)?$v:valueCell($v),$prepared);$expected[]=$frame;}
    };
    $add('options',[1,'siteurl',$source],[1,'siteurl',$target]);$add('options',[2,'home',$source],[2,'home',$target]);
    if($case!=='missing-plugins')$add('options',[3,'active_plugins',serialize(['example/plugin.php'])],[3,'active_plugins','a:0:{}']);
    if($case==='duplicate-plugins')$add('options',[33,'active_plugins',serialize(['second/plugin.php'])]);
    $add('options',[91,'time_format','g:i a']); // real WordPress default: letter+colon text is not serialized data
    $add('options',[4,'old_user_roles',serialize(['administrator'=>['capabilities'=>['manage_options'=>true]]])],[4,'wp_user_roles',serialize(['administrator'=>['capabilities'=>['manage_options'=>true]]])]);
    $add('options',[5,'_transient_widget','stale'],false);$add('options',[6,'_site_transient_timeout_update_plugins','stale'],false);
    $add('options',[7,'business_secret','keep user@old.example https://other.example/root '.$source.'-neighbor'],null);
    $add('usermeta',[1,'old_capabilities','a:1:{s:13:"administrator";b:1;}'],[1,'wp_capabilities','a:1:{s:13:"administrator";b:1;}']);
    $add('usermeta',[1,'old_user_level','10'],[1,'wp_user_level','10']);$add('usermeta',[1,'oldish_key','password-secret']);
    $text="UTF8 á雪\0 quo'te\" ".$source.'/image.jpg?x=1 '.$source.'?query '.$source.'#anchor https://old.example/rooted '.$source.'.jpg '.$source.';variant '.$source.':variant '.$source.'@variant '.$source.'!variant '.$source."'variant <a href='".$source."'>link</a> https://other.example/path/".$source.' email@old.example';
    $changed=str_replace([$source.'/image.jpg',$source.'?query',$source.'#anchor',"href='".$source."'"],[$target.'/image.jpg',$target.'?query',$target.'#anchor',"href='".$target."'"],$text);
    $nested=serializedString(serialize(['url'=>$source.'/nested','nul'=>"\0雪\"",'n'=>12]));
    $nestedNew=serializedString(serialize(['url'=>$target.'/nested','nul'=>"\0雪\"",'n'=>12]));
    $class='WordPressRestoreFixture\\WakeTrap';
    $object='O:'.strlen($class).':"'.$class.'":1:{'.serializedString("\0*\0url").serializedString($source.'/object').'}';
    $objectNew='O:'.strlen($class).':"'.$class.'":1:{'.serializedString("\0*\0url").serializedString($target.'/object').'}';
    $identity='a:5:{i:0;d:1.2300E+2;i:1;i:+001;i:2;N;i:3;'.serializedString($source.'/ref').'i:4;R:5;}';
    $identityNew=str_replace(serializedString($source.'/ref'),serializedString($target.'/ref'),$identity);
    $json='{"url":"https:\\/\\/old.example\\/root\\/json"}';$jsonNew='{"url":"'.str_replace('/','\\/',$target.'/json').'"}';
    foreach([[$text,$changed],[$nested,$nestedNew],[$object,$objectNew],[$identity,$identityNew],[$json,$jsonNew]]as$i=>$pair)$add('posts',[$i+1,$source.'/?p='.($i+1),$pair[0]],[$i+1,$source.'/?p='.($i+1),$pair[1]]);
    $add('links',[1,$source],[1,$target]);$add('terms',[1,$source.'/choice,other'],[1,$target.'/choice,other']);
    $spelling='a:02:{i:0;s:0003:"abc";i:1;b:0;}';$add('comments',[3,$spelling]);
    $graph='a:2:{i:0;'.$object.'i:1;r:2;}';$graphNew='a:2:{i:0;'.$objectNew.'i:1;r:2;}';$add('comments',[4,$graph], [4,$graphNew]);
    // serialize is used only on trusted synthetic fixture values, never backup input.
    $shared=$source.'/shared';$trusted=(object)['url'=>$source.'/shared-object'];$graph=[&$shared,&$shared,$trusted,$trusted,'tail'];$graph[]=&$graph[4];$before=serialize($graph);
    $shared=$target.'/shared';$trusted->url=$target.'/shared-object';$add('comments',[5,$before],[5,serialize($graph)]);
    foreach([['type'=>'null'],['type'=>'hex','value'=>'00FF27'],['type'=>'binary','base64'=>base64_encode("b\0\xff")],['type'=>'number','value'=>'-12.340e+2']]as$i=>$cell)$add('postmeta',[$i+1,$cell]);
    $add('comments',[1,"<?php file_put_contents(".var_export($marker,true).",'bad'); ?>"]);
    if($case==='key-collision')$add('options',[8,'wp_user_roles','must not overwrite']);
    if($case==='casefold-collision')$add('options',[8,'WP_user_roles','must not overwrite']);
    if($case==='nonascii-key')$add('options',[8,'business_雪','preserve offline']);
    if($case==='meta-collision')$add('usermeta',[1,'wp_capabilities','must not overwrite']);
    if($case==='array-collision')$add('comments',[2,'a:2:{'.serializedString($source). 'i:1;'.serializedString($target).'i:2;}']);
    if($case==='malformed')$add('comments',[2,'a:1:{s:3:"url";s:999:"'.$source.'";}']);
    if($case==='dangling-ref')$add('comments',[2,'a:1:{i:0;R:999;}']);
    if($case==='wrong-object-ref')$add('comments',[2,'a:1:{i:0;r:1;}']);
    if($case==='unknown-token')$add('comments',[2,'S:24:"'.$source.'";']);
    if($case==='custom'||$case==='opaque'){
        $payload=$case==='custom'?$source:'opaque payload';$add('comments',[2,'C:4:"Trap":'.strlen($payload).':{'.$payload.'}']);
    }
    if($case==='depth')$add('comments',[2,str_repeat('a:1:{i:0;',40).serializedString($source).str_repeat('}',40)]);
    if($case==='depth-ok'){$v=str_repeat('a:1:{i:0;',24).serializedString($source).str_repeat('}',24);$new=str_repeat('a:1:{i:0;',24).serializedString($target).str_repeat('}',24);$add('comments',[2,$v],[2,$new]);}
    if($case==='nodes'||$case==='nodes-ok'){$n=$case==='nodes'?10000:9800;$v='a:'.$n.':{';for($i=0;$i<$n;$i++)$v.='i:'.$i.';N;';$add('comments',[2,$v.'}']);}
    if($case==='growth')$add('comments',[2,str_repeat($source.'/ ',200000)]);
    if($case==='column-size')$add('options',[8,'small',str_repeat($source.'/',8)]); // Replaced below with a bounded VARCHAR column.
    if($case==='column-size'){
        // A plugin table makes changed character capacity uncertain/too small.
        $sql.="CREATE TABLE `old_small` (`id` BIGINT, `payload` VARCHAR(32)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
        $sql.='INSERT INTO `old_small` VALUES (1,'.valueQuote($source.'/1234567').");\n";
    }
    if($case==='output-tamper')for($i=0;$i<100;$i++)$add('comments',[$i+10,$source.'/chunk-'.$i],[$i+10,$target.'/chunk-'.$i]);
    foreach($rows as$r){$literals=[];foreach($r['values']as$v)$literals[]=match($v['type']){'null'=>'NULL','number'=>$v['value'],'hex'=>'0x'.$v['value'],'binary'=>'_binary'.valueQuote(base64_decode($v['base64'])),'bytes'=>valueQuote(base64_decode($v['base64']))};$sql.='INSERT INTO `'.$r['table'].'` VALUES ('.implode(',',$literals).");\n";}
    $preparedSchemas=[];foreach($schemas as$s){$s['table']='wp_'.substr($s['table'],4);if(in_array($s['table'],['wp_links','wp_terms'],true)){$value=$target.($s['table']==='wp_terms'?'/choice':'');$s['columns'][1]['args'][0]=valueCell($value);$s['columns'][1]['attributes']['default']=valueCell($value);}if($s['table']==='wp_options')$s['columns'][1]['attributes']['default']=valueCell('wp_user_roles');if($s['table']==='wp_usermeta')$s['columns'][1]['attributes']['default']=valueCell('wp_capabilities');$preparedSchemas[$s['table']]=$s;}
    return [$sql,$expected,$preparedSchemas];
}
