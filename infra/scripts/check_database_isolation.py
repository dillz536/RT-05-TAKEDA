#!/usr/bin/env python3
"""Check resolved Compose JSON, never output credential values. No DB/network access."""
import copy
import json
import sys
from urllib.parse import parse_qsl, unquote, urlsplit

RUNTIME = ('api', 'worker-default', 'worker-media', 'worker-exports', 'scheduler')


def contains(value, needle):
    if isinstance(value, str):
        return needle in value
    if isinstance(value, dict):
        return any(contains(item, needle) for item in value.values())
    if isinstance(value, list):
        return any(contains(item, needle) for item in value)
    return False


def role(url):
    parsed = urlsplit(url)
    # Laravel merges parse_str query options last. Refuse credential overrides
    # rather than reproducing PHP's nested arrays/native-type conversions.
    for key, _ in parse_qsl(parsed.query.replace(';', '&'), keep_blank_values=True):
        key = key.split('\0', 1)[0].lstrip().split('[', 1)[0].replace(' ', '_').replace('.', '_')
        if key in ('username', 'password'):
            raise ValueError('credential query overrides are not allowed')
    username = unquote(parsed.username or '')
    try:
        # Laravel also decodes JSON strings in primary URL components.
        native = json.loads(username)
    except ValueError:
        return username
    if not isinstance(native, str):
        raise ValueError('database role must be a string')
    return native


def validate(graph):
    services = graph.get('services', {})
    migration = services.get('migrate', {}).get('environment', {}).get('DATABASE_URL_UNPOOLED')
    runtime = services.get('api', {}).get('environment', {}).get('DATABASE_URL')
    if not isinstance(migration, str) or not migration:
        raise ValueError('migrate: direct credential required')
    if not isinstance(runtime, str) or not runtime or runtime == migration:
        raise ValueError('api: separate runtime credential required')
    owner = role(migration)
    runtime_role = role(runtime)
    for name in RUNTIME:
        env = services.get(name, {}).get('environment', {})
        if env.get('DATABASE_URL') != runtime:
            raise ValueError(name + ': consistent DML credential required')
        if owner and (runtime_role == owner or env.get('DB_USERNAME') == owner):
            raise ValueError(name + ': runtime and migration roles must differ')
    for name, service in services.items():
        if name == 'migrate':
            continue
        if 'DATABASE_URL_UNPOOLED' in service.get('environment', {}) or contains(service, migration):
            raise ValueError(name + ': migration credential present outside job')


def self_test():
    # Synthetic opaque values: no credential or connection is loaded by this test.
    graph = {'services': {name: {'environment': {'DATABASE_URL': 'runtime-only'}} for name in RUNTIME}}
    graph['services']['migrate'] = {'environment': {'DATABASE_URL': 'runtime-only', 'DATABASE_URL_UNPOOLED': 'migration-only'}}
    graph['services']['web'] = {'environment': {'APP_KEY': 'synthetic'}}
    validate(graph)
    mutations = []
    for name in RUNTIME:
        leaked = copy.deepcopy(graph)
        leaked['services'][name]['environment']['DATABASE_URL_UNPOOLED'] = 'migration-only'
        mutations.append(leaked)
    alias = copy.deepcopy(graph)
    alias['services']['web']['environment']['OWNER_URL_ALIAS'] = 'migration-only'
    mutations.append(alias)
    command = copy.deepcopy(graph)
    command['services']['scheduler']['command'] = ['run', '--url=migration-only']
    mutations.append(command)
    missing = copy.deepcopy(graph)
    missing['services']['migrate']['environment'].pop('DATABASE_URL_UNPOOLED')
    mutations.append(missing)
    same = copy.deepcopy(graph)
    same['services']['migrate']['environment']['DATABASE_URL_UNPOOLED'] = 'runtime-only'
    mutations.append(same)
    different = copy.deepcopy(graph)
    different['services']['worker-media']['environment']['DATABASE_URL'] = 'other-runtime'
    mutations.append(different)
    owner = copy.deepcopy(graph)
    for name in RUNTIME:
        owner['services'][name]['environment']['DATABASE_URL'] = 'postgresql://owner@pooled.example.test/db'
    owner['services']['migrate']['environment']['DATABASE_URL_UNPOOLED'] = 'postgresql://owner@direct.example.test/db'
    mutations.append(owner)
    for query in ('username=owner', '%75sername=owner', 'username%5B%5D=owner', 'password=synthetic', '+username=owner', 'username%00ignored=owner', 'unused=x;username=owner'):
        override = copy.deepcopy(graph)
        for name in RUNTIME:
            override['services'][name]['environment']['DATABASE_URL'] = 'postgresql://app@pooled.example.test/db?' + query
        override['services']['migrate']['environment']['DATABASE_URL_UNPOOLED'] = 'postgresql://owner@direct.example.test/db'
        mutations.append(override)
    encoded = copy.deepcopy(owner)
    for name in RUNTIME:
        encoded['services'][name]['environment']['DATABASE_URL'] = 'postgresql://%22owner%22@pooled.example.test/db'
    mutations.append(encoded)
    secure = copy.deepcopy(graph)
    for name in RUNTIME:
        secure['services'][name]['environment']['DATABASE_URL'] = 'postgresql://app@pooled.example.test/db?sslmode=verify-full&channel_binding=require'
    secure['services']['migrate']['environment']['DATABASE_URL_UNPOOLED'] = 'postgresql://owner@direct.example.test/db?sslmode=verify-full&sslrootcert=system'
    validate(secure)
    for mutated in mutations:
        try:
            validate(mutated)
        except ValueError:
            continue
        raise AssertionError('unsafe resolved graph accepted')
    print('PASS: database isolation self-test (2 legitimate graphs + 19 leak/misconfiguration cases)')


if __name__ == '__main__':
    try:
        if sys.argv[1:] == ['--self-test']:
            self_test()
        elif sys.argv[1:]:
            raise ValueError('use stdin Compose JSON or --self-test')
        else:
            validate(json.load(sys.stdin))
            print('PASS: migration credential only in migrate; five runtime services use separate DML credential')
    except (ValueError, KeyError, TypeError, AssertionError):
        # A malformed JSON/URL error may embed input: never echo exception text.
        print('FAIL: resolved Compose violates database credential isolation', file=sys.stderr)
        sys.exit(1)
