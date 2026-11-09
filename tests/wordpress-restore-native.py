#!/usr/bin/env python3
"""Disposable native restoration-admission contracts; no pulls or host mounts."""
import argparse
import io
import json
import pathlib
import re
import secrets
import subprocess
import sys
import tarfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--endpoint', required=True, help='Explicit local unix:// Docker endpoint')
parser.add_argument('--image', required=True, help='Existing immutable sha256 image ID with MariaDB/PHP/PDO')
parser.add_argument('--scenario', choices=['admission', 'capture', 'verify', 'artifact-tamper', 'catalog-tamper', 'lock-contention', 'unsupported', 'grant-refusal', 'cell-quota', 'staging', 'staging-plan-tamper', 'staging-artifact-tamper', 'staging-control-collision', 'staging-table-collision', 'staging-owner', 'staging-journal', 'staging-interrupt', 'staging-utf8', 'staging-lock', 'staging-collation', 'staging-trigger', 'staging-assurance', 'assurance-premise', 'assurance-reference-premise'], default='admission',
                    help='Default admission baseline; capture/verify backup; staging validates bounded typed SQL; staging-* exercise isolated refusal, ownership, journal and abrupt-kill boundaries; staging-assurance requires live-content seals; assurance-premise probes synthetic lock/DDL semantics; assurance-reference-premise probes private references and locked rename viability')
args = parser.parse_args()
if not args.endpoint.startswith('unix:///') or not re.fullmatch(r'sha256:[0-9a-f]{64}', args.image):
    parser.error('A local Unix endpoint and full immutable image ID are required.')
root = pathlib.Path(__file__).resolve().parents[1]
owner = secrets.token_hex(16)
assurance_cases = {'staging-assurance', 'assurance-premise', 'assurance-reference-premise'}
proof_area = ('.atl/wordpress-restore-assurance-proof' if args.scenario in assurance_cases else
              '.atl/wordpress-restore-import-proof' if args.scenario.startswith('staging') else
              '.atl/wordpress-restore-native-proof')
proof = root / proof_area / ('native-' + owner)
proof.mkdir(parents=True, mode=0o700)
docker = ['docker', '--host', args.endpoint]
record = {'owner': owner, 'name': 'cms-restore-native-' + owner[:16], 'image': args.image,
          'id': None, 'ready': False, 'cleaned': False, 'scenario': args.scenario, 'commands': []}
container = None
exit_code = 1

def save():
    (proof / 'run.json').write_text(json.dumps(record, indent=2) + '\n')

def call(label, command, data=None, check=True, timeout=30):
    result = subprocess.run(docker + command, input=data, capture_output=True, timeout=timeout)
    record['commands'].append({'label': label, 'exit': result.returncode,
                               'stdout_bytes': len(result.stdout), 'stderr_bytes': len(result.stderr)})
    save()
    # Do not log SQL/setup bodies, credentials or arbitrary database errors.
    if check and result.returncode:
        raise RuntimeError(label + ' failed; exit=' + str(result.returncode))
    return result

def inspect_owned():
    item = json.loads(call('inspect owned container', ['inspect', container]).stdout)[0]
    assert item['Id'] == container and item['Image'] == args.image
    assert item['Config']['Labels']['cms.restore.owner'] == owner
    host = item['HostConfig']
    assert item['Config']['User'] == 'mysql' and host['NetworkMode'] == 'none'
    assert host['ReadonlyRootfs'] and not host['Privileged'] and not host['Binds'] and not host['CapAdd']
    return item

