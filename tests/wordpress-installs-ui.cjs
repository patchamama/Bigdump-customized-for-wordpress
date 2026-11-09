const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync(process.argv[2], 'utf8');
const script = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]).find(t => t.includes("'installs_preview_open'"));
assert.ok(script, 'actual authenticated HTML contains the installations controller');
// Minimal DOM: only what the controller touches.
const node = (tag) => ({tag, children: [], style: {}, hidden: false, disabled: false, textContent: '', value: '', listeners: {},
  appendChild(c) { this.children.push(c); return c; }, replaceChildren(...c) { this.children = c; },
  insertRow() { const r = node('tr'); this.children.push(r); return r; }, add(o) { this.children.push(o); this.value ||= o.value; },
  addEventListener(e, l) { this.listeners[e] = l; }, focus() {}});
const ids = {};
const document = {getElementById: id => ids[id] ??= node('div'), createElement: tag => node(tag), addEventListener() {}};
const calls = [];
const installs = [{prefix: 'wp_', current: true, title: 'Main blog', url: 'https://a.example', theme: 't', plugins: 3, posts: 9, tables: 12, snapshot: false},
  {prefix: 'wp_20261109_', current: false, title: 'Main blog', url: 'https://a.example', theme: 't', plugins: 3, posts: 9, tables: 12, snapshot: true}];
const context = {document, window: {addEventListener() {}}, navigator: {sendBeacon() {}}, URLSearchParams, FormData: class { constructor() { this.m = new Map(); } append(k, v) { this.m.set(k, v); } },
  Option: class { constructor(text, value) { this.text = text; this.value = value; } }, Date,
  fetch: async (url, options = {}) => {
    const action = url.replace('?action=', ''); const f = options.body ? Object.fromEntries(options.body.m) : {};
    calls.push({action, f}); let data = {ok: true};
    if (action === 'installs_list') data = {ok: true, live: 'wp_', installs};
    if (action === 'installs_snapshot_start') data = {ok: true, new_prefix: 'wp_20261110_', tables: [{name: 'wp_options'}, {name: 'wp_posts'}]};
    if (action === 'installs_preview_open') data = {ok: true, folder: 'wpr_preview_' + 'a'.repeat(16), token: 'b'.repeat(32), url: 'https://a.example/wpr_preview_x/?cmspv=' + 'b'.repeat(32)};
    if (action === 'installs_preview_switch') data = {ok: true, url: 'https://a.example/wpr_preview_x/?cmspv=' + 'b'.repeat(32)};
    return {ok: true, json: async () => data};
  }};
(async () => {
  vm.runInNewContext(script, context);
  await new Promise(setImmediate);
  assert.equal(ids['installs-card'].hidden, false, 'the card appears once installations are listed');
  assert.match(ids['installs-summary'].textContent, /Installations detected: 2/);
  const rows = ids['installs-list'].children[0].children;
  assert.equal(rows.length, 3, 'header plus one row per installation');
  assert.match(rows[1].children[0].textContent, /^wp_ \(current\)$/);
  assert.match(rows[2].children[0].textContent, /\(snapshot\)$/);
  assert.equal(rows[1].children[1].textContent, 'Main blog', 'the blog title identifies each installation');
  rows[2].children[6].children[0].listeners.click();
  await new Promise(setImmediate);
  assert.deepEqual(calls.at(-1), {action: 'installs_preview_open', f: {prefix: 'wp_20261109_'}});
  assert.match(ids['installs-frame'].src, /cmspv=b{32}/);
  assert.equal(ids['installs-modal'].hidden, false);
  ids['installs-switch'].listeners.change({target: {value: 'wp_'}});
  await new Promise(setImmediate);
  assert.equal(calls.at(-1).action, 'installs_preview_switch', 'changing the selector reuses the same preview');
  assert.equal(calls.at(-1).f.prefix, 'wp_');
  await ids['installs-close'].listeners.click();
  assert.equal(calls.at(-1).action, 'installs_preview_close');
  assert.equal(ids['installs-modal'].hidden, true);
  await ids['installs-snapshot'].listeners.click();
  assert.deepEqual(calls.map(c => c.action).slice(-5), ['installs_snapshot_start', 'installs_snapshot_table', 'installs_snapshot_table', 'installs_snapshot_finish', 'installs_list']);
  assert.match(ids['installs-status'].textContent, /Snapshot created with prefix wp_20261110_/);
  console.log('PASS actual-HTML installations list, modal preview, live switch, close and snapshot flow');
})().catch(error => { console.error(error); process.exitCode = 1; });
