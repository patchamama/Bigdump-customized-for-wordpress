// Execute the production controller, not a reimplementation.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('cms_tools.php', 'utf8');
const start = source.indexOf('let cmsInstallBusy = false;');
const controller = source.slice(start, source.indexOf('</script>', start));
const elements = Object.fromEntries(['prepare','resume','cms','destination','csrf','status'].map(id => ['install_'+id, {value:'',disabled:false,textContent:'',dataset:{ready:'owned',empty:'empty',error:'safe failure'}}]));
elements.install_cms.value='wordpress'; elements.install_destination.value='sandbox'; elements.install_csrf.value='csrf';
let requests = [], response, pending;
const context = vm.createContext({URLSearchParams, document:{getElementById:id=>elements[id]}, fetch:(url,options)=>{
    requests.push({url,options}); return new Promise(resolve=>{pending=()=>resolve(response);});
}});
vm.runInContext(controller, context);
(async()=>{
    response={ok:false,json:async()=>({error:'<img src=x onerror=bad()>'})};
    let work=context.cmsInstallDraft('prepare'); await context.cmsInstallDraft('prepare');
    assert.equal(requests.length,1); assert.equal(elements.install_prepare.disabled,true);
    pending(); await work;
    assert.equal(elements.install_status.textContent,'safe failure'); assert.equal(elements.install_prepare.disabled,false);
    response={ok:true,json:async()=>({draft:{cms:'wordpress',destination:'sandbox',stage:'awaiting_database_validation'}})};
    work=context.cmsInstallDraft('prepare'); pending(); await work;
    assert.equal(requests[1].url,'?action=cms_install_prepare');
    assert.equal(requests[1].options.body.get('csrf'),'csrf'); assert.equal(requests[1].options.method,'POST');
    assert.equal(requests[1].options.cache,'no-store'); assert.equal(elements.install_status.textContent,'owned');
    assert.equal(elements.install_prepare.disabled,true); assert.equal(elements.install_resume.disabled,false);
    work=context.cmsInstallDraft('state'); pending(); await work;
    assert.equal(requests[2].url,'?action=cms_install_state'); assert.equal(requests[2].options.method,'GET');
    response={ok:true,json:async()=>({draft:{cms:'<script>',destination:'sandbox',stage:'awaiting_database_validation'}})};
    work=context.cmsInstallDraft('state'); pending(); await work;
    assert.equal(elements.install_status.textContent,'safe failure'); assert.equal(elements.install_resume.disabled,false);
    assert.ok(!/innerHTML|localStorage|sessionStorage|document\.cookie/.test(controller));
    assert.ok(source.includes('id="tab-install"')); assert.ok(!/id="install_[^"]*(pass|credential)/.test(source));
    console.log('PASS actual draft controller: CSRF, duplicate suppression, safe errors, resume, controls restored, no credentials/storage');
})().catch(error=>{console.error(error);process.exitCode=1;});
