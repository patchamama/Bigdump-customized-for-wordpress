const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(process.argv[2], 'utf8');
const script = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)]
  .map(match => match[1]).find(text => text.includes('window.wpRestoreWizard = '));
assert.ok(script, 'actual authenticated HTML contains the restoration wizard controller');
const make = () => ({hidden: true, disabled: false, checked: false, textContent: '', listeners: {},
  addEventListener(event, listener) { this.listeners[event] = listener; }});
const elements = Object.fromEntries(['wizard', 'progress', 'download', 'ack-row', 'ack', 'ack-text', 'next'].map(n => ['wp-restore-' + n, make()]));
// Simulated server: each operation advances the public state like the real handlers.
const run = {run_id: 'r', stage: 'preflight', revision: 0, backup_verified: false, runtime_verified: false,
  preflight: {files: {ready: false}, sql: {ready: false}}};
const ops = [], bodies = [];
let failTrialOnce = true;
const advance = {
  files: () => { run.preflight.files.ready = true; },
  sql: () => { run.preflight.sql.ready = true; },
  values: () => { run.preflight.values = {ready: true}; },
  backup: () => { run.preflight.backup = {ready: true, sha256: 'a'.repeat(64)}; },
  backup_confirm: () => { run.backup_verified = true; },
  staging: () => { run.preflight.staging = {status: 'ready'}; },
  assurance: () => { run.preflight.assurance = {status: 'compared_as_of'}; },
  replacement_prepare: () => { run.preflight.replacement = {status: 'prepared'}; },
  replacement: () => { run.preflight.replacement = {status: 'complete'}; },
  placement_trial: () => { run.preflight.placement = {status: 'trial_ready'}; },
  placement_promote: () => { run.preflight.placement = {status: 'promoted'}; },
};
const context = {
  document: {getElementById: id => elements[id], createElement: () => ({click() {}})},
  window: {}, URL: Object.assign(URL, {createObjectURL: () => 'blob:x'}), URLSearchParams,
  fetch: async (url, options = {}) => {
    const action = url.replace('?action=', '');
    let data;
    if (action === 'wp_restore_discover') data = {csrf: 'csrf-token'};
    else if (action === 'wp_restore_state') data = {run: JSON.parse(JSON.stringify(run))};
    else if (action === 'wp_restore_state&manifest=values') data = {run: {values_manifest: {plan_sha256: 'b'.repeat(64)}}};
    else if (action === 'wp_restore_step') {
      const body = new URLSearchParams(options.body.toString()); bodies.push(body);
      assert.equal(body.get('csrf'), 'csrf-token'); assert.equal(body.get('revision'), String(run.revision));
      if (body.get('operation') === 'placement_trial' && failTrialOnce) { failTrialOnce = false; return {ok: false, json: async () => ({error: 'transient'})}; }
      ops.push(body.get('operation')); advance[body.get('operation')](); run.revision++;
      data = {run: JSON.parse(JSON.stringify(run))};
    } else throw new Error('unexpected ' + action);
    return {ok: true, json: async () => data};
  },
};
(async () => {
  vm.runInNewContext(script, context);
  await new Promise(setImmediate);
  const next = elements['wp-restore-next'], ack = elements['wp-restore-ack'];
  const click = async () => { await next.listeners.click(); await new Promise(setImmediate); };
  await click();
  assert.deepEqual(ops, ['files', 'sql', 'values', 'backup'], 'private preparation and recovery capture run automatically');
  assert.equal(elements['wp-restore-download'].hidden, false, 'recovery copy download is offered at the backup gate');
  await click();
  assert.equal(ops.length, 4, 'no confirmation step without the explicit tick');
  ack.checked = true; await click();
  assert.deepEqual(ops.slice(4), ['backup_confirm'], 'backup declaration stops at the next distinct gate');
  assert.equal(ack.checked, false, 'a new confirmation needs a fresh tick');
  const confirm = bodies[4];
  assert.equal(confirm.get('recovery_method'), 'separate_database_schema_rows_counters');
  ack.checked = true; await click();
  assert.deepEqual(ops.slice(5), ['staging', 'assurance'], 'staging and read-only comparison follow one non-destructive confirmation');
  assert.equal(ack.checked, false);
  await click();
  assert.ok(!ops.includes('replacement_prepare'), 'destructive replacement never starts without its own tick');
  ack.checked = true; await click();
  assert.deepEqual(ops.slice(7), ['replacement_prepare', 'replacement'], 'replacement completes, then the transient trial error stops the loop');
  assert.match(elements['wp-restore-progress'].textContent, /transient/);
  await click();
  assert.deepEqual(ops.slice(9), ['placement_trial'], 'retry after an error places only into the trial folder');
  assert.ok(!ops.includes('placement_promote'), 'a tick from an earlier gate never authorizes promotion');
  const replace = bodies[7];
  assert.equal(replace.get('scope_confirmed'), 'exact_original_old_set');
  assert.equal(ack.checked, false);
  await click();
  assert.ok(!ops.includes('placement_promote'), 'promotion into the site root needs a separate confirmation');
  ack.checked = true; await click();
  assert.equal(ops.at(-1), 'placement_promote');
  assert.equal(bodies.at(-1).get('scope_confirmed'), 'trial_to_root');
  assert.match(elements['wp-restore-progress'].textContent, /NOT verified/);
  assert.equal(next.hidden, true, 'finished wizard offers no further step');
  console.log('PASS actual-HTML restoration wizard order, confirmations, scopes and truthful final message');
})().catch(error => { console.error(error); process.exitCode = 1; });
