// Exercise the actual production controller without browser storage or network.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('cms_tools.php', 'utf8');
const start = source.indexOf('async function manageReportApi(');
const controller = source.slice(start, source.indexOf('</script>', start));
const elements = Object.fromEntries(['report_api_url', 'report_api_copy', 'report_api_status', 'report_api_csrf'].map(id => [id, {value: '', disabled: false, textContent: '', dataset: {error: 'safe error'}}]));
elements.report_api_csrf.value = 'csrf-binding';
let listener, request, copied, fail = false;
const context = vm.createContext({URLSearchParams, document: {getElementById: id => elements[id]}, window: {addEventListener: (_, fn) => {listener = fn;}}, navigator: {clipboard: {writeText: async value => {copied = value;}}}, fetch: async (url, options) => {
    request = {url, options};
    return {ok: !fail, json: async () => ({url: 'https://example.test/nested/cms_tool.php?action=site_report_api&token=PLACEHOLDER'})};
}});
vm.runInContext(controller, context);
(async () => {
    await context.manageReportApi('rotate');
    assert.equal(request.url, '?action=site_report_token');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.cache, 'no-store');
    assert.equal(request.options.body.get('csrf'), 'csrf-binding');
    assert.equal(request.options.body.get('operation'), 'rotate');
    assert.ok(elements.report_api_url.value.includes('PLACEHOLDER'));
    await context.copyReportApiUrl();
    assert.ok(copied.includes('PLACEHOLDER'));
    assert.equal(elements.report_api_url.value, '');
    assert.equal(elements.report_api_copy.disabled, true);
    await context.manageReportApi('rotate'); listener();
    assert.equal(elements.report_api_url.value, '');
    fail = true; await context.manageReportApi('revoke');
    assert.equal(elements.report_api_url.value, '');
    assert.equal(elements.report_api_status.textContent, 'safe error');
    assert.ok(!/localStorage|sessionStorage|document\.cookie/.test(controller));
    console.log('PASS actual API controller: explicit CSRF POST, one-time copy, pagehide clearing, safe failures, no storage');
})().catch(error => {console.error(error); process.exitCode = 1;});
