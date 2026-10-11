#!/usr/bin/env node
// Validasi konfigurasi build debug mobile RT05 TAKEDA.
// --self-test memeriksa input di proses ini. --plan menulis matriks CI.
// --build-debug hanya untuk CI, dari direktori mobile, dan tidak pernah --release.
import { spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { appendFileSync, copyFileSync, existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const FLAVORS = Object.freeze(['dev', 'staging', 'prod']);
// Sama dengan AppConfig.devHttpHosts dan network security config debug Android.
const DEV_HTTP_HOSTS = new Set(['10.0.2.2', '127.0.0.1']);
const DEFAULTS = Object.freeze({
    dev: 'http://10.0.2.2:8077',
    staging: 'https://takeda-staging.morriz.tech',
    prod: 'https://takeda.morriz.tech',
});

function hasWhitespaceOrControl(value) {
    return /[\u0000-\u001F\u007F]/.test(value) || /\s/u.test(value);
}

function canonicalPort(text) {
    if (!/^[0-9]+$/.test(text)) return null;
    const stripped = text.replace(/^0+/, '') || '0';
    if (stripped.length > 5) return null;
    const port = Number(stripped);
    if (!Number.isInteger(port) || port < 1 || port > 65535) return null;
    return port;
}

function splitAuthority(authority) {
    if (authority.startsWith('[')) {
        const match = /^\[([^\]]+)\](?::([0-9]+))?$/.exec(authority);
        if (!match || match[1].length === 0) return null;
        return { portText: match[2] ?? null };
    }
    const colon = authority.lastIndexOf(':');
    if (colon === -1) return authority.length === 0 ? null : { portText: null };
    const host = authority.slice(0, colon);
    const portText = authority.slice(colon + 1);
    if (host.length === 0 || !/^[0-9]+$/.test(portText)) return { portText: portText.length === 0 ? '' : portText, invalid: true };
    return { portText };
}

// Bentuk mentah wajib http(s)://authority atau dengan satu garis miring akhir.
// Pemeriksaan ini terjadi sebelum URL parser, karena WHATWG menormalisasi
// https:host, userinfo kosong, ?, #, backslash, dan /../ menjadi origin.
function validateBuild(flavor, apiBaseUrl) {
    if (typeof flavor !== 'string' || !FLAVORS.includes(flavor)) return { ok: false, error: 'flavor-invalid' };
    if (typeof apiBaseUrl !== 'string' || apiBaseUrl.length === 0 || apiBaseUrl.trim().length === 0) {
        return { ok: false, error: 'url-empty' };
    }
    if (hasWhitespaceOrControl(apiBaseUrl)) return { ok: false, error: 'url-whitespace' };
    if (apiBaseUrl.includes('\\')) return { ok: false, error: 'url-backslash' };
    if (apiBaseUrl.includes('@')) return { ok: false, error: 'url-userinfo' };
    if (apiBaseUrl.includes('?') || apiBaseUrl.includes('#')) return { ok: false, error: 'url-query-or-fragment' };
    if (!/^https?:\/\//i.test(apiBaseUrl)) {
        if (/^https?:/.test(apiBaseUrl)) return { ok: false, error: 'url-not-origin' };
        if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(apiBaseUrl)) return { ok: false, error: 'url-scheme' };
        return { ok: false, error: 'url-invalid' };
    }
    const raw = /^https?:\/\/([^/?#\\@]+)\/?$/i.exec(apiBaseUrl);
    if (!raw) return { ok: false, error: 'url-not-origin' };
    const authority = splitAuthority(raw[1]);
    if (!authority) return { ok: false, error: 'url-invalid' };
    if (authority.invalid || authority.portText === '') return { ok: false, error: 'url-port' };
    if (authority.portText !== null && canonicalPort(authority.portText) === null) return { ok: false, error: 'url-port' };
    let url;
    try {
        url = new URL(apiBaseUrl);
    } catch {
        return { ok: false, error: 'url-invalid' };
    }
    if (url.username !== '' || url.password !== '') return { ok: false, error: 'url-userinfo' };
    if (url.search !== '' || url.hash !== '') return { ok: false, error: 'url-query-or-fragment' };
    if (url.pathname !== '/') return { ok: false, error: 'url-not-origin' };
    if (url.protocol === 'http:') {
        // WHATWG menormalisasi alias numerik (127.1, 0x7f000001) menjadi localhost;
        // host dev mentah tetap harus sama dengan allowlist Android/Dart.
        if (flavor !== 'dev' || !DEV_HTTP_HOSTS.has(raw[1].split(':')[0])) return { ok: false, error: 'url-http-not-allowed' };
    } else if (url.protocol !== 'https:') {
        return { ok: false, error: 'url-scheme' };
    }
    if (url.hostname.length === 0 || url.origin === 'null') return { ok: false, error: 'url-invalid' };
    return { ok: true, flavor, apiBaseUrl: url.origin };
}

function planBuilds(env) {
    const event = env.RT05_EVENT || '';
    if (event === 'pull_request' || event === 'push') {
        const builds = [
            { flavor: 'dev', apiBaseUrl: DEFAULTS.dev },
            { flavor: 'staging', apiBaseUrl: DEFAULTS.staging },
        ];
        for (const build of builds) {
            const checked = validateBuild(build.flavor, build.apiBaseUrl);
            if (!checked.ok) return checked;
        }
        return { ok: true, builds };
    }
    if (event !== 'workflow_dispatch') return { ok: false, error: 'event-invalid' };
    const flavor = env.RT05_FLAVOR ?? '';
    const raw = env.RT05_API_BASE_URL ?? '';
    if (!FLAVORS.includes(flavor)) return { ok: false, error: 'flavor-invalid' };
    return validatePlan(flavor, raw === '' ? DEFAULTS[flavor] : raw);
}

function validatePlan(flavor, apiBaseUrl) {
    const checked = validateBuild(flavor, apiBaseUrl);
    if (!checked.ok) return checked;
    return { ok: true, builds: [{ flavor: checked.flavor, apiBaseUrl: checked.apiBaseUrl }] };
}

function debugArgs(checked) {
    return [
        'build',
        'apk',
        '--debug',
        `--dart-define=API_BASE_URL=${checked.apiBaseUrl}`,
        `--dart-define=FLAVOR=${checked.flavor}`,
    ];
}

function emitBuilds(builds, outputFile = process.env.GITHUB_OUTPUT) {
    const json = JSON.stringify(builds);
    if (!outputFile) {
        process.stdout.write(`${json}\n`);
        return json;
    }
    const delim = `RT05_${randomBytes(8).toString('hex')}`;
    appendFileSync(outputFile, `builds<<${delim}\n${json}\n${delim}\n`);
    return json;
}

function expect(failures, condition, label) {
    if (!condition) failures.push(label);
}

function selfTest() {
    const failures = [];
    const check = (flavor, url, error, normalized) => {
        const result = validateBuild(flavor, url);
        const id = failures.length;
        if (error) {
            expect(failures, !result.ok && result.error === error, `case-${id}-${error}-got-${result.ok ? 'ok' : result.error}`);
            return;
        }
        expect(
            failures,
            result.ok && result.flavor === flavor && result.apiBaseUrl === (normalized ?? url),
            `case-${id}-normalize`,
        );
    };

    check('dev', DEFAULTS.dev);
    check('dev', 'http://127.0.0.1:8077');
    check('dev', 'http://127.1:8077', 'url-http-not-allowed');
    check('dev', 'http://0x7f000001:8077', 'url-http-not-allowed');
    check('staging', 'HTTPS://takeda-staging.morriz.tech:443/', null, DEFAULTS.staging);
    check('staging', DEFAULTS.staging);
    check('prod', DEFAULTS.prod);
    check('dev', DEFAULTS.staging);
    check('staging', 'http://takeda-staging.morriz.tech', 'url-http-not-allowed');
    check('dev', 'http://example.com', 'url-http-not-allowed');
    check('dev', 'http://localhost:8077', 'url-http-not-allowed');
    check('dev', 'http://[::1]:8077', 'url-http-not-allowed');
    check('nope', DEFAULTS.staging, 'flavor-invalid');
    check('DEV', DEFAULTS.dev, 'flavor-invalid');
    check(' dev', DEFAULTS.dev, 'flavor-invalid');
    check('staging', 'https://sample:sample@takeda-staging.morriz.tech', 'url-userinfo');
    check('staging', 'https://takeda-staging.morriz.tech?x=1', 'url-query-or-fragment');
    check('staging', 'https://takeda-staging.morriz.tech#f', 'url-query-or-fragment');
    check('dev', '', 'url-empty');
    check('dev', '   ', 'url-empty');
    check('dev', 'http://10.0.2.2:8077\n', 'url-whitespace');
    check('staging', 'https://takeda-staging.morriz.tech /', 'url-whitespace');
    check('staging', 'https://takeda-staging.morriz.tech\u0001', 'url-whitespace');
    check('staging', 'https://takeda-staging.morriz.tech/api', 'url-not-origin');
    check('staging', 'https://takeda-staging.morriz.tech/', null, DEFAULTS.staging);
    check('staging', 'https://takeda-staging.morriz.tech:443', null, DEFAULTS.staging);
    check('staging', 'https://takeda-staging.morriz.tech:443/', null, DEFAULTS.staging);
    check('staging', 'https://takeda-staging.morriz.tech:8443', null, 'https://takeda-staging.morriz.tech:8443');
    check('staging', 'https://takeda-staging.morriz.tech:8443/', null, 'https://takeda-staging.morriz.tech:8443');
    check('staging', 'https://takeda-staging.morriz.tech:65535', null, 'https://takeda-staging.morriz.tech:65535');
    check('dev', 'http://10.0.2.2:8077/', null, DEFAULTS.dev);
    check('dev', 'http://127.0.0.1:80', null, 'http://127.0.0.1');
    check('prod', 'https://takeda.morriz.tech:443', null, DEFAULTS.prod);
    check('staging', 'https://takeda-staging.morriz.tech:0', 'url-port');
    check('staging', 'https://takeda-staging.morriz.tech:65536', 'url-port');
    check('staging', 'https://takeda-staging.morriz.tech:99999', 'url-port');
    check('staging', 'https://takeda-staging.morriz.tech?', 'url-query-or-fragment');
    check('staging', 'https://takeda-staging.morriz.tech#', 'url-query-or-fragment');
    check('staging', 'https://@takeda-staging.morriz.tech', 'url-userinfo');
    check('staging', 'https://:@takeda-staging.morriz.tech', 'url-userinfo');
    check('staging', 'https://takeda-staging.morriz.tech\\a', 'url-backslash');
    check('staging', 'https:\\\\takeda-staging.morriz.tech', 'url-backslash');
    check('staging', 'https://takeda-staging.morriz.tech/../', 'url-not-origin');
    check('staging', 'https://takeda-staging.morriz.tech/..', 'url-not-origin');
    check('staging', 'https:takeda-staging.morriz.tech', 'url-not-origin');
    check('staging', 'https:/takeda-staging.morriz.tech', 'url-not-origin');
    check('prod', 'http://10.0.2.2:8077', 'url-http-not-allowed');
    check('dev', 'ftp://10.0.2.2:8077', 'url-scheme');

    const ignored = planBuilds({ RT05_EVENT: 'pull_request', RT05_FLAVOR: 'prod', RT05_API_BASE_URL: 'http://example.com' });
    expect(failures, ignored.ok && ignored.builds.length === 2, 'pr-count');
    expect(failures, ignored.builds?.[0]?.flavor === 'dev' && ignored.builds?.[0]?.apiBaseUrl === DEFAULTS.dev, 'pr-dev');
    expect(failures, ignored.builds?.[1]?.flavor === 'staging' && ignored.builds?.[1]?.apiBaseUrl === DEFAULTS.staging, 'pr-staging');
    const pushed = planBuilds({ RT05_EVENT: 'push', RT05_FLAVOR: 'nope', RT05_API_BASE_URL: '' });
    expect(failures, JSON.stringify(pushed.builds) === JSON.stringify(ignored.builds), 'push-same');

    const stagingDefault = planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'staging', RT05_API_BASE_URL: '' });
    expect(failures, stagingDefault.ok && stagingDefault.builds.length === 1 && stagingDefault.builds[0].apiBaseUrl === DEFAULTS.staging, 'dispatch-staging');
    const prodDefault = planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'prod', RT05_API_BASE_URL: '' });
    expect(failures, prodDefault.ok && prodDefault.builds[0].flavor === 'prod' && prodDefault.builds[0].apiBaseUrl === DEFAULTS.prod, 'dispatch-prod');
    const slashDispatch = planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'staging', RT05_API_BASE_URL: `${DEFAULTS.staging}/` });
    expect(failures, slashDispatch.ok && slashDispatch.builds[0].apiBaseUrl === DEFAULTS.staging, 'dispatch-slash');
    const portDispatch = planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'staging', RT05_API_BASE_URL: `${DEFAULTS.staging}:443` });
    expect(failures, portDispatch.ok && portDispatch.builds[0].apiBaseUrl === DEFAULTS.staging, 'dispatch-port-443');
    expect(failures, planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'staging', RT05_API_BASE_URL: `${DEFAULTS.staging}:0` }).error === 'url-port', 'dispatch-port-0');
    expect(failures, planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'staging', RT05_API_BASE_URL: 'https:takeda-staging.morriz.tech' }).error === 'url-not-origin', 'dispatch-scheme-slash');
    expect(failures, planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'staging', RT05_API_BASE_URL: 'http://takeda-staging.morriz.tech' }).error === 'url-http-not-allowed', 'dispatch-http');
    expect(failures, planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'nope', RT05_API_BASE_URL: DEFAULTS.staging }).error === 'flavor-invalid', 'dispatch-flavor');
    expect(failures, planBuilds({ RT05_EVENT: 'workflow_dispatch', RT05_FLAVOR: 'dev', RT05_API_BASE_URL: 'http://example.com' }).error === 'url-http-not-allowed', 'dispatch-dev-public-http');
    const userinfo = planBuilds({
        RT05_EVENT: 'workflow_dispatch',
        RT05_FLAVOR: 'staging',
        RT05_API_BASE_URL: 'https://sample:sample@takeda-staging.morriz.tech',
    });
    expect(failures, userinfo.error === 'url-userinfo' && !JSON.stringify(userinfo).includes('sample:sample'), 'dispatch-userinfo');
    expect(failures, planBuilds({ RT05_EVENT: 'schedule' }).error === 'event-invalid', 'event');

    const stagingArgs = debugArgs(validateBuild('staging', `${DEFAULTS.staging}:443/`));
    expect(failures, stagingArgs.includes('--debug') && !stagingArgs.includes('--release'), 'args-debug');
    expect(failures, stagingArgs.includes(`--dart-define=FLAVOR=staging`) && stagingArgs.includes(`--dart-define=API_BASE_URL=${DEFAULTS.staging}`), 'args-flavor');
    expect(failures, validateBuild('staging', 'http://takeda-staging.morriz.tech').ok === false, 'args-withheld');

    const output = path.join(tmpdir(), `rt05-mobile-plan-${process.pid}.txt`);
    try {
        emitBuilds(ignored.builds, output);
        const text = readFileSync(output, 'utf8');
        expect(failures, text.startsWith('builds<<RT05_') && text.includes(JSON.stringify(ignored.builds)), 'github-output');
    } finally {
        if (existsSync(output)) unlinkSync(output);
    }

    if (failures.length > 0) {
        console.error(`self-test gagal: ${failures.join(', ')}`);
        return false;
    }
    console.log('self-test OK: pemeriksaan input build debug');
    return true;
}

