#!/usr/bin/env python3
"""Bounded public-API content assurance contracts using only pinned local images."""
import argparse
import hashlib
import io
import json
import pathlib
import secrets
import subprocess
import sys
import tarfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--endpoint', required=True)
parser.add_argument('--scenario', required=True, choices=['red', 'valid', 'row', 'schema', 'default', 'index', 'ai', 'plan', 'backup', 'authority', 'inventory', 'concurrent', 'unsupported', 'staging-only', 'replacement-authority', 'replacement-partition', 'replacement-terminal', 'replacement-complete', 'e2e-wordpress'], help='staging-only checks compatibility without requesting assurance; replacement-* checks exact-set preparation/terminal contracts or refusal without the real finalizer; other cases cover the dependent assurance contract')
parser.add_argument('--wordpress', help='Official WordPress tar.gz for the e2e-wordpress scenario (checksum verified by the caller)')
parser.add_argument('--check-reader', action='store_true', help='Supplement staging-only with actual fixed runtime reader controls')
args = parser.parse_args()
e2e = args.scenario == 'e2e-wordpress'
if e2e and not args.wordpress:
    parser.error('e2e-wordpress needs --wordpress')
if args.check_reader and args.scenario != 'staging-only':
    parser.error('--check-reader is only valid with --scenario staging-only')
root = pathlib.Path(__file__).resolve().parents[1]
binding = json.loads((root / '.atl/wordpress-restore-mysql84-proof/image-binding.json').read_text())
server_image = 'sha256:ae113cae2661960c4665a586812ae8150b2a133772a9f3ad63d1f1ae4f9e8ce7'
client_image = 'sha256:48b4b616daf3d5927e4d7499f89ffca712bf43fc9a15e7a543d89151e623f8f1'
assert args.endpoint == binding['endpoint'] == 'unix:///Users/mandy/.docker/run/docker.sock'
assert binding['server_image'] == server_image and binding['client_image'] == client_image
assert binding['architecture'] == 'arm64'
owner = secrets.token_hex(16)
proof = root / '.atl/wordpress-restore-content-assurance-proof' / ('native-' + owner)
proof.mkdir(mode=0o700)
docker = ['docker', '--host', args.endpoint]
record = {'owner': owner, 'scenario': args.scenario, 'check_reader': args.check_reader, 'containers': {}, 'volume': None,
          'runtime_sha256': hashlib.sha256((root / 'cms_tools.php').read_bytes()).hexdigest(), 'commands': []}
volume = 'cms-assurance-socket-' + owner
socket_dir = '/var/run/mysqld'
deadline = time.monotonic() + (600 if e2e else 22)
cleanup = False
exit_code = 1

def save():
    (proof / 'run.json').write_text(json.dumps(record, indent=2) + '\n')

def call(label, command, data=None, check=True, private=False):
    budget = 2 if cleanup else min(420 if e2e else 12, deadline - time.monotonic())
    if budget <= 0:
        raise TimeoutError('work budget exhausted')
    prefix = proof / ('%02d-' % len(record['commands']) + label)
    try:
        result = subprocess.run(docker + command, input=data, capture_output=True, timeout=budget)
    except subprocess.TimeoutExpired as error:
        if not private:
            prefix.with_suffix('.stdout').write_bytes(error.stdout or b'')
            prefix.with_suffix('.stderr').write_bytes(error.stderr or b'')
        prefix.with_suffix('.exit').write_text('TIMEOUT\n')
        record['commands'].append({'label': label, 'exit': 'TIMEOUT'})
        save()
        raise
    # Backup transfer contains SQL: retain exit/digest only, never raw rows/SQL.
    if not private:
        prefix.with_suffix('.stdout').write_bytes(result.stdout)
        prefix.with_suffix('.stderr').write_bytes(result.stderr)
    prefix.with_suffix('.exit').write_text(str(result.returncode) + '\n')
    record['commands'].append({'label': label, 'exit': result.returncode})
    save()
    if check and result.returncode:
        raise RuntimeError(label + ' failed')
    return result

def volume_owned():
    item = json.loads(call('inspect-volume', ['volume', 'inspect', volume]).stdout)[0]
    assert item['Name'] == record['volume'] == volume and item['Driver'] == 'local'
    assert item['Labels']['cms.restore.owner'] == owner and not item.get('Options')
    assert item['Scope'] == 'local'

