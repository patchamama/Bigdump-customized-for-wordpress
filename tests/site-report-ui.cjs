// Execute the actual inline report controller without third-party dependencies.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('cms_tools.php', 'utf8');
const script = source.match(/<script>\s*(let generatedSiteReport = null;[\s\S]*?)<\/script>/)[1];
const fields = {};
for (const id of ['report_generate','report_download','report_send','report_status','report_output','report_github_status','report_github_form','report_token']) {
    fields[id] = {disabled:false, textContent:'', value:'', dataset:{busy:'Busy', error:'Failed', success:'Saved', confirm:'Confirm'}};
}
fields.report_github_form.reportValidity = () => true;
let calls = [], confirm = true, failure = false, reportDomain = 'wp.example.test', downloaded;
const context = {
    document:{getElementById:id=>fields[id], createElement:()=>({click(){ downloaded = this.download; }})},
    Blob, URL:{createObjectURL:()=> 'blob:synthetic', revokeObjectURL(){}}, setTimeout:fn=>fn(),
    confirm:()=>confirm,
    FormData:class extends Map { constructor() { super([['token',fields.report_token.value],['repository','https://github.com/example/private'],['branch','wordpress-report']]); } },
    fetch:async (url, options)=>{
        calls.push({url, options});
        if (url.includes('github')) return {ok:!failure, json:async()=>failure ? {ok:false,error:'No permission'} : {ok:true,filename:'wp.example.test.json',branch:'wordpress-report'}};
        return {ok:true, headers:{get:name=>name.endsWith('Id') ? 'id' : 'csrf'}, json:async()=>({schema:'cms-tools-site-report',site:{domain:reportDomain},summary:{critical:1,warning:2,ok:3,unavailable:4},filename:'wp.example.test.json',generated_at:'fixed-time'})};
    }
};
vm.createContext(context);
vm.runInContext(script,context);
(async()=>{
    assert.equal(calls.length,0,'opening tab must not generate or send');
    await context.generateSiteReport();
    assert.equal(calls.length,1);
    assert.equal(fields.report_send.disabled,false);
    const displayed = fields.report_output.textContent;
    context.downloadSiteReport();
    assert.equal(calls.length,1,'download does not regenerate');
    assert.equal(downloaded,'wp.example.test.json');
    assert.match(displayed,/fixed-time/);
    fields.report_token.value='synthetic'; confirm=false;
    await context.sendSiteReportGitHub();
    assert.equal(calls.length,1,'cancel sends nothing');
    confirm=true;
    await context.sendSiteReportGitHub();
    assert.equal(calls[1].options.body.get('report_id'),'id');
    assert.equal(calls[1].options.body.get('csrf'),'csrf');
    assert.equal(calls[1].options.body.get('token'),'synthetic');
    assert.equal(fields.report_token.value,'');
    assert.match(fields.report_github_status.textContent,/Saved wordpress-report\/wp.example.test.json/);
    failure=true; fields.report_token.value='synthetic';
    await context.sendSiteReportGitHub();
    assert.equal(fields.report_token.value,'','clear token on failure too');
    assert.match(fields.report_github_status.textContent,/Failed No permission/);
    assert.equal(fields.report_generate.disabled,false);
    reportDomain=null;
    await context.generateSiteReport();
    assert.equal(fields.report_send.disabled,true,'unknown domain cannot publish');
    console.log('PASS report UI: explicit actions, exact download, confirmation, snapshot binding, token clearing and unavailable domain');
})().catch(error=>{console.error(error);process.exitCode=1;});
