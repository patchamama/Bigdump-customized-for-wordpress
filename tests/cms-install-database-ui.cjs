const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('cms_tools.php', 'utf8');
const start = source.indexOf('let cmsDatabaseBusy = false;');
assert.ok(start > 0, 'database controller exists');
const script = source.slice(start, source.indexOf('</script>', start));
const nodes = Object.fromEntries(['check','host','port','database','user','password','engine','namespace','status'].map(id => ['db_'+id,{value:'',disabled:false,textContent:'',dataset:{error:'safe failure',unknown:'Unknown',collision:'Visible collision: reuse/copy unavailable',absent:'Not observed in visible metadata only'}}]));
nodes.install_csrf = {value:'independent-csrf'};
for (const [key,value] of Object.entries({host:'127.0.0.1',database:'fixturedb',user:'dbuser',password:'SECRET',engine:'mysql',namespace:''})) nodes['db_'+key].value=value;
let requests=[], response, release;
const ctx = vm.createContext({URLSearchParams, document:{getElementById:id=>nodes[id]}, fetch:(url,options)=>{
    requests.push({url,options,sentBody:new URLSearchParams(options.body)}); return new Promise(resolve=>{release=()=>resolve(response);});
}, cmsInstallRun:'owned-run'});
vm.runInContext(script,ctx);
(async()=>{
    response={ok:false,json:async()=>({error:'SECRET <img>'})};
    let work=ctx.cmsInstallDatabase(); await ctx.cmsInstallDatabase();
    assert.equal(requests.length,1); assert.equal(nodes.db_password.value,''); assert.equal(nodes.db_user.value,'');
    release(); await work; assert.equal(nodes.db_status.textContent,'safe failure');
    assert.equal(requests[0].url,'?action=cms_install_database');
    assert.equal(requests[0].sentBody.get('password'),'SECRET');
    assert.equal(requests[0].sentBody.get('user'),'dbuser');
    assert.equal(requests[0].options.body.get('password'),null);
    assert.equal(requests[0].options.body.get('user'),null);
    assert.equal(requests[0].options.body.get('run_id'),'owned-run');
    assert.equal(requests[0].options.body.get('csrf'),'independent-csrf');
    assert.equal(requests[0].options.cache,'no-store');
    for (const state of ['collision','not-observed','unknown']) {
        response={ok:true,json:async()=>({evidence:{namespace:'cms_safe_',namespace_status:state,observed_engine:'mysql',observed_version:'8.4.2'}})};
        work=ctx.cmsInstallDatabase(); release(); await work;
        assert.equal(nodes.db_namespace.value,'cms_safe_');
        assert.ok(!nodes.db_status.textContent.includes('SECRET'));
        assert.equal(nodes.db_check.disabled,false);
    }
    assert.ok(!/innerHTML|localStorage|sessionStorage|document\.cookie/.test(script));
    assert.ok(source.includes('id="db_password" type="password" autocomplete="off"'));
    assert.ok(source.includes('Comprobar BD local'));
    console.log('PASS database controller: owned run/CSRF, transient credentials, immediate clearing, safe text, duplicate guard, evidence only');
})().catch(error=>{console.error(error);process.exitCode=1;});