function runPlan() {
    const result = planBuilds(process.env);
    if (!result.ok) {
        console.error(`konfigurasi build ditolak: ${result.error}`);
        process.exit(1);
    }
    emitBuilds(result.builds);
}

function buildDebug() {
    if (!existsSync('pubspec.yaml')) {
        console.error('--build-debug dijalankan dari direktori mobile');
        process.exit(1);
    }
    const result = validateBuild(process.env.RT05_FLAVOR ?? '', process.env.RT05_API_BASE_URL ?? '');
    if (!result.ok) {
        console.error(`konfigurasi build ditolak: ${result.error}`);
        process.exit(1);
    }
    const args = debugArgs(result);
    if (args.includes('--release') || !args.includes(`--dart-define=FLAVOR=${result.flavor}`)) {
        console.error('argumen build tidak konsisten');
        process.exit(1);
    }
    const proc = spawnSync('flutter', args, { stdio: 'inherit', shell: false });
    if (proc.error || proc.status !== 0) {
        if (proc.error) console.error('flutter tidak dapat dijalankan');
        process.exit(proc.status || 1);
    }
    const outDir = path.join('build', 'app', 'outputs', 'flutter-apk');
    const named = path.join(outDir, `rt05-takeda-${result.flavor}-debug.apk`);
    const apk = path.join(outDir, 'app-debug.apk');
    if (!existsSync(apk)) {
        console.error('apk debug tidak ditemukan');
        process.exit(1);
    }
    copyFileSync(apk, named);
    const hash = createHash('sha256').update(readFileSync(named)).digest('hex');
    writeFileSync(`${named}.sha256`, `${hash}  ${path.basename(named)}\n`);
}

const command = process.argv[2];
if (command === '--self-test') process.exit(selfTest() ? 0 : 1);
if (command === '--plan') runPlan();
else if (command === '--build-debug') buildDebug();
else {
    console.error('pemakaian: --self-test | --plan | --build-debug');
    process.exit(1);
}
