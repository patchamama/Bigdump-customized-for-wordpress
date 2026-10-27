"""Optional local Docker adapter. Treat the CLI operator, image and local daemon as trusted."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import selectors
import time
import sys
import tarfile
import tempfile
import uuid

LABEL = 'org.cms-tools.upgrade'
LIMIT = 131072


def engine(*args, limit=LIMIT, timeout=20):
    process = subprocess.Popen(['docker', *args], stdin=subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    output = bytearray()
    size = 0
    deadline = time.monotonic() + timeout
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            selector.register(process.stderr, selectors.EVENT_READ)
            while selector.get_map():
                if time.monotonic() > deadline:
                    raise RuntimeError('Docker operation timed out.')
                for key, _ in selector.select(0.1):
                    block = os.read(key.fileobj.fileno(), 8192)
                    if not block:
                        selector.unregister(key.fileobj)
                        continue
                    size += len(block)
                    if size > limit:
                        raise RuntimeError('Docker output exceeded its bound.')
                    if key.fileobj is process.stdout:
                        output.extend(block)
        if process.wait(timeout=max(0.1, deadline-time.monotonic())):
            raise RuntimeError('Docker operation failed.')
        return output.decode('utf-8')
    finally:
        if process.poll() is None:
            process.kill()
        process.wait()
        process.stdout.close()
        process.stderr.close()


def inspect(kind, name):
    data = json.loads(engine(kind, 'inspect', name) if kind == 'volume' else engine('inspect', name))
    if not isinstance(data, list) or len(data) != 1 or not isinstance(data[0], dict):
        raise RuntimeError('Invalid Docker inspection.')
    return data[0]


def safe_context():
    if any(k.startswith('DOCKER_') and v for k, v in os.environ.items()):
        raise RuntimeError('Unset Docker environment overrides; use a verified local context.')
    data = json.loads(engine('context', 'inspect'))
    if not isinstance(data, list) or len(data) != 1:
        raise RuntimeError('Invalid Docker context.')
    endpoint = data[0].get('Endpoints', {}).get('docker', {})
    if not re.fullmatch(r'unix:///[^\s]+', endpoint.get('Host', '')) or endpoint.get('SkipTLSVerify'):
        raise RuntimeError('Only a local Unix-socket Docker context is accepted.')


# Authenticated official WordPress 6.8.3 and 7.1.3 bundled Akismet rules.
# Source rules are verified for omission only, never installed in the executor.
SOURCE_RULE_PATH = 'wp-content/plugins/akismet/.htaccess'
SOURCE_RULE_HASHES = {
    '6d8db93cbee3b408b9b8972684d20f33d739efd11d32ba584d6df272a7873011',
    '63553e7f320285fe2417cdb00664a136958d70baa07d7325e86f0847f11dd17c',
}


def inventory(backup, rules_omitted=None):
    backup = Path(backup)
    if not backup.is_absolute() or backup.is_symlink() or not backup.is_dir():
        raise RuntimeError('Supply an explicit offline backup directory, not a live installation.')
    files = []
    total = 0
    for base, dirs, names in os.walk(backup, followlinks=False):
        for name in dirs + names:
            p = Path(base) / name
            stat = p.lstat()
            if p.is_symlink() or (not p.is_dir() and not p.is_file()) or (p.is_file() and stat.st_nlink != 1):
                raise RuntimeError('Backup links, shared files and special files are not supported.')
            relative = p.relative_to(backup).as_posix()
            if name.lower() in ('.htaccess', '.user.ini', 'php.ini'):
                if (relative == SOURCE_RULE_PATH and p.is_file() and stat.st_size <= 65536
                        and hashlib.sha256(read_regular(p, 65536, stat)).hexdigest() in SOURCE_RULE_HASHES):
                    if rules_omitted is not None:
                        rules_omitted.append(relative)
                    continue
                raise RuntimeError('Backup routing/PHP overrides need separate-host validation; Docker refuses them.')
            if name in ('wp-config.php', '.migrate_config'):
                continue
            if not re.fullmatch(r'[A-Za-z0-9_@+,./-]+', relative):
                raise RuntimeError('Unsupported backup path.')
            if p.is_file():
                total += stat.st_size
                if stat.st_size > 16777216 or total > 268435456 or len(files) >= 20000:
                    raise RuntimeError('Backup exceeds supported copy bounds.')
                # Only standard core and content; hidden configs/logs/dumps never enter runtime.
                if any(part.startswith('.') for part in p.relative_to(backup).parts) or p.suffix.lower() in ('.sql', '.log', '.ini', '.env'):
                    raise RuntimeError('Remove logs, dumps and hidden configuration from the offline backup.')
                files.append((p, relative, stat))
    if not any(name.startswith('wp-content/') for _, name, _ in files):
        raise RuntimeError('Backup must contain independent wp-content resources.')
    return files


def read_regular(path, limit, expected):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        before = os.fstat(descriptor)
        identity = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_nlink)
        if identity(before) != identity(expected) or before.st_nlink != 1 or before.st_size > limit:
            raise RuntimeError('Backup identity changed before read.')
        with os.fdopen(os.dup(descriptor), 'rb') as stream:
            body = stream.read(limit + 1)
        if len(body) != before.st_size or identity(os.fstat(descriptor)) != identity(before):
            raise RuntimeError('Backup changed during bounded read.')
        return body
    finally:
        os.close(descriptor)


def pack(files, sql, destination):
    with tarfile.open(destination, 'w') as archive:
        for path, name, before in files:
            body = read_regular(path, 16777216, before)
            after = path.lstat()
            if path.is_symlink() or after.st_nlink != 1 or (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns):
                raise RuntimeError('Backup changed during independent copy.')
            info = tarfile.TarInfo('site/' + name)
            info.size = len(body)
            info.mode = 0o600
            archive.addfile(info, io.BytesIO(body))
        info = tarfile.TarInfo('seed.sql')
        info.size = len(sql)
        info.mode = 0o600
        archive.addfile(info, io.BytesIO(sql))


def verify_executor(name, owner, image, volumes, running):
    d = inspect('container', name)
    h, c = d.get('HostConfig', {}), d.get('Config', {})
    if c.get('Labels', {}).get(LABEL) != owner or d.get('Image') != image or bool(d.get('State', {}).get('Running')) != running:
        raise RuntimeError('Executor ownership, image or lifecycle mismatch.')
    if h.get('NetworkMode') != 'none' or h.get('Privileged') or h.get('PidMode') or h.get('IpcMode') != 'private' or h.get('UTSMode') or h.get('UsernsMode') == 'host':
        raise RuntimeError('Executor namespaces are unsafe.')
    if h.get('ExtraHosts') != ['staging.invalid:127.0.0.1'] or h.get('Links') or h.get('VolumesFrom') or h.get('NetworkMode') != 'none':
        raise RuntimeError('Executor host aliases or links are unsafe.')
    if h.get('Binds') or h.get('PortBindings') or h.get('PublishAllPorts') or h.get('Devices') or h.get('DeviceRequests') or set(h.get('CapAdd') or []) != {'CHOWN', 'SETUID', 'SETGID', 'KILL', 'DAC_OVERRIDE'} or not h.get('ReadonlyRootfs'):
        raise RuntimeError('Executor host access is unsafe.')
    if 'ALL' not in h.get('CapDrop', []) or 'no-new-privileges' not in h.get('SecurityOpt', []):
        raise RuntimeError('Executor capability controls are missing.')
    if set(h.get('Tmpfs', {})) != {'/run', '/tmp'} or not h.get('Memory') or not h.get('NanoCpus') or not 1 <= h.get('PidsLimit', 0) <= 256:
        raise RuntimeError('Executor scratch or resource bounds are missing.')
    mounts = d.get('Mounts', [])
    actual = {m.get('Destination'): (m.get('Name'), m.get('RW')) for m in mounts if m.get('Type') == 'volume'}
    if actual != volumes or any(m.get('Type') not in ('volume', 'tmpfs') for m in mounts):
        raise RuntimeError('Unexpected executor mount.')
    for volume, _ in volumes.values():
        if inspect('volume', volume).get('Labels', {}).get(LABEL) != owner:
            raise RuntimeError('Data resource ownership mismatch.')
    networks = d.get('NetworkSettings', {}).get('Networks', {})
    if set(networks) - {'none'} or any(v.get('IPAddress') or v.get('GlobalIPv6Address') for v in networks.values()):
        raise RuntimeError('Executor has an external network attachment.')


def cleanup(owner):
    if not re.fullmatch(r'[a-f0-9]{32}', owner):
        raise RuntimeError('Invalid ownership identifier.')
    safe_context()
    container = 'cms-upgrade-' + owner + '-executor'
    volumes = {'cms-upgrade-' + owner + '-' + k for k in ('cache', 'data')}
    found = []
    for kind, command, allowed in [('container', ('ps', '-a'), {container}), ('volume', ('volume', 'ls'), volumes)]:
        names = engine(*command, '--filter', 'label=' + LABEL + '=' + owner, '--format', '{{.Names}}' if kind == 'container' else '{{.Name}}').splitlines()
        if set(names) - allowed or len(names) != len(set(names)):
            raise RuntimeError('Unexpected ownership resources; no cleanup attempted.')
        for name in names:
            d = inspect(kind, name)
            labels = d.get('Config', {}).get('Labels', {}) if kind == 'container' else d.get('Labels', {})
            if labels.get(LABEL) != owner:
                raise RuntimeError('Cleanup ownership mismatch.')
            found.append((kind, name, d.get('State', {}).get('Running', False)))
    # All resources were verified before removing any; never force/prune/repair.
    for kind, name, running in found:
        if kind == 'container':
            if running:
                engine('stop', '--time', '10', name)
            engine('container', 'rm', name)
    for kind, name, _ in found:
        if kind == 'volume':
            engine('volume', 'rm', name)
    print(json.dumps({'ok': True, 'cleaned_owned_resources': len(found)}))


def main():
    parser = argparse.ArgumentParser(description='Optional offline Docker upgrade smoke tests from an explicit backup; never production promotion.',
                                     epilog='Explicit cleanup only: run.sh --cleanup OWNER (the 32-hex owner in JSON). No prune or forced repair.')
    if len(sys.argv) == 3 and sys.argv[1] == '--cleanup':
        try:
            cleanup(sys.argv[2])
            return 0
        except (RuntimeError, OSError, ValueError, subprocess.TimeoutExpired, KeyError, TypeError):
            print('{"ok":false,"error":"Owned cleanup failed closed; no broad cleanup or repair was attempted."}')
            return 1
    parser.add_argument('--backup', required=True, help='Offline backup, never live production; only pinned official Akismet rules are verified then omitted (reported as source_rules_omitted), never copied; other overrides refused')
    parser.add_argument('--sql', required=True, help='Plain single-database CREATE TABLE/INSERT SQL, no client commands')
    parser.add_argument('--production-url', required=True, help='Production HTTPS origin for staging attestation only')
    parser.add_argument('--prefix', required=True, help='Literal WordPress table prefix')
    parser.add_argument('--image', required=True, help='Locally built trusted image ID sha256:...; no automatic build/pull')
    args = parser.parse_args()
    resources = []
    owner = uuid.uuid4().hex
    try:
        if not re.fullmatch(r'sha256:[a-f0-9]{64}', args.image) or not re.fullmatch(r'[A-Za-z0-9_]{1,40}', args.prefix):
            raise RuntimeError('Invalid immutable local image ID or table prefix.')
        if not re.fullmatch(r'https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?', args.production_url) or args.production_url.lower().startswith('https://staging.invalid'):
            raise RuntimeError('Supply a distinct production HTTPS origin.')
        # All local admission precedes daemon mutations, even in a fake-engine test.
        rules_omitted = []
        files = inventory(args.backup, rules_omitted)
        p = Path(args.sql)
        if not p.is_absolute() or p.is_symlink() or not p.is_file() or p.stat().st_nlink != 1 or p.stat().st_size > 33554432:
            raise RuntimeError('Unsafe SQL backup.')
        sql = read_regular(p, 33554432, p.lstat())
        if not sql or b'\x00' in sql:
            raise RuntimeError('Invalid plain SQL backup.')
        validation = subprocess.run(['php', str(Path(__file__).with_name('import.php')), args.prefix],
                                    input=sql, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        if validation.returncode:
            raise RuntimeError('Unsupported SQL; no resources created.')
        safe_context()
        image = json.loads(engine('image', 'inspect', args.image))
        if len(image) != 1 or image[0].get('Id') != args.image:
            raise RuntimeError('Local image identity mismatch.')
        names = {k: 'cms-upgrade-' + owner + '-' + k for k in ('cache', 'data', 'executor')}
        for key in ('cache', 'data'):
            engine('volume', 'create', '--label', LABEL + '=' + owner, names[key])
            resources.append({'kind': 'volume', 'name': names[key]})
            if inspect('volume', names[key]).get('Labels', {}).get(LABEL) != owner:
                raise RuntimeError('Volume ownership could not be verified.')
        # Trusted prefetch has no backup/site/database mounted. Redirects are refused by PHP.
        engine('run', '--rm', '--pull', 'never', '--label', LABEL + '=' + owner,
               '--network', 'bridge', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
               '--read-only', '--mount', 'type=volume,src=' + names['cache'] + ',dst=/cache',
               '--entrypoint', 'php', args.image, '/opt/upgrade/prefetch.php', timeout=120)
        volumes = {'/cache': (names['cache'], False), '/data': (names['data'], True)}
        engine('create', '--pull', 'never', '--name', names['executor'], '--label', LABEL + '=' + owner,
               '--network', 'none', '--ipc', 'private', '--cap-drop', 'ALL', '--cap-add', 'CHOWN',
               '--cap-add', 'SETUID', '--cap-add', 'SETGID', '--cap-add', 'KILL', '--cap-add', 'DAC_OVERRIDE',
               '--security-opt', 'no-new-privileges', '--read-only', '--pids-limit', '256',
               '--memory', '1g', '--cpus', '2', '--tmpfs', '/run:rw,nosuid,nodev,size=64m',
               '--tmpfs', '/tmp:rw,nosuid,nodev,size=128m',
               '--mount', 'type=volume,src=' + names['cache'] + ',dst=/cache,readonly',
               '--mount', 'type=volume,src=' + names['data'] + ',dst=/data',
               '--add-host', 'staging.invalid:127.0.0.1',
               '--env', 'CMS_UPGRADE_OFFLINE_DIR=/cache', '--env', 'CMS_PRODUCTION_ORIGIN=' + args.production_url,
               '--env', 'CMS_TABLE_PREFIX=' + args.prefix, args.image)
        resources.append({'kind': 'container', 'name': names['executor']})
        # Root setup may chown/drop identities; HTTP PHP must run with no effective caps.
        verify_executor(names['executor'], owner, args.image, volumes, False)
        with tempfile.TemporaryDirectory(prefix='cms-upgrade-copy-') as scratch:
            archive = Path(scratch) / 'input.tar'
            pack(files, sql, archive)
            # docker cp creates independent files in the owned volume; no host bind mounts.
            engine('cp', str(archive), names['executor'] + ':/data/input.tar')
        engine('start', names['executor'])
        verify_executor(names['executor'], owner, args.image, volumes, True)
        raw = json.loads(engine('exec', '--user', '0:0', names['executor'], 'php', '/opt/upgrade/driver.php', timeout=1200))
        counts = raw.get('findings', {})
        if set(counts) - {'ok', 'warning', 'failed', 'unavailable'} or any(type(v) is not int or not 0 <= v <= 1000 for v in counts.values()):
            raise RuntimeError('Invalid bounded smoke result.')
        # Never forward logs, DB data, plugin paths, error strings or arbitrary runtime JSON.
        report = {'ok': raw.get('ok') is True, 'ready': raw.get('ready') is True,
                  'runtime_verified': raw.get('runtime_verified') is True, 'findings': counts,
                  'resources': resources, 'owner': owner, 'promotion': 'manual-only',
                  'scope': 'Container-only smoke checks, not production compatibility.'}
        if rules_omitted:
            report['source_rules_omitted'] = sorted(rules_omitted)
        settings = raw.get('settings', {})
        safe = {}
        for key in ('php', 'memory', 'upload', 'post'):
            value = settings.get(key)
            if isinstance(value, str) and re.fullmatch(r'[0-9.]+[KMG]?', value):
                safe[key] = value
        if type(settings.get('execution')) is int and 0 <= settings['execution'] <= 3600:
            safe['execution'] = settings['execution']
        report['container_php'] = safe
        apache = re.match(r'^Apache/([0-9]+\.[0-9]+\.[0-9]+)(?:\s|$)', str(settings.get('server', '')))
        report['container_server'] = 'Apache/' + apache[1] if apache else 'unavailable'
        print(json.dumps(report))
        return 0 if report['ok'] else 1
    except (RuntimeError, OSError, ValueError, subprocess.TimeoutExpired, KeyError, TypeError):
        print(json.dumps({'ok': False, 'ready': False, 'error': 'Docker testing failed closed. Review prerequisites; no automatic retry or repair.', 'resources': resources, 'owner': owner}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
