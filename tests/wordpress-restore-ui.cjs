const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(process.argv[2], 'utf8');
const script = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]).find(text => text.includes("getElementById('wp-restore-offer')"));
assert.ok(script, 'actual authenticated HTML contains restore controller');
const elements = Object.fromEntries(['offer','prepare','status','archive','database','target'].map(name => [
  'wp-restore-' + name,
  {hidden: true, disabled: false, value: '', textContent: '', options: [],
    add(option) { this.options.push(option); this.value ||= option.value; },
    addEventListener(event, listener) { this[event] = listener; }}
]));
const calls = [];
const context = {
  document: {getElementById: id => elements[id]},
  window: {location: {href: 'https://destination.example/restore/migrate.php'}},
  URL, URLSearchParams,
  Option: class { constructor(text, value) { this.text = text; this.value = value; } },
  fetch: async (url, options) => {
    calls.push({url, method: options.method || 'GET', body: options.body?.toString()});
    const data = url.endsWith('wp_restore_discover')
      ? {eligible: true, can_prepare: true, csrf: 'independent-restore-csrf',
          archives: ['hosting_fixture.zip'], databases: ['db_fixture.sql.gz']}
      : url.endsWith('wp_restore_state') ? {run: null} : {run: {stage: 'preflight'}};
    return {ok: true, json: async () => data};
  }
};
(async () => {
  vm.runInNewContext(script, context);
  await new Promise(setImmediate);
  assert.equal(elements['wp-restore-offer'].hidden, false);
  assert.deepEqual(calls.map(call => call.method), ['GET', 'GET'], 'automatic offer never starts mutation');
  assert.equal(elements['wp-restore-archive'].value, 'hosting_fixture.zip');
  assert.equal(elements['wp-restore-database'].value, 'db_fixture.sql.gz');
  assert.equal(elements['wp-restore-target'].value, 'https://destination.example/restore');
  await elements['wp-restore-prepare'].click();
  await elements['wp-restore-prepare'].click();
  assert.equal(calls.length, 3, 'duplicate clicks do not create another request');
  assert.equal(calls[2].method, 'POST');
  const sent = new URLSearchParams(calls[2].body);
  assert.equal(sent.get('csrf'), 'independent-restore-csrf');
  assert.equal(sent.get('archive'), 'hosting_fixture.zip');
  assert.equal(sent.get('database'), 'db_fixture.sql.gz');
  assert.equal(sent.get('target_url'), 'https://destination.example/restore');
  assert.equal(elements['wp-restore-prepare'].disabled, true);
  assert.match(elements['wp-restore-status'].textContent, /No files or tables have been replaced/);
  console.log('PASS actual-HTML automatic restore offer, explicit preparation, scoped CSRF, target URL and duplicate prevention');
})().catch(error => { console.error(error); process.exitCode = 1; });