save()
try:
    startup = ('umask 077; mariadb-install-db --no-defaults --datadir=/tmp/mysql '
               '--auth-root-authentication-method=normal --skip-test-db >/tmp/init.log 2>&1 && '
               'exec mariadbd --no-defaults --datadir=/tmp/mysql --socket=/run/mysqld/mysqld.sock '
               '--pid-file=/tmp/mysql.pid --skip-networking --local-infile=0 --skip-log-bin '
               '--general-log=0 --slow-query-log=0 --skip-name-resolve --tmpdir=/tmp '
               '>/tmp/database.log 2>&1')
    container = call('create isolated fixture', ['create', '--pull', 'never', '--name', record['name'],
        '--label', 'cms.restore.owner=' + owner, '--network', 'none', '--read-only', '--user', 'mysql',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--memory', '1g', '--memory-swap', '1g',
        '--pids-limit', '256', '--tmpfs', '/tmp:rw,noexec,nosuid,size=1073741824,mode=1777',
        '--tmpfs', '/run/mysqld:rw,noexec,nosuid,size=16777216,mode=0777', '--entrypoint', '/bin/sh',
        args.image, '-c', startup]).stdout.decode().strip()
    record['id'] = container
    save()
    inspect_owned()
    call('start fixture once', ['start', container])
    deadline = time.monotonic() + 30
    while True:
        ping = call('connection-startup readiness', ['exec', container, 'mariadb-admin', '--no-defaults',
                    '--socket=/run/mysqld/mysqld.sock', '-uroot', 'ping', '--silent'], check=False, timeout=3)
        if ping.returncode == 0:
            break
        if time.monotonic() >= deadline or not inspect_owned()['State']['Running']:
            raise RuntimeError('Native startup failed or timed out; no restart attempted.')
        time.sleep(0.2)
    record['ready'] = True
    call('native PHP capabilities', ['exec', container, 'php', '-r',
         'exit(extension_loaded("pdo_mysql") && extension_loaded("zlib") && class_exists("ZipArchive") ? 0 : 77);'])
    package = io.BytesIO()
    with tarfile.open(fileobj=package, mode='w') as archive:
        for name in ['cms_tools.php', 'tests/fixtures/wordpress-restore/native-client.php',
                     'tests/fixtures/wordpress-restore/values.php'] + (['tests/fixtures/wordpress-restore/import.php'] if args.scenario.startswith('staging') else []) + (['tests/fixtures/wordpress-restore/assurance.php'] if args.scenario in {'assurance-premise', 'assurance-reference-premise'} else []):
            archive.add(root / name, arcname=name, recursive=False)
    call('copy independent trusted inputs', ['exec', '-i', container, '/bin/sh', '-c',
         'umask 077; mkdir /tmp/restore-proof && tar -xf - -C /tmp/restore-proof'], package.getvalue())
    result = call('public native admission contract', ['exec', container, 'php',
                  '/tmp/restore-proof/tests/fixtures/wordpress-restore/native-client.php', args.scenario], check=False, timeout=20 if args.scenario in assurance_cases or args.scenario.startswith('staging') else 90)
    (proof / 'admission.stdout').write_bytes(result.stdout)
    (proof / 'admission.stderr').write_bytes(result.stderr)
    (proof / 'admission.exit').write_text(str(result.returncode) + '\n')
    evidence = call('collect safe contract report', ['exec', container, 'cat', '/tmp/admission-contract.json'], check=False)
    if evidence.returncode == 0:
        parsed = json.loads(evidence.stdout)
        (proof / 'contract.json').write_text(json.dumps(parsed, indent=2) + '\n')
    exit_code = result.returncode or (1 if result.stderr else 0)
    if args.scenario.startswith('staging'):
        print('Native staging assertions:', result.stdout.count(b'PASS '), 'exit:', result.returncode)
    else:
        sys.stdout.buffer.write(result.stdout)
    sys.stderr.buffer.write(result.stderr)
except Exception as error:
    print('Native fixture failed: ' + type(error).__name__ + ': ' + str(error), file=sys.stderr)
    record['infrastructure_error'] = type(error).__name__
finally:
    if container:
        try:
            state = inspect_owned()['State']
            if state['Running']:
                call('stop exact owned fixture', ['stop', '--time', '2', container])
            call('remove exact stopped owned fixture', ['rm', container])
            record['cleaned'] = True
        except Exception as error:
            record['cleanup_error'] = type(error).__name__
            exit_code = 1
    record['exit'] = exit_code
    save()
    print('Native fixture receipts: ' + str(proof.relative_to(root)))
sys.exit(exit_code)
