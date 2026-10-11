#!/usr/bin/env python3
"""Fill missing Redis credentials in the existing, private RT05 staging env only.

No path argument, production target, rotation, backup, or credential output.
Run on the authorized VPS after reviewing this exact committed file.
"""
import os
from pathlib import Path
import secrets
import stat
import sys
import tempfile

TARGET = Path('/root/.rt05/.env.staging')
KEYS = (b'REDIS_PASSWORD', b'REDIS_CACHE_PASSWORD')


def prepare_content(original: bytes) -> tuple[bytes, list[str]]:
    lines = original.splitlines(keepends=True)
    values = {}
    for index, line in enumerate(lines):
        key, separator, value = line.rstrip(b'\r\n').partition(b'=')
        normalized = key.strip()
        if normalized.startswith(b'export '):
            normalized = normalized[7:].strip()
        if separator and normalized in (b'APP_ENV', *KEYS) and key != normalized:
            raise ValueError('Noncanonical guarded env key')
        if separator and key in (b'APP_ENV', *KEYS):
            if key in values:
                raise ValueError('Duplicate guarded env key')
            values[key] = (index, value.strip())
    if values.get(b'APP_ENV', (None, None))[1] != b'staging':
        raise ValueError('Exact APP_ENV=staging required')
    newline = b'\r\n' if b'\r\n' in original else b'\n'
    changed = []
    for key in KEYS:
        entry = values.get(key)
        value = entry[1] if entry else b''
        if value not in (b'', b'""', b"''") and not value.startswith(b'#'):
            continue
        replacement = key + b'=' + secrets.token_hex(32).encode('ascii')
        if entry:
            old = lines[entry[0]]
            ending = b'\r\n' if old.endswith(b'\r\n') else (b'\n' if old.endswith(b'\n') else b'')
            lines[entry[0]] = replacement + ending
        else:
            if lines and not lines[-1].endswith(b'\n'):
                lines[-1] += newline
            lines.append(replacement + newline)
        changed.append(key.decode('ascii'))
    return b''.join(lines), changed


def main() -> int:
    if len(sys.argv) != 1 or sys.platform != 'linux' or os.geteuid() != 0:
        print('Rejected: VPS root, Linux, and no arguments required.', file=sys.stderr)
        return 1
    temporary = None
    try:
        parent = TARGET.parent.lstat()
        if not stat.S_ISDIR(parent.st_mode) or parent.st_uid != 0 or parent.st_mode & 0o077:
            raise ValueError('Private root-owned env directory required')
        descriptor = os.open(TARGET, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(descriptor, 'rb') as source:
            before = os.fstat(source.fileno())
            if not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or stat.S_IMODE(before.st_mode) != 0o600:
                raise ValueError('Existing root-owned regular env mode 600 required')
            original = source.read()
        replacement, changed = prepare_content(original)
        if not changed:
            print('Unchanged: both Redis staging credentials already present.')
            return 0
        descriptor, temporary = tempfile.mkstemp(prefix='.redis-auth-', dir=TARGET.parent)
        with os.fdopen(descriptor, 'wb') as destination:
            os.fchmod(destination.fileno(), 0o600)
            destination.write(replacement)
            destination.flush()
            os.fsync(destination.fileno())
        current = TARGET.lstat()
        if (current.st_dev, current.st_ino, current.st_mtime_ns, current.st_size) != (
            before.st_dev, before.st_ino, before.st_mtime_ns, before.st_size
        ):
            raise ValueError('Env changed concurrently; refusing replacement')
        os.replace(temporary, TARGET)
        temporary = None
        directory = os.open(TARGET.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
        print('Provisioned staging keys: ' + ', '.join(changed) + '; mode 600; existing other values preserved.')
        return 0
    except Exception as error:
        print('Rejected: ' + type(error).__name__ + '; no credential values printed.', file=sys.stderr)
        return 1
    finally:
        if temporary is not None:
            os.unlink(temporary)


if __name__ == '__main__':
    raise SystemExit(main())