def container_owned(role):
    expected = record['containers'][role]
    item = json.loads(call('inspect-' + role, ['inspect', expected['id']]).stdout)[0]
    host, config = item['HostConfig'], item['Config']
    assert item['Id'] == expected['id'] and item['Image'] == expected['image']
    assert item['Name'] == '/' + expected['name']
    assert config['Labels']['cms.restore.owner'] == owner and config['Labels']['cms.restore.role'] == role
    assert config['User'] == 'mysql' and host['NetworkMode'] == 'none' and host['ReadonlyRootfs']
    assert not host['Privileged'] and not host['Binds'] and not host['PortBindings'] and not host['CapAdd']
    assert host['CapDrop'] == ['ALL'] and 'no-new-privileges' in host['SecurityOpt']
    assert set(host['Tmpfs']) == {'/tmp', '/var/lib/mysql'}
    sockets = [m for m in item['Mounts'] if m['Type'] == 'volume']
    assert len(sockets) == 1 and sockets[0]['Name'] == volume
    assert sockets[0]['Destination'] == socket_dir and sockets[0]['RW'] == (role == 'server')
    assert all(m['Type'] == 'volume' or (m['Type'] == 'tmpfs' and m['Destination'] in host['Tmpfs']) for m in item['Mounts'])
    volume_owned()
    return item['State']

