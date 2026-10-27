// Execute the real controller using a synthetic DOM/transport, never a browser or site.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../cms_tools.php'), 'utf8');
const start = source.indexOf('async function upgradeAdmission(event)');
const end = source.indexOf('const T_UPGRADE =', start);
assert.ok(start > 0 && end > start, 'upgrade controller exists');
const controller = source.slice(start, end);
assert.ok(!/sandbox_create|wp_install|teBuild|localStorage|innerHTML/.test(controller), 'no legacy mutation, persistent credentials or HTML injection');
const id = 'a'.repeat(24);
const payload = (stage, extra={}) => ({ok:true, run_id:id, stage, ready:stage==='done', progress:`Stage: ${stage}`, findings:[], recommendation:'Review smoke results; manual migration only.', ...extra});
const labels = {docker:'Run locally: bash docker/wordpress-upgrade/run.sh --help; explicit backup and SQL required; never submit production.',checking:'Preparing staging run', notReady:'Not ready for recommendation', passed:'Required smoke checks passed; manual review remains', uncertain:'Request uncertain; staging resources may already exist. Do not automatically retry.', scope:'Smoke checks are not comprehensive compatibility.'};
async function run({valid=true, disabled=false, mode='new', platform='separate-host', rejectAt=-1, malformedAt=-1, replies=[payload('download'),payload('plugins'),payload('done')]}={}) {
    const requests=[]; let prevented=false;
    const elements={platform:{value:platform},upgrade_csrf:{value:'csrf-value'},backup:{value:'yes'},separate_host:{value:'yes'},mode:{value:mode},run_id:{value:mode==='reuse'?id:''}};
    for (const name of ['target_host','target_name','target_user','target_password']) elements[name]={value:name==='target_password'?'TARGET_SECRET':'fixture',disabled:mode==='reuse',required:mode!=='reuse'};
    const form={reportValidity:()=>valid,elements};
    const button={disabled}; const output={textContent:''};
    class FormData {
        constructor(input) { this.data=new Map(input?Object.entries(input.elements).filter(([,el])=>!el.disabled).map(([key,el])=>[key,el.value]):[]); }
        get(key){return this.data.get(key);} set(key,value){this.data.set(key,value);} delete(key){this.data.delete(key);}
    }
    const sandbox={FormData,T_UPGRADE:labels,document:{getElementById:key=>({upgrade_form:form,upgrade_button:button,upgrade_result:output}[key])},
        fetch:async(url,options)=>{
            const n=requests.length;
            requests.push({url,options,snapshot:new Map(options.body.data)});
            assert.equal(options.method,'POST'); assert.equal(options.credentials,'same-origin');
            assert.equal(button.disabled,true,'duplicate submission blocked throughout run');
            assert.equal(options.body.get('csrf'),'csrf-value');
            assert.equal(options.body.get('upgrade_csrf'),undefined);
            if(n>0 || mode==='reuse') assert.equal(options.body.get('target_password'),undefined,'credentials only in new prepare request');
            if(n>0) {assert.equal(form.elements.target_password.value,'','input cleared before first step');assert.equal(options.body.get('run_id'),id);}
            if(n===rejectAt) throw Error('SECRET transport detail');
            const result=replies[n];
            assert.ok(result,'no unexpected request/retry');
            return {ok:result.ok!==false,status:result.ok===false?409:200,json:async()=>{if(n===malformedAt)throw Error('SECRET JSON detail');return result;}};
        }};
    vm.createContext(sandbox); vm.runInContext(controller,sandbox);
    await sandbox.upgradeAdmission({preventDefault:()=>{prevented=true;}});
    assert.equal(prevented,true);
    if(!valid || disabled || platform==='docker'){assert.equal(requests.length,0);return {requests,output};}
    assert.equal(button.disabled,false);
    assert.equal(elements.target_password.value,'');
    for(const request of requests) assert.equal(request.options.body.get('target_password'),undefined,'held request credentials discarded');
    assert.ok(!output.textContent.includes('SECRET'));
    return {requests,output,form};
}
(async()=>{
    const docker=await run({platform:'docker',valid:false});
    assert.equal(docker.requests.length,0,'Docker selection never submits production or executes plugins');
    assert.equal(docker.output.textContent,labels.docker,'Docker option presents local CLI instructions before staging validation');
    const success=await run();
    assert.deepEqual(success.requests.map(x=>x.url),['?action=upgrade_prepare','?action=upgrade_step','?action=upgrade_step'],'controller must drive prepared stages to completion');
    assert.equal(success.requests[0].snapshot.get('target_password'),'TARGET_SECRET');
    assert.equal(success.requests[0].snapshot.get('run_id'),undefined,'new preparation cannot accidentally reuse an old id');
    assert.ok(success.output.textContent.includes(labels.passed));
    assert.ok(success.output.textContent.includes(labels.scope));
    assert.equal(success.form.elements.run_id.value,id);
    const reuse=await run({mode:'reuse'});
    assert.equal(reuse.requests[0].snapshot.get('run_id'),id);
    assert.ok(reuse.output.textContent.includes(labels.passed));
    await run({valid:false}); await run({disabled:true});
    for(const params of [{rejectAt:0},{malformedAt:0},{replies:[{ok:true,ready:true}]}]) {
        const result=await run(params);assert.equal(result.requests.length,1);assert.ok(!result.output.textContent.includes(labels.passed));assert.ok(result.output.textContent.includes(labels.uncertain));
    }
    const untrusted=await run({replies:[{ok:false,error:'<script>untrusted error</script>'}]});
    assert.equal(untrusted.requests.length,1);assert.ok(untrusted.output.textContent.includes('<script>untrusted error</script>'),'error rendered as plain text');
    const partial=await run({replies:[payload('download'),{ok:false,error:'Stage failed',run:payload('failed',{ready:false})}]});
    assert.equal(partial.requests.length,2);assert.ok(partial.output.textContent.includes(labels.notReady));assert.ok(partial.output.textContent.includes(id),'retain existing run identity on failure');
    const unknown=await run({replies:[payload('done',{ready:false,findings:[{severity:'unavailable',tip:'Check real host rules manually'}]})]});
    assert.equal(unknown.requests.length,1);assert.ok(!unknown.output.textContent.includes(labels.passed));assert.ok(unknown.output.textContent.includes('Check real host rules manually'));
    const failClosed=await run({replies:[payload('done',{ready:true,findings:[{severity:'failed',tip:'Restore missing assets'}]})]});
    assert.ok(!failClosed.output.textContent.includes(labels.passed),'failed findings never become all-clear even with contradictory ready flag');
    console.log('All WordPress staging upgrade controller tests passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
