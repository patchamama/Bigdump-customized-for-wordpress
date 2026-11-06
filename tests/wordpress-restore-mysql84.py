#!/usr/bin/env python3
"""One owned MySQL 8.4 synthetic viability experiment; never pulls images."""
import argparse
import hashlib
import json
import pathlib
import secrets
import subprocess
import sys
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--endpoint', required=True)
args = parser.parse_args()
root = pathlib.Path(__file__).resolve().parents[1]
area = root / '.atl/wordpress-restore-mysql84-proof'
binding = json.loads((area / 'image-binding.json').read_text())
server_image = 'sha256:ae113cae2661960c4665a586812ae8150b2a133772a9f3ad63d1f1ae4f9e8ce7'
client_image = 'sha256:48b4b616daf3d5927e4d7499f89ffca712bf43fc9a15e7a543d89151e623f8f1'
assert args.endpoint == binding['endpoint'] == 'unix:///Users/mandy/.docker/run/docker.sock'
assert binding['server_image'] == server_image and binding['client_image'] == client_image
assert binding['architecture'] == 'arm64' and binding['mysql_major'] == '8.4'
owner = secrets.token_hex(16)
proof = area / ('native-' + owner)
proof.mkdir(mode=0o700)
docker = ['docker', '--host', args.endpoint]
record = {'owner': owner, 'containers': {}, 'volume': None, 'cleaned': False,
          'runtime_sha256': hashlib.sha256((root / 'cms_tools.php').read_bytes()).hexdigest(), 'commands': []}
volume = 'cms-mysql84-socket-' + owner
socket_dir = '/var/run/mysqld'
deadline = time.monotonic() + 22
cleanup = False
exit_code = 1

def save():
    (proof / 'run.json').write_text(json.dumps(record, indent=2) + '\n')

def call(label, command, data=None, check=True):
    budget = 2 if cleanup else min(10, deadline - time.monotonic())
    if budget <= 0:
        raise TimeoutError('owned work budget exhausted')
    number = len(record['commands'])
    prefix = proof / ('%02d-' % number + label)
    try:
        result = subprocess.run(docker + command, input=data, capture_output=True, timeout=budget)
    except subprocess.TimeoutExpired as error:
        prefix.with_suffix('.stdout').write_bytes(error.stdout or b'')
        prefix.with_suffix('.stderr').write_bytes(error.stderr or b'')
        prefix.with_suffix('.exit').write_text('TIMEOUT\n')
        record['commands'].append({'label': label, 'exit': 'TIMEOUT'})
        save()
        raise
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
    absent = call('volume-must-be-new', ['volume', 'inspect', volume], check=False)
    assert absent.returncode != 0
    created = call('create-volume', ['volume', 'create', '--driver', 'local', '--label',
        'cms.restore.owner=' + owner, volume]).stdout.decode().strip()
    assert created == volume
    record['volume'] = created
    volume_owned()
    startup = ('umask 077; mysqld --no-defaults --initialize-insecure --datadir=/var/lib/mysql '
               '>/tmp/init.log 2>&1 && exec mysqld --no-defaults --datadir=/var/lib/mysql '
               '--socket=/var/run/mysqld/fixture.sock --pid-file=/tmp/server.pid --skip-networking '
               '--mysqlx=0 --local-infile=0 --skip-log-bin --innodb-buffer-pool-size=64M '
               '--log-error=/tmp/mysql.log')
    for role, image in [('server', server_image), ('client', client_image)]:
        name = 'cms-mysql84-' + role + '-' + owner
        mount = 'type=volume,src=' + volume + ',dst=' + socket_dir + (',readonly' if role == 'client' else '')
        volume_owned()
        identifier = call('create-' + role, ['create', '--pull', 'never', '--name', name,
            '--label', 'cms.restore.owner=' + owner, '--label', 'cms.restore.role=' + role,
            '--network', 'none', '--read-only', '--user', 'mysql', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges', '--memory', '1g', '--memory-swap', '1g',
            '--pids-limit', '256', '--tmpfs', '/tmp:rw,noexec,nosuid,size=128m,mode=1777',
            '--tmpfs', '/var/lib/mysql:rw,noexec,nosuid,size=768m,mode=1777', '--mount', mount,
            '--entrypoint', '/bin/sh', image, '-c', startup if role == 'server' else 'exec sleep 120']).stdout.decode().strip()
        record['containers'][role] = {'id': identifier, 'image': image, 'name': name}
        save()
        container_owned(role)
        call('start-' + role, ['start', identifier])
    client = record['containers']['client']['id']
    readiness = b'<?php try { new PDO("mysql:unix_socket=/var/run/mysqld/fixture.sock", "root", ""); exit(0); } catch (Throwable $e) { exit(1); }'
    while True:
        if call('readiness', ['exec', '-i', client, 'php'], readiness, check=False).returncode == 0:
            break
        if not container_owned('server')['Running']:
            raise RuntimeError('server stopped before readiness')
        time.sleep(0.15)
    container_owned('client')
    result = call('premise', ['exec', '-i', client, 'php'],
                  (root / 'tests/fixtures/wordpress-restore/mysql84-premise.php').read_bytes(), check=False)
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
    if errors:
        exit_code = 1
    record['exit'] = exit_code
    save()
    print('MySQL84 receipts: ' + str(proof.relative_to(root)))
sys.exit(exit_code)