save()
try:
    for role, image in [('server', server_image), ('client', client_image)]:
        info = json.loads(call('image-' + role, ['image', 'inspect', image]).stdout)[0]
        assert info['Id'] == image and info['Architecture'] == 'arm64'
        assert set((info['Config'].get('Volumes') or {})) <= {'/var/lib/mysql', socket_dir}
    assert call('volume-must-be-new', ['volume', 'inspect', volume], check=False).returncode != 0
    created = call('create-volume', ['volume', 'create', '--driver', 'local', '--label', 'cms.restore.owner=' + owner, volume]).stdout.decode().strip()
    assert created == volume
    record['volume'] = created
    volume_owned()
    startup = ('umask 077; mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql '
               '>/tmp/init.log 2>&1 && exec mysqld --no-defaults --datadir=/var/lib/mysql '
               '--socket=/var/run/mysqld/fixture.sock --pid-file=/tmp/server.pid --skip-networking '
               '--mysqlx=0 --local-infile=0 --skip-log-bin --innodb-buffer-pool-size=64M --log-error=/tmp/mysql.log')
    for role, image in [('server', server_image), ('client', client_image)]:
        name = 'cms-assurance-' + role + '-' + owner
        mount = 'type=volume,src=' + volume + ',dst=' + socket_dir + (',readonly' if role == 'client' else '')
        volume_owned()
        identifier = call('create-' + role, ['create', '--pull', 'never', '--name', name,
            '--label', 'cms.restore.owner=' + owner, '--label', 'cms.restore.role=' + role,
            '--network', 'none', '--read-only', '--user', 'mysql', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--memory', '2g' if e2e else '1g', '--memory-swap', '2g' if e2e else '1g', '--pids-limit', '256',
            '--tmpfs', '/tmp:rw,noexec,nosuid,size=' + ('1000m' if e2e and role == 'client' else '128m') + ',mode=1777', '--tmpfs', '/var/lib/mysql:rw,noexec,nosuid,size=768m,mode=1777',
            '--mount', mount, '--entrypoint', '/bin/sh', image, '-c', startup if role == 'server' else (('export PHP_CLI_SERVER_WORKERS=4; ' if args.scenario in ('replacement-complete', 'e2e-wordpress') else '') + 'export TMPDIR=/tmp/contract/private; while [ ! -f /tmp/contract/ready ]; do sleep 0.1; done; exec php -d memory_limit=512M -d mysqli.default_socket=/var/run/mysqld/fixture.sock -d pdo_mysql.default_socket=/var/run/mysqld/fixture.sock -d session.save_path=/tmp/contract/private -S 127.0.0.1:18084 -t /tmp/contract/site')]).stdout.decode().strip()
        record['containers'][role] = {'id': identifier, 'image': image, 'name': name}
        save()
        container_owned(role)
        call('start-' + role, ['start', identifier])
    client = record['containers']['client']['id']
    ready = b'<?php try { new PDO("mysql:unix_socket=/var/run/mysqld/fixture.sock", "root", ""); exit(0); } catch (Throwable $e) { exit(1); }'
    while call('readiness', ['exec', '-i', client, 'php'], ready, check=False).returncode:
        if not container_owned('server')['Running']:
            raise RuntimeError('server stopped')
        time.sleep(0.15)
    container_owned('client')
    package = io.BytesIO()
    with tarfile.open(fileobj=package, mode='w') as archive:
        for name in ['cms_tools.php', 'tests/fixtures/wordpress-restore/values.php', 'tests/fixtures/wordpress-restore/mysql84-assurance.php', 'tests/wordpress-restore-warning-reader.php', 'tests/fixtures/wordpress-restore/replacement-authority.php'] + (['tests/fixtures/wordpress-restore/e2e.php'] if e2e else []):
            archive.add(root / name, arcname=name, recursive=False)
    call('copy-inputs', ['exec', '-i', client, '/bin/sh', '-c', 'umask 077; mkdir /tmp/contract && tar -xf - -C /tmp/contract'], package.getvalue())
    if e2e:
        call('copy-wordpress', ['exec', '-i', client, '/bin/sh', '-c', 'umask 077; tar -xzf - -C /tmp/contract'], pathlib.Path(args.wordpress).read_bytes())
        php = ['exec', client, 'php', '-d', 'memory_limit=512M', '-d', 'mysqli.default_socket=/var/run/mysqld/fixture.sock', '-d', 'pdo_mysql.default_socket=/var/run/mysqld/fixture.sock']
        driver = php + ['/tmp/contract/tests/fixtures/wordpress-restore/e2e.php']
        source = call('e2e-source', driver + ['source'], check=False)
        sys.stdout.buffer.write(source.stdout); sys.stderr.buffer.write(source.stderr)
        if source.returncode:
            raise RuntimeError('e2e source build failed')
        dump = call('e2e-dump', ['exec', record['containers']['server']['id'], 'mysqldump', '--no-defaults', '--socket=/var/run/mysqld/fixture.sock', '-uroot',
                                 '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', '--skip-comments', '--default-character-set=utf8mb4', 'src_wp'], private=True).stdout
        record['e2e_dump_bytes'] = len(dump)
        call('e2e-dump-copy', ['exec', '-i', client, '/bin/sh', '-c', 'umask 077; mkdir /tmp/contract/incoming && gzip -n > /tmp/contract/incoming/db_e2e.sql.gz'], dump, private=True)
        prepared = call('prepare-api', driver + ['prepare'], check=False)
    else:
        driver = ['exec', client, 'php', '/tmp/contract/tests/fixtures/wordpress-restore/mysql84-assurance.php']
        prepared = call('prepare-api', driver + ['prepare', args.scenario, 'reader-controls' if args.check_reader else 'no-reader-controls'], check=False)
    sys.stdout.buffer.write(prepared.stdout)
    sys.stderr.buffer.write(prepared.stderr)
    if prepared.returncode:
        raise RuntimeError('preparation fixture failed; not product RED')
    backup = call('private-recovery-input', ['exec', client, 'cat', '/tmp/contract/recovery.sql'], private=True).stdout
    record['recovery_input_sha256'] = hashlib.sha256(backup).hexdigest()
    container_owned('server')
    call('standard-recovery', ['exec', '-i', record['containers']['server']['id'], 'mysql', '--no-defaults',
         '--socket=/var/run/mysqld/fixture.sock', '-ufixture_recovery', 'recovery84'], backup, private=True)
    result = call('public-assurance', driver + (['complete'] if e2e else ['complete', args.scenario, 'reader-controls' if args.check_reader else 'no-reader-controls']), check=False)
    sys.stdout.buffer.write(result.stdout)
    sys.stderr.buffer.write(result.stderr)
    exit_code = result.returncode or (1 if result.stderr else 0)
except Exception as error:
    record['failure'] = type(error).__name__
    print('Fixture stopped: ' + type(error).__name__, file=sys.stderr)
finally:
    cleanup = True
    errors = []
    for role in reversed(list(record['containers'])):
        try:
            state = container_owned(role)
            identifier = record['containers'][role]['id']
            if state['Running']:
                call('stop-' + role, ['stop', '--time', '1', identifier])
            container_owned(role)
            call('remove-' + role, ['rm', identifier])
            record['containers'][role]['removed'] = True
        except Exception as error:
            errors.append(role + ':' + type(error).__name__)
    if record['volume'] and all(c.get('removed') for c in record['containers'].values()):
        try:
            volume_owned()
            call('remove-volume', ['volume', 'rm', volume])
            record['volume_removed'] = True
        except Exception as error:
            errors.append('volume:' + type(error).__name__)
    record['cleanup_errors'] = errors
    record['cleaned'] = bool(record.get('volume_removed')) and not errors
    record['exit'] = exit_code = 1 if errors else exit_code
    save()
    print('Assurance receipts: ' + str(proof.relative_to(root)))
sys.exit(exit_code)
