#!/usr/bin/env bash
set -euo pipefail
# Only synthetic fixtures and a fake engine. Never contacts a Docker daemon.
python3 - <<'PY'
import pathlib, subprocess, tempfile, os, json, importlib.util, tarfile, hashlib, sys
sys.dont_write_bytecode=True
repo=pathlib.Path.cwd()
runner=repo/'docker/wordpress-upgrade/run.sh'
apache=(repo/'docker/wordpress-upgrade/apache.conf').read_text()
# Structural policy assertion; the real public CLI separately proves HTTP behavior.
assert 'Options -Indexes -FollowSymLinks +SymLinksIfOwnerMatch' in apache, 'Apache must allow owner-matched rewrite without enabling FollowSymLinks'
assert 'Require all denied' in apache and 'wp-config' in apache and '.migrate_config' in apache, 'Private file denial must remain'
scratch_base=repo/'.atl/updater-integration-proof/tmp'
scratch_base.mkdir(parents=True,exist_ok=True)
with tempfile.TemporaryDirectory(prefix='cms-docker-cli-',dir=scratch_base) as scratch:
    root=pathlib.Path(scratch); (root/'bin').mkdir(); (root/'backup').mkdir()
    (root/'backup/wp-config.php').write_text('<?php /* PRODUCTION_SECRET */')
    (root/'backup/.migrate_config').write_text('PRODUCTION_SECRET')
    (root/'backup/wp-content').mkdir(); (root/'backup/wp-content/plugin.php').write_text('<?php // synthetic')
    (root/'seed.sql').write_text('CREATE TABLE `wp_options` (`option_name` varchar(191), `option_value` text) ENGINE=InnoDB;')
    fake=repo/'tests/fixtures/wordpress-upgrade/docker'
    (root/'bin/docker').write_bytes(fake.read_bytes()); (root/'bin/docker').chmod(0o700)
    env={**os.environ,'PATH':str(root/'bin')+os.pathsep+os.environ['PATH'],'FAKE_STATE':str(root/'state.json'),'TMPDIR':scratch}
    for key in list(env):
        if key.startswith('DOCKER_'): del env[key]
    def run(*args, extra=None):
        return subprocess.run(['bash',str(runner),*args],env={**env,**(extra or {})},text=True,capture_output=True,timeout=20)
    help=run('--help'); assert help.returncode==0 and help.stderr=='' and 'backup' in help.stdout and 'offline' in help.stdout and 'source_rules_omitted' in help.stdout
    args=['--backup',str(root/'backup'),'--sql',str(root/'seed.sql'),'--production-url','https://production.invalid','--prefix','wp_','--image','sha256:'+'a'*64]
    bad=run(*args,extra={'DOCKER_HOST':'tcp://remote.invalid:2375'})
    assert bad.returncode!=0 and 'PRODUCTION_SECRET' not in bad.stdout+bad.stderr and not (root/'state.json').exists(),bad
    (root/'backup/unsafe').symlink_to(root/'seed.sql')
    bad=run(*args); assert bad.returncode!=0 and not (root/'state.json').exists(),bad
    (root/'backup/unsafe').unlink()
    (root/'backup/.htaccess').write_text('RewriteEngine On')
    bad=run(*args); assert bad.returncode!=0 and not (root/'state.json').exists(),bad
    (root/'backup/.htaccess').unlink()
    good_sql=(root/'seed.sql').read_text()
    for sql in ['SOURCE /secret;', 'USE production;', 'INSERT INTO `wp_options` SELECT * FROM mysql.user;']:
        (root/'seed.sql').write_text(sql)
        bad=run(*args); assert bad.returncode!=0 and not (root/'state.json').exists(),bad
    (root/'seed.sql').write_text(good_sql)
    for scenario in ['remote','unsafe-inspect','huge-inspect','bind-mount','published-port','host-pid','privileged','rootfs-write','extra-network','wrong-label']:
        bad=run(*args,extra={'FAKE_SCENARIO':scenario}); assert bad.returncode!=0 and 'PRODUCTION_SECRET' not in bad.stdout+bad.stderr,bad
        state=json.loads((root/'state.json').read_text()); assert not any(c[:2]==['exec','--user'] for c in state['calls']),state
        (root/'state.json').unlink()
    for name in ['Inter-VariableFont_slnt,wght.woff2','akismet-refresh-logo@2x.png']:
        (root/'backup/wp-content'/name).write_bytes(b'SYNTHETIC WORDPRESS RESOURCE')
    plain=run(*args); assert plain.returncode==0 and plain.stderr=='',(plain.stdout,plain.stderr)
    plain_report=json.loads(plain.stdout); assert 'source_rules_omitted' not in plain_report
    cleaned=run('--cleanup',plain_report['owner']); assert cleaned.returncode==0 and cleaned.stderr==''
    (root/'state.json').unlink()
    rules=(repo/'tests/fixtures/wordpress-upgrade/akismet-default-6.8.3.htaccess').read_bytes()
    assert hashlib.sha256(rules).hexdigest()=='6d8db93cbee3b408b9b8972684d20f33d739efd11d32ba584d6df272a7873011'
    rule_path='wp-content/plugins/akismet/.htaccess'
    source_rule=root/'backup'/rule_path; source_rule.parent.mkdir(parents=True)
    # Every refusal happens before a single fake-engine call, with exact CLI output.
    def rejected():
        bad=run(*args)
        report=json.loads(bad.stdout); owner=report.pop('owner')
        assert len(owner)==32 and all(c in '0123456789abcdef' for c in owner)
        assert bad.returncode==1 and bad.stderr=='' and bad.stdout==json.dumps({**report,'owner':owner})+'\n',bad
        assert report=={'ok':False,'ready':False,'error':'Docker testing failed closed. Review prerequisites; no automatic retry or repair.','resources':[]},report
        assert not (root/'state.json').exists()
    for path,body in [(source_rule,rules+b'# modified'),(source_rule,b'x'*65537),
                      (source_rule.with_name('.HTACCESS'),rules),
                      (root/'backup/wp-content/.htaccess',rules),
                      (source_rule.with_name('.user.ini'),rules),
                      (source_rule.with_name('php.ini'),rules)]:
        path.write_bytes(body); rejected(); assert path.read_bytes()==body; path.unlink()
    linked=root/'backup/wp-content/pinned'; linked.write_bytes(rules)
    for kind in ['symlink','hardlink']:
        if kind=='symlink': source_rule.symlink_to(linked)
        else: os.link(linked,source_rule)
        rejected(); assert linked.read_bytes()==rules; source_rule.unlink()
    linked.unlink()
    source_rule.write_bytes(rules)
    good=run(*args); assert good.returncode==0 and good.stderr=='',(good.stdout,good.stderr)
    assert json.loads(good.stdout).get('source_rules_omitted')==[rule_path],good.stdout
    assert source_rule.read_bytes()==rules
    spec=importlib.util.spec_from_file_location('upgrade_runner',repo/'docker/wordpress-upgrade/runner.py')
    module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    omitted=[]; files=module.inventory(root/'backup',omitted)
    assert omitted==[rule_path] and not any(name==rule_path for _,name,_ in files)
    # Existing one-argument helper remains usable; it must never copy the override.
    assert module.inventory(root/'backup')==files
    assert module.read_regular(source_rule,65536,source_rule.lstat())==rules
    stale=source_rule.lstat(); source_rule.write_bytes(rules+b'# changed')
    try: module.read_regular(source_rule,65536,stale)
    except RuntimeError: pass
    else: raise AssertionError('bounded regular-file reader accepted changed identity')
    try: module.inventory(root/'backup')
    except RuntimeError: pass
    else: raise AssertionError('shared inventory accepted modified rules')
    source_rule.write_bytes(rules)
    module.pack(files,good_sql.encode(),root/'packed.tar')
    with tarfile.open(root/'packed.tar') as packed:
        assert not any(name.endswith('/.htaccess') for name in packed.getnames()),packed.getnames()
    report=json.loads(good.stdout); assert report['ready'] is False and report['runtime_verified'] is False,report
    assert 'PRODUCTION_SECRET' not in good.stdout+good.stderr
    state=json.loads((root/'state.json').read_text())
    create=next(c for c in state['calls'] if c[0]=='create'); assert '--network' in create and create[create.index('--network')+1]=='none'
    assert not any('--publish' in c or '--privileged' in c or '--network=host' in c for c in state['calls'])
    cleanup=run('--cleanup',report['owner']); assert cleanup.returncode==0,(cleanup.stdout,cleanup.stderr)
    state=json.loads((root/'state.json').read_text()); assert not state['volumes'] and state['container'] is None,state
    assert not any('prune' in call or '--force' in call for call in state['calls'])
    print('PASS synthetic CLI rejection, owned resources, effective isolation gate, explicit owned cleanup and bounded output; real runtime NOT verified')
PY
