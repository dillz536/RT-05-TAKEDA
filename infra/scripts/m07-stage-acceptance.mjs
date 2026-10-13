#!/usr/bin/env node
/*
 * M07 synthetic LIVE acceptance — staging only (docs/evidence/m07/LIVE_PLAN.md).
 *
 * Node 22, standard fetch, tanpa dependency. Hanya memanggil
 * https://takeda-staging.morriz.tech/api/v1/*; host lain, redirect, dan production ditolak.
 * Tanpa --execute script hanya memvalidasi argumen/fixture lalu mencetak rencana (tanpa jaringan).
 *
 *   node infra/scripts/m07-stage-acceptance.mjs --sha <40hex> \
 *     --fixture C:/Users/USER/.rt05/m06-stage-check/fixtures.json \
 *     --out C:/Users/USER/.rt05/m07-stage-check [--execute] [--confirm-not-applied <stepId>]
 *
 * Fixture M06 hanya dibaca (accounts.admin); nilainya tidak pernah dicetak atau ditulis ulang.
 * Aktor M07 baru: email @example.test, kata sandi acak, nama/alamat bertanda "Sintetis M07".
 * State privat (resume-safe) dan fixture Android ditulis di --out (di luar Git). Ringkasan publik
 * berisi {case, routeTemplate, httpStatus, code, assertions} tanpa token/kata sandi/email/WA.
 * Tidak ada hapus/cleanup, logout-all, reset password, SMTP, factory, atau akses Neon langsung.
 */
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import { realpathSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ORIGIN = 'https://takeda-staging.morriz.tech';
const API = '/api/v1';
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const UUID_RE = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi;
const IS_UUID = v => typeof v === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v);
const STATE_FILE = 'm07-state.private.json';
const ANDROID_FILE = 'm07-android-fixtures.private.json';
const SUMMARY_FILE = 'm07-live-summary.json';
const REGISTRATION_WINDOW_MS = 600_000; // throttle registrasi: 5 per 10 menit per IP
const REGISTRATION_BURST = 4; // run ini butuh tepat 4 registrasi; sisakan 1 slot kuota
const LOGIN_WINDOW_MS = 60_000; // throttle login: 5 per menit per IP+email
const LOGIN_MAX_PER_WINDOW = 4;
const MAX_RETRY_AFTER_S = 900;
/** Semua kasus gerbang M07; ringkasan hanya "passed" bila semuanya tercatat lulus. */
const REQUIRED_CASES = `
C00-api-info C01-admin-login C02-admin-registration-token
register-p1 C04-primary-household C05-registration-history
register-a2 C07-pending-household-denied C08-pending-feed-denied C09-pending-dues-denied C10-pending-own-status
register-a1 approve-a1 C12-additional-profile C13-additional-household-read-only a1-household-patch-denied a1-member-add-denied
p1-patch-missing-versi p1-patch-ok p1-patch-stale p1-member-add p1-member-update p1-head-end-protected p1-member-end C20-ended-member-hidden
house-h2 house-h3 house-h4 family-k2 family-house-occupied C31-no-half-family C32-house-still-k2 family-k3 k2-verify k2-verify-stale
k2-member-add k2-member-update k2-member-end C38-ended-history-retained k2-member-move p1-idor-other-family
h2-owner C42-owner-separate C43-owner-admin-only C44-resident-view-without-owner
register-a3 approve-a3 C51-three-links-one-primary transfer-race-winner transfer-race-loser C53-exactly-one-active-primary old-primary-denied new-primary-writes
occ-k3-h3 occ-h3-occupied C61-h3-unchanged
dues-generate dues-generate-again C72-one-house-many-accounts-one-charge C73-h3-charge-before-move
occ-move occ-end C82-h3-empty-billing-kept C83-old-charge-stays C84-h4-charge-separate C85-empty-house-not-waived
loser-deactivate C91-deactivated-token-revoked C92-deactivated-login-denied loser-reactivate C94-reactivated-login
p1-archive C96-archived-token-revoked C97-archived-login-denied p1-archive-again C99-family-history-retained C100-k2-history-retained
`.trim().split(/\s+/);

class UsageError extends Error {}
class Uncertain extends Error {}
class CaseFailure extends Error {}

// ---------------------------------------------------------------- argumen dan path

function parseArgs(argv) {
  const args = { execute: false, confirmNotApplied: new Set() };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    const value = () => {
      const v = argv[++i];
      if (v === undefined || v.startsWith('--')) throw new UsageError(`${a} memerlukan nilai`);
      return v;
    };
    if (a === '--sha') args.sha = value();
    else if (a === '--fixture') args.fixture = value();
    else if (a === '--out') args.out = value();
    else if (a === '--execute') args.execute = true;
    else if (a === '--confirm-not-applied') args.confirmNotApplied.add(value());
    else if (a === '--help' || a === '-h') args.help = true;
    else throw new UsageError(`Argumen tidak dikenal: ${a}`);
  }
  if (args.help) return args;
  if (!/^[0-9a-f]{40}$/.test(args.sha ?? '')) throw new UsageError('--sha wajib 40 hex huruf kecil (SHA yang sudah diverifikasi root lewat SSH).');
  if (!args.fixture || !args.out) throw new UsageError('--fixture dan --out wajib.');
  if (!path.isAbsolute(args.fixture) || !path.isAbsolute(args.out)) throw new UsageError('--fixture dan --out harus path absolut.');
  return args;
}

function canonical(p) {
  let resolved = path.resolve(p);
  try { resolved = realpathSync.native(resolved); } catch { /* belum ada: pakai path resolve */ }
  return process.platform === 'win32' ? resolved.toLowerCase() : resolved;
}

function inside(child, parent) {
  const rel = path.relative(parent, child);
  return rel === '' || (!rel.startsWith('..') && !path.isAbsolute(rel));
}

function guardPaths(args) {
  const repo = canonical(REPO);
  const fixture = canonical(args.fixture);
  const out = canonical(args.out);
  if (inside(fixture, repo) || inside(out, repo)) throw new UsageError('Fixture dan output privat harus di luar repository Git.');
  if (inside(out, path.dirname(fixture))) throw new UsageError('--out harus folder terpisah dari folder fixture M06 (fixture M06 tidak disentuh).');
}

async function readAdminFixture(file) {
  const fixture = JSON.parse(await fs.readFile(file, 'utf8'));
  if (fixture.origin !== ORIGIN) throw new UsageError('Fixture bukan untuk staging yang diizinkan.');
  const admin = fixture.accounts?.admin;
  if (typeof admin?.email !== 'string' || typeof admin?.password !== 'string' || !admin.email || !admin.password) {
    throw new UsageError('Fixture tidak memuat accounts.admin.email/password.');
  }
  return { email: admin.email, password: admin.password };
}

// ---------------------------------------------------------------- state privat

let args;
let outDir;
let state;
const secrets = new Set();
const tokens = new Map(); // aktor -> bearer (hanya di memori)
const loginTimes = new Map();

function remember(...values) {
  for (const v of values) if (typeof v === 'string' && v.length >= 6) secrets.add(v);
}

async function writeAtomic(file, text) {
  const tmp = `${file}.${process.pid}.tmp`;
  await fs.writeFile(tmp, text, { mode: 0o600 });
  await fs.rename(tmp, file);
}

async function save() {
  state.updatedAt = new Date().toISOString();
  await writeAtomic(path.join(outDir, STATE_FILE), JSON.stringify(state, null, 2));
  await writeSummary();
}

async function loadState() {
  const file = path.join(outDir, STATE_FILE);
  try {
    const existing = JSON.parse(await fs.readFile(file, 'utf8'));
    if (existing.sourceSha !== args.sha) {
      throw new UsageError('State di --out milik SHA lain. Pakai folder --out baru untuk deployment baru.');
    }
    return existing;
  } catch (e) {
    if (e.code !== 'ENOENT') throw e;
  }
  const run = crypto.randomBytes(3).toString('hex').toUpperCase();
  const password = () => `M07${crypto.randomBytes(18).toString('base64url')}a9`;
  const actor = key => ({ email: `m07-${run.toLowerCase()}-${key}@example.test`, password: password() });
  return {
    schema: 'rt05-m07-live-state/v1',
    origin: ORIGIN,
    sourceSha: args.sha,
    run,
    createdAt: new Date().toISOString(),
    actors: { p1: actor('p1'), a1: actor('a1'), a2: actor('a2'), a3: actor('a3') },
    ids: {},
    steps: {},
    phases: {},
    cases: {},
    registrationTimes: [],
  };
}

// ---------------------------------------------------------------- ringkasan publik

function publicSafe(text) {
  const scanned = text.replaceAll(state.sourceSha, ''); // SHA publik tidak dianggap kebocoran kode 6 digit
  for (const s of secrets) if (scanned.includes(s)) return false;
  return !/@|Bearer |\d+\|[A-Za-z0-9]{20,}/.test(text);
}

async function writeSummary() {
  const cases = Object.values(state.cases).sort((a, b) => a.order - b.order).map(({ order, ...c }) => c);
  const failed = cases.some(c => c.result === 'failed');
  const allPhasesDone = PHASES.every(p => state.phases[p.name] === 'done');
  const missingCases = REQUIRED_CASES.filter(id => !state.cases[id]);
  const summary = {
    schema: 'rt05-m07-live-summary/v1',
    scope: 'synthetic staging only',
    origin: ORIGIN,
    apiBase: API,
    sourceSha: state.sourceSha,
    run: `Sintetis M07 ${state.run}`,
    startedAt: state.createdAt,
    updatedAt: state.updatedAt,
    status: failed ? 'failed' : allPhasesDone && missingCases.length === 0 ? 'passed' : 'incomplete',
    requiredCases: REQUIRED_CASES.length,
    recordedCases: cases.length,
    missingCases,
    phases: Object.fromEntries(PHASES.map(p => [p.name, state.phases[p.name] ?? 'pending'])),
    cases,
  };
  const text = JSON.stringify(summary, null, 2);
  if (!publicSafe(text)) throw new Error('Ringkasan publik memuat nilai rahasia; penulisan dibatalkan.');
  await writeAtomic(path.join(outDir, SUMMARY_FILE), text);
}

// ---------------------------------------------------------------- HTTP

const sleep = ms => new Promise(r => setTimeout(r, ms));

function buildUrl(route, params = {}, query) {
  const filled = route.replace(/\{(\w+)\}/g, (_, k) => {
    if (!IS_UUID(params[k])) throw new Error(`Parameter ${k} bukan UUID`);
    return params[k];
  });
  const url = new URL(API + filled, ORIGIN);
  for (const [k, v] of Object.entries(query ?? {})) url.searchParams.set(k, String(v));
  if (url.origin !== ORIGIN || url.protocol !== 'https:' || (!url.pathname.startsWith(`${API}/`) && url.pathname !== API)) {
    throw new Error('URL di luar staging API ditolak.');
  }
  return url;
}

/** Satu request. 429 (ditolak throttle sebelum handler) aman diulang; error jaringan pada write → Uncertain. */
async function http(method, route, { params, query, token, body } = {}) {
  const url = buildUrl(route, params, query);
  const write = method !== 'GET';
  for (let attempt = 1; ; attempt++) {
    let response;
    try {
      response = await fetch(url, {
        method,
        redirect: 'manual',
        signal: AbortSignal.timeout(30_000),
        headers: {
          Accept: 'application/json',
          'User-Agent': 'rt05-m07-synthetic-acceptance',
          ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch (e) {
      if (!write && attempt < 3) { await sleep(2000 * attempt); continue; }
      if (write) throw new Uncertain(`${method} ${route}: tanpa respons (${e.name}); hasil tulis tidak diketahui.`);
      throw new Error(`${method} ${route}: jaringan gagal (${e.name}).`);
    }
    if (response.status >= 300 && response.status < 400) throw new Error(`${method} ${route}: redirect ditolak.`);
    if (response.status === 429 && attempt <= 4) {
      const wait = Number(response.headers.get('retry-after')) || 60;
      if (wait > MAX_RETRY_AFTER_S) throw new Error(`${method} ${route}: Retry-After ${wait}s terlalu lama; ulangi nanti (resume aman).`);
      console.log(`  rate limit ${route}: tunggu ${wait + 1}s`);
      await sleep((wait + 1) * 1000);
      continue;
    }
    if (write && [502, 503, 504].includes(response.status)) {
      throw new Uncertain(`${method} ${route}: gateway ${response.status}; hasil tulis tidak diketahui.`);
    }
    const text = await response.text();
    let json = null;
    if (text) { try { json = JSON.parse(text); } catch { json = null; } }
    return {
      method, route, status: response.status, body: json,
      code: json?.error?.code ?? null,
      cacheControl: response.headers.get('cache-control') ?? '',
    };
  }
}

// ---------------------------------------------------------------- kasus dan checkpoint

let order = 0;

/** Assertion envelope API (docs/API.md): data+meta.request_id, error.code+request_id, atau 204 kosong. */
function envelopeAssertions(res, expect) {
  if (expect === 204) return [['204 tanpa body', res.body === null]];
  if (expect >= 400) {
    return [['error.code string', typeof res.body?.error?.code === 'string'],
      ['error.request_id string', typeof res.body?.error?.request_id === 'string']];
  }
  return [['data ada', res.body?.data !== undefined], ['meta.request_id string', typeof res.body?.meta?.request_id === 'string']];
}

function record(id, res, expect, { code, checks = [] } = {}) {
  const assertions = [[`status ${expect}`, res.status === expect], ...envelopeAssertions(res, expect)];
  if (code) assertions.push([`code ${code}`, Array.isArray(code) ? code.includes(res.code) : res.code === code]);
  for (const [name, fn] of checks) {
    let ok = false;
    try { ok = res.status === expect && Boolean(fn(res.body)); } catch { ok = false; }
    assertions.push([name, ok]);
  }
  const passed = assertions.every(([, ok]) => ok);
  state.cases[id] = {
    order: state.cases[id]?.order ?? ++order,
    case: id,
    routeTemplate: `${res.method} ${API}${res.route}`,
    httpStatus: res.status,
    code: res.status >= 400 ? res.code : null,
    assertions: assertions.map(([name, ok]) => ({ name, result: ok ? 'passed' : 'failed' })),
    result: passed ? 'passed' : 'failed',
  };
  console.log(`${passed ? 'PASS' : 'FAIL'} ${id} ${res.method} ${res.route} ${res.status}${res.code ? ` ${res.code}` : ''}`);
  if (!passed) throw new CaseFailure(`Kasus ${id} gagal.`);
  return res.body;
}

/** Baca (idempotent): request + assertion. */
async function check(id, method, route, opts, expect, assertOpts) {
  const res = await http(method, route, opts);
  return record(id, res, expect, assertOpts);
}

/**
 * Checkpoint tulis. Langkah selesai tidak diulang. Langkah "pending" (tanpa respons) diselesaikan
 * oleh reconciler baca-saja bila ada; selain itu operator memeriksa lalu memakai
 * --confirm-not-applied <stepId>. Respons 2xx yang gagal assertion ditandai failed dan tidak diulang.
 */
async function once(stepId, run, reconcile) {
  const s = state.steps[stepId];
  if (s?.status === 'done') return { result: s.result, fresh: false };
  if (s?.status === 'failed' && !args.confirmNotApplied.has(stepId)) {
    throw new CaseFailure(`Langkah ${stepId} pernah gagal; tinjau lewat admin, lalu --confirm-not-applied ${stepId} bila tulisannya tidak terjadi.`);
  }
  if (s?.status === 'failed' || s?.status === 'pending') {
    if (args.confirmNotApplied.has(stepId)) {
      delete state.steps[stepId];
    } else if (reconcile) {
      const found = await reconcile();
      if (found) {
        state.steps[stepId] = { status: 'done', result: found, reconciled: true, at: new Date().toISOString() };
        await save();
        return { result: found, fresh: false };
      }
      delete state.steps[stepId];
    } else {
      throw new Uncertain(`Langkah ${stepId} tidak pasti dari run sebelumnya. Periksa lewat admin; bila tidak terjadi, ulangi dengan --confirm-not-applied ${stepId}.`);
    }
  }
  state.steps[stepId] = { status: 'pending', at: new Date().toISOString() };
  await save();
  try {
    const result = (await run()) ?? {};
    state.steps[stepId] = { status: 'done', result, at: new Date().toISOString() };
    await save();
    return { result, fresh: true };
  } catch (e) {
    if (e instanceof Uncertain) { await save(); throw e; }
    state.steps[stepId] = { status: e instanceof CaseFailure ? 'failed' : 'pending', at: new Date().toISOString() };
    await save();
    throw e;
  }
}

/** Tulis yang diharapkan ditolak (4xx) atau berhasil, sebagai satu checkpoint + kasus. */
async function write(stepId, method, route, opts, expect, assertOpts = {}, keep = () => ({}), reconcile) {
  return once(stepId, async () => {
    const res = await http(method, route, typeof opts === 'function' ? await opts() : opts);
    const body = record(stepId, res, expect, assertOpts);
    return keep(body);
  }, reconcile);
}

// ---------------------------------------------------------------- aktor dan throttle

async function paceLogin(email) {
  const now = Date.now();
  const recent = (loginTimes.get(email) ?? []).filter(t => now - t < LOGIN_WINDOW_MS);
  if (recent.length >= LOGIN_MAX_PER_WINDOW) {
    const wait = LOGIN_WINDOW_MS - (now - recent[0]) + 1000;
    console.log(`  jeda login ${Math.ceil(wait / 1000)}s (throttle 5/menit)`);
    await sleep(wait);
  }
  loginTimes.set(email, [...recent, Date.now()]);
}

async function loginRaw(creds) {
  await paceLogin(creds.email);
  return http('POST', '/auth/mobile-login', { body: { email: creds.email, password: creds.password, device_name: 'M07 synthetic acceptance' } });
}

/** Bearer aktor (login baru bila belum ada di memori). Login biasa, bukan logout-all. */
async function tokenFor(key) {
  if (tokens.has(key)) return tokens.get(key);
  const creds = key === 'admin' ? adminCreds : state.actors[key];
  const res = await loginRaw(creds);
  if (res.status !== 200 || typeof res.body?.data?.token !== 'string') throw new CaseFailure(`Login ${key} gagal (${res.status} ${res.code ?? ''}).`);
  remember(res.body.data.token);
  tokens.set(key, res.body.data.token);
  return res.body.data.token;
}

/** Sadar kuota: maksimal 4 registrasi per jendela 10 menit dari run ini; 429 tetap mengikuti Retry-After. */
async function paceRegistration() {
  const recent = state.registrationTimes.map(Date.parse).filter(t => Date.now() - t < REGISTRATION_WINDOW_MS);
  if (recent.length >= REGISTRATION_BURST) {
    const wait = REGISTRATION_WINDOW_MS - (Date.now() - Math.min(...recent)) + 1000;
    console.log(`  jeda registrasi ${Math.ceil(wait / 1000)}s (kuota 5/10 menit per IP)`);
    await sleep(wait);
  }
  state.registrationTimes.push(new Date().toISOString());
  await save();
}

function currentPeriod() {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit' }).formatToParts(new Date());
  return `${parts.find(p => p.type === 'year').value}-${parts.find(p => p.type === 'month').value}`;
}

const names = () => {
  const r = state.run;
  return {
    jalan: `Jl. Sintetis M07 ${r}`,
    kepala1: `Kepala Sintetis M07 ${r}`,
    anak1: `Anak Sintetis M07 ${r}`,
    saudara1: `Saudara Sintetis M07 ${r}`,
    cucu1: `Cucu Sintetis M07 ${r}`,
    akunSaudara: `Saudara Akun Sintetis M07 ${r}`,
    tunggu: `Tunggu Sintetis M07 ${r}`,
    kepala2: `Kepala Dua Sintetis M07 ${r}`,
    anggota2: `Anggota Dua Sintetis M07 ${r}`,
    akhir2: `Anggota Akhir Sintetis M07 ${r}`,
    gagal: `Gagal Sintetis M07 ${r}`,
    kepala3: `Kepala Tiga Sintetis M07 ${r}`,
    pemilik: `Pemilik Sintetis M07 ${r}`,
  };
};

const activeLinks = fam => (fam.akun ?? []).filter(t => t.selesai_at === null);
const hasKeyDeep = (v, key) => v && typeof v === 'object' && (Object.hasOwn(v, key) || Object.values(v).some(x => hasKeyDeep(x, key)));

async function household(key) {
  const res = await http('GET', '/household', { token: await tokenFor(key) });
  if (res.status !== 200) throw new CaseFailure(`GET /household ${key} ${res.status}`);
  return res.body.data;
}

async function adminGet(route, params, query) {
  const res = await http('GET', route, { token: await tokenFor('admin'), params, query });
  if (res.status !== 200) throw new CaseFailure(`GET ${route} ${res.status}`);
  return res.body;
}

async function registerStep(stepId, key, route, body, expect, assertOpts) {
  const creds = state.actors[key];
  const reconcile = async () => {
    const res = await loginRaw(creds);
    if (res.status !== 200) return null; // akun tidak ada → registrasi tidak terjadi
    remember(res.body.data.token);
    tokens.set(key, res.body.data.token);
    const a = res.body.data.akun;
    return { akunId: a.id, keluargaId: a.keluarga_id, rumahId: a.rumah?.id ?? null };
  };
  return once(stepId, async () => {
    await paceRegistration();
    const token = (await adminRegistrationCode());
    const res = await http('POST', route, {
      body: { ...body, email: creds.email, password: creds.password, password_confirmation: creds.password, device_name: 'M07 synthetic acceptance', registration_token: token },
    });
    const data = record(stepId, res, expect, assertOpts)?.data;
    remember(data.token);
    tokens.set(key, data.token);
    return { akunId: data.akun.id, keluargaId: data.akun.keluarga_id, rumahId: data.akun.rumah?.id ?? null };
  }, reconcile);
}

let registrationCode;
async function adminRegistrationCode() {
  if (registrationCode) return registrationCode;
  const body = await check('C02-admin-registration-token', 'GET', '/admin/registration-token', { token: await tokenFor('admin') }, 200, {
    checks: [['kode enam digit', b => /^[0-9]{6}$/.test(b.data.kode)]],
  });
  registrationCode = body.data.kode;
  remember(registrationCode);
  return registrationCode;
}

async function findRequestByEmail(email) {
  for (let page = 1; page <= 20; page++) {
    const body = await adminGet('/admin/additional-account-requests', undefined, { status: 'diajukan', per_page: 100, page });
    const found = body.data.find(p => p.email === email);
    if (found) return found;
    if (page >= body.meta.last_page) return null;
  }
  return null;
}

async function approveStep(stepId, key, extra) {
  const creds = state.actors[key];
  const akunId = state.steps[`register-${key}`].result.akunId;
  const reconcile = async () => {
    const detail = await adminGet('/admin/accounts/{id}', { id: akunId });
    return detail.data.status === 'aktif' && detail.data.permohonan?.status === 'disetujui' ? { approved: true } : null;
  };
  return once(stepId, async () => {
    const request = await findRequestByEmail(creds.email);
    if (!request) throw new CaseFailure(`Permohonan ${key} tidak ditemukan pada antrean admin.`);
    const res = await http('POST', '/admin/additional-account-requests/{id}/approve', {
      token: await tokenFor('admin'), params: { id: request.id },
      body: { rumah_id: state.ids.rumah1, catatan: 'Persetujuan sintetis M07', ...extra() },
    });
    record(stepId, res, 200, { checks: [['permohonan disetujui', b => b.data.status === 'disetujui']] });
    tokens.delete(key); // token pending lama tetap sah; login ulang mengambil profil aktif
    return { approved: true };
  }, reconcile);
}

const adminOpts = async extra => ({ token: await tokenFor('admin'), ...extra });

// ---------------------------------------------------------------- fase

const PHASES = [
  {
    name: 'preflight',
    cases: ['C00 info API', 'C01 login admin fixture', 'C02 token registrasi admin no-store'],
    async run() {
      await check('C00-api-info', 'GET', '', {}, 200, { checks: [['api_version v1', b => b.data.api_version === 'v1']] });
      const res = await loginRaw(adminCreds);
      record('C01-admin-login', res, 200, {
        checks: [['peran pengurus aktif', b => b.data.akun.peran === 'pengurus' && b.data.akun.status === 'aktif'], ['token Bearer', b => b.data.token_type === 'Bearer']],
      });
      remember(res.body.data.token);
      tokens.set('admin', res.body.data.token);
      const tokenRes = await http('GET', '/admin/registration-token', { token: tokens.get('admin') });
      record('C02-admin-registration-token', tokenRes, 200, {
        checks: [['kode enam digit', b => /^[0-9]{6}$/.test(b.data.kode)], ['Cache-Control no-store', () => tokenRes.cacheControl.includes('no-store')]],
      });
      registrationCode = tokenRes.body.data.kode;
      remember(registrationCode);
    },
  },
  {
    name: 'primary-register',
    cases: ['register-p1 201 utama aktif', 'C04 household 3 anggota', 'C05 keanggotaan+tautan registrasi'],
    async run() {
      const n = names();
      const { result } = await registerStep('register-p1', 'p1', '/auth/register', {
        nama_kepala: n.kepala1, whatsapp: null, blok: 'S7', jalan: n.jalan, nomor: '1', jenis_hunian: 'pemilik',
        anggota: [{ nama: n.anak1, hubungan_keluarga: 'Anak' }, { nama: n.saudara1, hubungan_keluarga: 'Saudara' }],
      }, 201, {
        checks: [
          ['akun utama aktif warga', b => b.data.akun.jenis === 'utama' && b.data.akun.status === 'aktif' && b.data.akun.peran === 'warga'],
          ['dapat ubah keluarga', b => b.data.akun.dapat_ubah_keluarga === true],
          ['keluarga dan rumah dari tautan', b => IS_UUID(b.data.akun.keluarga_id) && IS_UUID(b.data.akun.rumah?.id)],
          ['token Bearer', b => b.data.token_type === 'Bearer' && typeof b.data.token === 'string'],
        ],
      });
      state.ids.keluarga1 = result.keluargaId;
      state.ids.rumah1 = result.rumahId;
      const hh = await check('C04-primary-household', 'GET', '/household', { token: await tokenFor('p1') }, 200, {
        checks: [
          ['dapat_ubah true', b => b.data.dapat_ubah === true],
          ['belum_verifikasi', b => b.data.status === 'belum_verifikasi'],
          ['3 anggota, 1 kepala', b => b.data.anggota.length === 3 && b.data.anggota.filter(w => w.is_kepala).length === 1],
          ['kepala punya akun', b => b.data.anggota.find(w => w.is_kepala).punya_akun === true],
          ['versi integer', b => Number.isInteger(b.data.versi)],
        ],
      });
      state.ids.kepala1 = hh.data.anggota.find(w => w.is_kepala).id;
      state.ids.anak1 = hh.data.anggota.find(w => w.nama === n.anak1).id;
      await check('C05-registration-history', 'GET', '/admin/families/{id}', await adminOpts({ params: { id: state.ids.keluarga1 } }), 200, {
        checks: [
          ['3 keanggotaan aktif sumber registrasi', b => b.data.riwayat_keanggotaan.filter(m => m.selesai_at === null && m.sumber === 'registrasi').length === 3],
          ['1 tautan utama aktif registrasi milik akun', b => {
            const links = activeLinks(b.data);
            return links.length === 1 && links[0].is_utama === true && links[0].sumber === 'registrasi' && links[0].akun.id === result.akunId;
          }],
        ],
      });
      await save();
    },
  },
  {
    name: 'additional-pending',
    cases: ['register-a2 202 menunggu', 'C07-C09 household/feed/iuran 403', 'C10 status permohonan diajukan'],
    async run() {
      const n = names();
      await registerStep('register-a2', 'a2', '/auth/additional-register', {
        nama: n.tunggu, hubungan_keluarga: 'Saudara', blok: 'S7', jalan: n.jalan, nomor: '1',
      }, 202, {
        checks: [['akun menunggu tanpa keluarga', b => b.data.akun.status === 'menunggu' && b.data.akun.keluarga_id === null && b.data.akun.dapat_ubah_keluarga === false]],
      });
      const token = await tokenFor('a2');
      await check('C07-pending-household-denied', 'GET', '/household', { token }, 403, { code: 'FORBIDDEN' });
      await check('C08-pending-feed-denied', 'GET', '/announcements', { token }, 403, { code: 'FORBIDDEN' });
      await check('C09-pending-dues-denied', 'GET', '/dues/my', { token }, 403, { code: 'FORBIDDEN' });
      await check('C10-pending-own-status', 'GET', '/auth/additional-account-status', { token }, 200, {
        checks: [['permohonan diajukan', b => b.data.permohonan.status === 'diajukan']],
      });
    },
  },
  {
    name: 'approve-existing-member',
    cases: ['register-a1 202', 'approve-a1 warga_id existing', 'C12 profil tambahan aktif', 'C13 household baca saja tanpa warga ganda', 'a1 tulis 403 PRIMARY_ACCOUNT_REQUIRED'],
    async run() {
      const n = names();
      await registerStep('register-a1', 'a1', '/auth/additional-register', {
        nama: n.anak1, hubungan_keluarga: 'Anak', blok: 'S7', jalan: n.jalan, nomor: '1',
      }, 202, { checks: [['akun menunggu', b => b.data.akun.status === 'menunggu']] });
      await approveStep('approve-a1', 'a1', () => ({ warga_id: state.ids.anak1 }));
      const res = await loginRaw(state.actors.a1);
      record('C12-additional-profile', res, 200, {
        checks: [['tambahan aktif keluarga sama, tanpa hak ubah', b => b.data.akun.status === 'aktif' && b.data.akun.jenis === 'tambahan'
          && b.data.akun.keluarga_id === state.ids.keluarga1 && b.data.akun.dapat_ubah_keluarga === false]],
      });
      remember(res.body.data.token);
      tokens.set('a1', res.body.data.token);
      const hh = await check('C13-additional-household-read-only', 'GET', '/household', { token: tokens.get('a1') }, 200, {
        checks: [
          ['dapat_ubah false', b => b.data.dapat_ubah === false],
          ['tanpa warga ganda', b => b.data.anggota.length === 3],
          ['anggota existing kini punya akun', b => b.data.anggota.find(w => w.id === state.ids.anak1)?.punya_akun === true],
        ],
      });
      await write('a1-household-patch-denied', 'PATCH', '/household', async () => ({ token: await tokenFor('a1'), body: { versi: hh.data.versi, nama_kepala: 'Tidak boleh berubah Sintetis M07' } }),
        403, { code: 'PRIMARY_ACCOUNT_REQUIRED' });
      await write('a1-member-add-denied', 'POST', '/household/members', async () => ({ token: await tokenFor('a1'), body: { nama: 'Tidak boleh Sintetis M07', hubungan_keluarga: 'Anak', versi: hh.data.versi } }),
        403, { code: 'PRIMARY_ACCOUNT_REQUIRED' });
    },
  },
  {
    name: 'primary-edit-versioning',
    cases: ['versi hilang 422', 'edit 200 versi naik', 'versi basi 409', 'anggota tambah/ubah/akhiri', 'kepala dilindungi 422'],
    async run() {
      const n = names();
      const p1 = async body => ({ token: await tokenFor('p1'), body: typeof body === 'function' ? await body() : body });
      const versi = async () => (await household('p1')).versi;
      await write('p1-patch-missing-versi', 'PATCH', '/household', () => p1({ nama_kepala: n.kepala1 }), 422, {
        code: 'VALIDATION_FAILED', checks: [['fields.versi', b => Array.isArray(b.error.fields?.versi)]],
      });
      let before;
      await write('p1-patch-ok', 'PATCH', '/household', () => p1(async () => ({ versi: (before = await versi()), nama_kepala: `${n.kepala1} Ubah` })), 200, {
        checks: [['versi naik satu', b => b.data.versi === before + 1], ['dapat_ubah true', b => b.data.dapat_ubah === true]],
      });
      await write('p1-patch-stale', 'PATCH', '/household', () => p1(async () => ({ versi: (await versi()) - 1, nama_kepala: 'Basi Sintetis M07' })), 409, { code: 'VERSION_CONFLICT' });
      const { result: cucu } = await write('p1-member-add', 'POST', '/household/members',
        () => p1(async () => ({ nama: n.cucu1, hubungan_keluarga: 'Cucu', versi: await versi() })), 201,
        { checks: [['warga baru + versi_keluarga', b => IS_UUID(b.data.id) && Number.isInteger(b.data.versi_keluarga)]] },
        b => ({ wargaId: b.data.id }),
        async () => { const w = (await household('p1')).anggota.find(x => x.nama === n.cucu1); return w ? { wargaId: w.id } : null; });
      await write('p1-member-update', 'PATCH', '/household/members/{id}',
        async () => ({ token: await tokenFor('p1'), params: { id: cucu.wargaId }, body: { hubungan_keluarga: 'Cucu kandung', versi: await versi() } }), 200,
        { checks: [['hubungan berubah', b => b.data.hubungan_keluarga === 'Cucu kandung']] });
      await write('p1-head-end-protected', 'DELETE', '/household/members/{id}',
        async () => ({ token: await tokenFor('p1'), params: { id: state.ids.kepala1 }, body: { versi: await versi() } }), 422, { code: 'MEMBER_PROTECTED' });
      await write('p1-member-end', 'DELETE', '/household/members/{id}',
        async () => ({ token: await tokenFor('p1'), params: { id: cucu.wargaId }, body: { status: 'pindah', versi: await versi() } }), 204);
      await check('C20-ended-member-hidden', 'GET', '/household', { token: await tokenFor('p1') }, 200, {
        checks: [['anggota diakhiri tidak aktif', b => !b.data.anggota.some(w => w.id === cucu.wargaId)]],
      });
    },
  },
  {
    name: 'admin-families',
    cases: ['rumah H2-H4', 'keluarga+hunian atomik', 'rumah dihuni 409 tanpa keluarga setengah', 'verifikasi + basi 409', 'anggota tambah/ubah/akhiri/pindah', 'IDOR 404', 'pemilik terpisah dan privat'],
    async run() {
      const n = names();
      const period = currentPeriod();
      for (const nomor of ['2', '3', '4']) {
        const kode = `M07-${state.run}-${nomor}`;
        const { result } = await write(`house-h${nomor}`, 'POST', '/admin/houses', await adminOpts({
          body: { blok: 'S7', jalan: n.jalan, nomor, kode_rumah: kode, tagihan_aktif: true, mulai_tagih: period },
        }), 201, { checks: [['kode tetap, versi 1, tagihan aktif', b => b.data.kode_rumah === kode && b.data.versi === 1 && b.data.tagihan_aktif === true]] },
        b => ({ id: b.data.id }),
        async () => { const f = (await adminGet('/admin/houses', undefined, { q: kode, per_page: 100 })).data.find(h => h.kode_rumah === kode); return f ? { id: f.id } : null; });
        state.ids[`rumah${nomor}`] = result.id;
      }
      const familyByName = async nama => {
        const body = await adminGet('/admin/families', undefined, { q: nama, per_page: 100 });
        return body.data.find(k => k.kepala === nama) ?? null;
      };
      const { result: k2 } = await write('family-k2', 'POST', '/admin/families', await adminOpts({
        body: { nama_kepala: n.kepala2, anggota: [{ nama: n.anggota2, hubungan_keluarga: 'Anak' }], rumah_id: state.ids.rumah2, jenis_hunian: 'kontrak' },
      }), 201, {
        checks: [
          ['hunian aktif di rumah H2', b => b.data.rumah?.id === state.ids.rumah2 && b.data.penghunian[0]?.aktif === true],
          ['2 anggota, belum_verifikasi', b => b.data.anggota.length === 2 && b.data.status === 'belum_verifikasi'],
        ],
      }, b => ({ id: b.data.id }), async () => { const f = await familyByName(n.kepala2); return f ? { id: f.id } : null; });
      state.ids.keluarga2 = k2.id;
      await write('family-house-occupied', 'POST', '/admin/families', await adminOpts({
        body: { nama_kepala: n.gagal, rumah_id: state.ids.rumah2, jenis_hunian: 'pemilik' },
      }), 409, { code: 'HOUSE_OCCUPIED' });
      await check('C31-no-half-family', 'GET', '/admin/families', await adminOpts({ query: { q: n.gagal, per_page: 100 } }), 200, {
        checks: [['keluarga gagal tidak tersimpan', b => b.meta.total === 0]],
      });
      await check('C32-house-still-k2', 'GET', '/admin/houses/{id}', await adminOpts({ params: { id: state.ids.rumah2 } }), 200, {
        checks: [['H2 tetap dihuni K2 saja', b => b.data.keluarga?.id === k2.id && b.data.riwayat_penghunian.filter(p => p.selesai_tanggal === null).length === 1]],
      });
      const { result: k3 } = await write('family-k3', 'POST', '/admin/families', await adminOpts({ body: { nama_kepala: n.kepala3 } }), 201,
        { checks: [['tanpa rumah', b => b.data.rumah === null && b.data.anggota.length === 1]] },
        b => ({ id: b.data.id }), async () => { const f = await familyByName(n.kepala3); return f ? { id: f.id } : null; });
      state.ids.keluarga3 = k3.id;
      const famVersi = async id => (await adminGet('/admin/families/{id}', { id })).data.versi;
      let v2;
      await write('k2-verify', 'POST', '/admin/families/{id}/verify', async () => adminOpts({ params: { id: k2.id }, body: { versi: (v2 = await famVersi(k2.id)) } }), 200, {
        checks: [['terverifikasi, versi naik', b => b.data.status === 'terverifikasi' && b.data.versi === v2 + 1]],
      });
      await write('k2-verify-stale', 'POST', '/admin/families/{id}/verify', async () => adminOpts({ params: { id: k2.id }, body: { versi: (await famVersi(k2.id)) - 1 } }), 409, { code: 'VERSION_CONFLICT' });
      const memberByName = async (kid, nama) => (await adminGet('/admin/families/{id}', { id: kid })).data.anggota.find(w => w.nama === nama) ?? null;
      const { result: akhir } = await write('k2-member-add', 'POST', '/admin/families/{id}/members',
        async () => adminOpts({ params: { id: k2.id }, body: { nama: n.akhir2, hubungan_keluarga: 'Saudara', versi: await famVersi(k2.id) } }), 201,
        { checks: [['anggota K2', b => b.data.keluarga_id === k2.id]] },
        b => ({ id: b.data.id }), async () => { const w = await memberByName(k2.id, n.akhir2); return w ? { id: w.id } : null; });
      await write('k2-member-update', 'PATCH', '/admin/residents/{id}',
        async () => adminOpts({ params: { id: akhir.id }, body: { hubungan_keluarga: 'Saudara sepupu', versi: await famVersi(k2.id) } }), 200,
        { checks: [['hubungan berubah', b => b.data.hubungan_keluarga === 'Saudara sepupu']] });
      await write('k2-member-end', 'POST', '/admin/residents/{id}/end',
        async () => adminOpts({ params: { id: akhir.id }, body: { status: 'pindah', alasan: 'Uji sintetis M07', versi: await famVersi(k2.id) } }), 200, {
          checks: [['diarsipkan pindah tanpa keluarga aktif', b => b.data.status === 'pindah' && b.data.keluarga_id === null && b.data.archived_at !== null]],
        });
      await check('C38-ended-history-retained', 'GET', '/admin/residents/{id}', await adminOpts({ params: { id: akhir.id } }), 200, {
        checks: [['histori keanggotaan ditutup pindah', b => b.data.riwayat_keanggotaan.length === 1 && b.data.riwayat_keanggotaan[0].alasan_selesai === 'pindah' && b.data.riwayat_keanggotaan[0].selesai_at !== null]],
      });
      const anggota2 = (await memberByName(k2.id, n.anggota2)) ?? (await memberByName(k3.id, n.anggota2));
      await write('k2-member-move', 'POST', '/admin/residents/{id}/move',
        async () => adminOpts({ params: { id: anggota2.id }, body: { keluarga_id: k3.id, hubungan_keluarga: 'Saudara', alasan: 'Uji sintetis M07', versi: await famVersi(k2.id) } }), 200, {
          checks: [['pindah ke K3 dengan histori', b => b.data.keluarga_id === k3.id && b.data.riwayat_keanggotaan.length === 2
            && b.data.riwayat_keanggotaan.some(m => m.keluarga_id === k2.id && m.alasan_selesai === 'pindah_keluarga')]],
        });
      const kepala2 = (await adminGet('/admin/families/{id}', { id: k2.id })).data.anggota.find(w => w.is_kepala);
      await write('p1-idor-other-family', 'PATCH', '/household/members/{id}',
        async () => ({ token: await tokenFor('p1'), params: { id: kepala2.id }, body: { nama: 'IDOR Sintetis M07', versi: (await household('p1')).versi } }), 404, { code: 'NOT_FOUND' });
      const houseVersi = async id => (await adminGet('/admin/houses/{id}', { id })).data.versi;
      await write('h2-owner', 'PATCH', '/admin/houses/{id}',
        async () => adminOpts({ params: { id: state.ids.rumah2 }, body: { pemilik: { nama: n.pemilik, catatan: 'Pemilik sintetis M07' }, alasan: 'Uji sintetis M07', versi: await houseVersi(state.ids.rumah2) } }), 200);
      await check('C42-owner-separate', 'GET', '/admin/houses/{id}', await adminOpts({ params: { id: state.ids.rumah2 } }), 200, {
        checks: [['pemilik tercatat terpisah dari kepala penghuni', b => b.data.pemilik?.nama === n.pemilik && b.data.keluarga?.id === k2.id && kepala2.nama !== n.pemilik]],
      });
      await check('C43-owner-admin-only', 'GET', '/admin/houses/{id}', { token: await tokenFor('p1'), params: { id: state.ids.rumah2 } }, 403, { code: 'FORBIDDEN' });
      await check('C44-resident-view-without-owner', 'GET', '/household', { token: await tokenFor('p1') }, 200, {
        checks: [['tanpa field pemilik', b => !hasKeyDeep(b, 'pemilik')]],
      });
    },
  },
  {
    name: 'primary-transfer',
    cases: ['register/approve a3', 'dua target bersamaan versi sama → 200 + 409', 'tepat satu utama aktif', 'utama lama 403, utama baru 200'],
    async run() {
      const n = names();
      await registerStep('register-a3', 'a3', '/auth/additional-register', {
        nama: n.akunSaudara, hubungan_keluarga: 'Saudara', blok: 'S7', jalan: n.jalan, nomor: '1',
      }, 202, { checks: [['akun menunggu', b => b.data.akun.status === 'menunggu']] });
      await approveStep('approve-a3', 'a3', () => ({}));
      const k1 = state.ids.keluarga1;
      const ids = { a1: state.steps['register-a1'].result.akunId, a3: state.steps['register-a3'].result.akunId, p1: state.steps['register-p1'].result.akunId };
      await check('C51-three-links-one-primary', 'GET', '/admin/families/{id}', await adminOpts({ params: { id: k1 } }), 200, {
        checks: [['3 tautan aktif, utama = p1', b => activeLinks(b.data).length === 3 && activeLinks(b.data).filter(t => t.is_utama).map(t => t.akun.id).join() === ids.p1]],
      });
      const { result: race } = await once('transfer-race', async () => {
        const versi = (await adminGet('/admin/families/{id}', { id: k1 })).data.versi;
        const token = await tokenFor('admin');
        const fire = target => http('POST', '/admin/families/{id}/primary-account', { token, params: { id: k1 }, body: { akun_id: ids[target], versi, alasan: 'Uji sintetis M07 bersamaan' } });
        const [r1, r3] = await Promise.all([fire('a1'), fire('a3')]);
        const winner = r1.status === 200 ? 'a1' : r3.status === 200 ? 'a3' : null;
        const statuses = [r1.status, r3.status].sort().join(',');
        const loserRes = winner === 'a1' ? r3 : r1;
        const res = winner === 'a1' ? r1 : r3;
        record('transfer-race-winner', res, 200, { checks: [['tepat satu 200 dan satu 409', () => statuses === '200,409']] });
        record('transfer-race-loser', loserRes, 409, { code: ['VERSION_CONFLICT', 'PRIMARY_ACCOUNT_EXISTS'] });
        return { winner, loser: winner === 'a1' ? 'a3' : 'a1' };
      });
      state.ids.primaryWinner = race.winner;
      state.ids.primaryLoser = race.loser;
      await check('C53-exactly-one-active-primary', 'GET', '/admin/families/{id}', await adminOpts({ params: { id: k1 } }), 200, {
        checks: [
          ['tepat satu utama aktif = pemenang', b => activeLinks(b.data).filter(t => t.is_utama).map(t => t.akun.id).join() === ids[race.winner]],
          ['tiga tautan aktif tetap', b => activeLinks(b.data).length === 3],
          ['tautan utama lama ditutup pergantian_utama', b => b.data.akun.some(t => t.akun.id === ids.p1 && t.is_utama && t.alasan_selesai === 'pergantian_utama')],
        ],
      });
      tokens.delete(race.winner);
      await write('old-primary-denied', 'PATCH', '/household',
        async () => ({ token: await tokenFor('p1'), body: { versi: (await household('p1')).versi, nama_kepala: 'Utama lama Sintetis M07' } }), 403, { code: 'PRIMARY_ACCOUNT_REQUIRED' });
      await write('new-primary-writes', 'PATCH', '/household',
        async () => ({ token: await tokenFor(race.winner), body: { versi: (await household(race.winner)).versi, nama_kepala: `${n.kepala1} Utama Baru` } }), 200,
        { checks: [['dapat_ubah true', b => b.data.dapat_ubah === true]] });
    },
  },
  {
    name: 'occupancy-begin',
    cases: ['hunian K3 di H3 201', 'H3 dihuni 409 tanpa perubahan'],
    async run() {
      const { result } = await write('occ-k3-h3', 'POST', '/admin/occupancies', await adminOpts({
        body: { rumah_id: state.ids.rumah3, keluarga_id: state.ids.keluarga3, jenis_hunian: 'kontrak' },
      }), 201, { checks: [['aktif di H3', b => b.data.aktif === true && b.data.rumah?.id === state.ids.rumah3 && b.data.keluarga_id === state.ids.keluarga3]] },
      b => ({ id: b.data.id }),
      async () => { const h = (await adminGet('/admin/houses/{id}', { id: state.ids.rumah3 })).data; return h.keluarga?.id === state.ids.keluarga3 ? { id: h.penghunian_aktif_id } : null; });
      state.ids.occ3 = result.id;
      await write('occ-h3-occupied', 'POST', '/admin/occupancies', await adminOpts({
        body: { rumah_id: state.ids.rumah3, keluarga_id: state.ids.keluarga1, jenis_hunian: 'pemilik' },
      }), 409, { code: 'HOUSE_OCCUPIED' });
      await check('C61-h3-unchanged', 'GET', '/admin/houses/{id}', await adminOpts({ params: { id: state.ids.rumah3 } }), 200, {
        checks: [['H3 tetap satu hunian K3', b => b.data.keluarga?.id === state.ids.keluarga3 && b.data.riwayat_penghunian.length === 1]],
      });
    },
  },
  {
    name: 'dues-generate',
    cases: ['generate periode berjalan', 'ulang tagihan_baru 0', 'satu rumah banyak akun satu tagihan'],
    async run() {
      const periode = currentPeriod();
      await write('dues-generate', 'POST', '/admin/dues/generate', await adminOpts({ body: { periode } }), 200, {
        checks: [['tagihan_baru integer >= 0', b => Number.isInteger(b.data.tagihan_baru) && b.data.tagihan_baru >= 0]],
      });
      await write('dues-generate-again', 'POST', '/admin/dues/generate', await adminOpts({ body: { periode } }), 200, {
        checks: [['idempotent: tagihan_baru 0', b => b.data.tagihan_baru === 0]],
      });
      const summary = (await adminGet('/admin/houses/{id}/dues', { id: state.ids.rumah1 })).data;
      const fam = (await adminGet('/admin/families/{id}', { id: state.ids.keluarga1 })).data;
      await check('C72-one-house-many-accounts-one-charge', 'GET', '/admin/dues-charges', await adminOpts({ query: { rumah_id: state.ids.rumah1, periode } }), 200, {
        checks: [
          ['rumah H1 memiliki >= 3 akun aktif', () => activeLinks(fam).length >= 3],
          ['tepat satu tagihan periode', b => b.meta.total === 1],
          ['nominal = tarif berlaku, belum_bayar', b => b.data[0].nominal === summary.tarif_berlaku && b.data[0].status === 'belum_bayar'],
        ],
      });
      const h3 = await check('C73-h3-charge-before-move', 'GET', '/admin/dues-charges', await adminOpts({ query: { rumah_id: state.ids.rumah3, periode } }), 200, {
        checks: [['satu tagihan H3', b => b.meta.total === 1]],
      });
      state.ids.chargeH3 = h3.data[0].id;
      await save();
    },
  },
  {
    name: 'occupancy-move-end',
    cases: ['pindah H3→H4 201', 'akhiri 200', 'tagihan lama tetap di H3', 'rumah kosong tidak dibebaskan'],
    async run() {
      const periode = currentPeriod();
      const { result } = await write('occ-move', 'POST', '/admin/occupancies/{id}/move', await adminOpts({
        params: { id: state.ids.occ3 }, body: { rumah_id: state.ids.rumah4, alasan: 'Uji sintetis M07' },
      }), 201, { checks: [['aktif di H4', b => b.data.aktif === true && b.data.rumah?.id === state.ids.rumah4]] },
      b => ({ id: b.data.id }),
      async () => { const h = (await adminGet('/admin/houses/{id}', { id: state.ids.rumah4 })).data; return h.keluarga?.id === state.ids.keluarga3 ? { id: h.penghunian_aktif_id } : null; });
      await write('occ-end', 'POST', '/admin/occupancies/{id}/end', await adminOpts({ params: { id: result.id }, body: { alasan: 'Uji sintetis M07' } }), 200, {
        checks: [['berakhir', b => b.data.aktif === false && typeof b.data.selesai_tanggal === 'string']],
      });
      await check('C82-h3-empty-billing-kept', 'GET', '/admin/houses/{id}', await adminOpts({ params: { id: state.ids.rumah3 } }), 200, {
        checks: [['H3 kosong, histori tertutup, tagihan_aktif tetap', b => b.data.keluarga === null && b.data.penghunian_aktif_id === null
          && b.data.riwayat_penghunian.every(p => p.selesai_tanggal !== null) && b.data.tagihan_aktif === true]],
      });
      await check('C83-old-charge-stays', 'GET', '/admin/dues-charges', await adminOpts({ query: { rumah_id: state.ids.rumah3, periode } }), 200, {
        checks: [['tagihan H3 sama, belum dibayar, tidak berpindah', b => b.meta.total === 1 && b.data[0].id === state.ids.chargeH3 && b.data[0].status === 'belum_bayar' && b.data[0].rumah.id === state.ids.rumah3]],
      });
      await check('C84-h4-charge-separate', 'GET', '/admin/dues-charges', await adminOpts({ query: { rumah_id: state.ids.rumah4, periode } }), 200, {
        checks: [['H4 tagihan sendiri', b => b.meta.total === 1 && b.data[0].id !== state.ids.chargeH3]],
      });
      await check('C85-empty-house-not-waived', 'GET', '/admin/houses/{id}/dues', await adminOpts({ params: { id: state.ids.rumah3 } }), 200, {
        checks: [['bulan berjalan tetap belum_bayar', b => b.data.bulan_berjalan?.status === 'belum_bayar']],
      });
    },
  },
  {
    name: 'accounts',
    cases: ['nonaktif cabut token + login ditolak', 'aktif lagi', 'arsip utama lama cabut token, final 409', 'histori tetap'],
    async run() {
      const loser = state.ids.primaryLoser;
      const ids = { [loser]: state.steps[`register-${loser}`].result.akunId, p1: state.steps['register-p1'].result.akunId };
      const accountStatus = async id => (await adminGet('/admin/accounts/{id}', { id })).data.status;
      const { fresh: deactivated } = await once('loser-deactivate', async () => {
        tokens.delete(loser);
        const old = await tokenFor(loser);
        const res = await http('PATCH', '/admin/accounts/{id}', await adminOpts({ params: { id: ids[loser] }, body: { status: 'nonaktif', alasan: 'Uji sintetis M07' } }));
        record('loser-deactivate', res, 200, { checks: [['status nonaktif', b => b.data.status === 'nonaktif']] });
        tokens.delete(loser);
        record('C91-deactivated-token-revoked', await http('GET', '/auth/me', { token: old }), 401, { code: 'UNAUTHENTICATED' });
        record('C92-deactivated-login-denied', await loginRaw(state.actors[loser]), 422, { code: 'VALIDATION_FAILED' });
        return {};
      }, async () => ((await accountStatus(ids[loser])) === 'nonaktif' ? {} : null));
      if (!deactivated && !state.cases['C91-deactivated-token-revoked']) console.log('  catatan: C91/C92 tidak dapat diulang setelah resume');
      await write('loser-reactivate', 'PATCH', '/admin/accounts/{id}', await adminOpts({ params: { id: ids[loser] }, body: { status: 'aktif', alasan: 'Uji sintetis M07 selesai' } }), 200,
        { checks: [['status aktif', b => b.data.status === 'aktif']] }, () => ({}),
        async () => ((await accountStatus(ids[loser])) === 'aktif' ? {} : null));
      const relogin = await loginRaw(state.actors[loser]);
      record('C94-reactivated-login', relogin, 200, { checks: [['aktif kembali', b => b.data.akun.status === 'aktif']] });
      remember(relogin.body.data.token);
      tokens.set(loser, relogin.body.data.token);
      await once('p1-archive', async () => {
        tokens.delete('p1');
        const old = await tokenFor('p1');
        const res = await http('POST', '/admin/accounts/{id}/archive', await adminOpts({ params: { id: ids.p1 }, body: { alasan: 'Uji sintetis M07 arsip utama lama' } }));
        record('p1-archive', res, 200, {
          checks: [
            ['diarsipkan final', b => b.data.status === 'diarsipkan' && b.data.archived_at !== null],
            ['semua tautan ditutup, histori >= 2', b => b.data.tautan.length >= 2 && b.data.tautan.every(t => t.selesai_at !== null)],
          ],
        });
        tokens.delete('p1');
        record('C96-archived-token-revoked', await http('GET', '/auth/me', { token: old }), 401, { code: 'UNAUTHENTICATED' });
        record('C97-archived-login-denied', await loginRaw(state.actors.p1), 422, { code: 'VALIDATION_FAILED' });
        return {};
      }, async () => ((await accountStatus(ids.p1)) === 'diarsipkan' ? {} : null));
      await write('p1-archive-again', 'POST', '/admin/accounts/{id}/archive', await adminOpts({ params: { id: ids.p1 }, body: { alasan: 'Ulang sintetis M07' } }), 409, { code: 'ACCOUNT_ARCHIVED' });
      await check('C99-family-history-retained', 'GET', '/admin/families/{id}', await adminOpts({ params: { id: state.ids.keluarga1 } }), 200, {
        checks: [
          ['tautan p1 tetap di histori, tertutup', b => b.data.akun.filter(t => t.akun.id === ids.p1).length >= 2 && b.data.akun.filter(t => t.akun.id === ids.p1).every(t => t.selesai_at !== null)],
          ['tetap satu utama aktif', b => activeLinks(b.data).filter(t => t.is_utama).length === 1],
          ['kepala tetap anggota aktif', b => b.data.anggota.some(w => w.id === state.ids.kepala1)],
        ],
      });
      await check('C100-k2-history-retained', 'GET', '/admin/families/{id}', await adminOpts({ params: { id: state.ids.keluarga2 } }), 200, {
        checks: [['histori pindah dan pindah_keluarga', b => b.data.riwayat_keanggotaan.some(m => m.alasan_selesai === 'pindah') && b.data.riwayat_keanggotaan.some(m => m.alasan_selesai === 'pindah_keluarga')]],
      });
    },
  },
];

// ---------------------------------------------------------------- fixture Android dan penutup

async function writeAndroidFixture() {
  const role = key => {
    if (key === 'p1') return 'utama lama, diarsipkan (login harus ditolak)';
    if (key === 'a2') return 'tambahan menunggu (akses terbatas)';
    if (key === state.ids.primaryWinner) return 'akun utama aktif (boleh ubah keluarga)';
    if (key === state.ids.primaryLoser) return 'tambahan aktif (baca saja)';
    return 'belum diproses';
  };
  const actors = Object.fromEntries(Object.entries(state.actors).map(([k, c]) => [k, { ...c, akunId: state.steps[`register-${k}`]?.result?.akunId ?? null, peranUji: role(k) }]));
  await writeAtomic(path.join(outDir, ANDROID_FILE), JSON.stringify({
    schema: 'rt05-m07-android-fixtures/v1', origin: ORIGIN, sourceSha: state.sourceSha, run: state.run,
    note: 'Privat. Akun sintetis M07 @example.test; jangan dikomit atau ditampilkan di screenshot.',
    actors, ids: state.ids, writtenAt: new Date().toISOString(),
  }, null, 2));
}

async function logoutSessions() {
  for (const [key, token] of tokens) {
    try {
      const res = await http('POST', '/auth/logout', { token });
      console.log(`  logout perangkat ini (${key}): ${res.status}`);
    } catch { /* token sesi ini saja; kegagalan tidak memengaruhi data */ }
  }
  tokens.clear();
}

let adminCreds;

function usage() {
  return `Pemakaian:
  node infra/scripts/m07-stage-acceptance.mjs --sha <40hex> --fixture <fixture-M06.json> --out <folder-privat> [--execute] [--confirm-not-applied <stepId>]

Tanpa --execute: validasi argumen/path/fixture dan cetak rencana, tanpa jaringan dan tanpa menulis.
Origin tetap ${ORIGIN}${API}. Lihat docs/evidence/m07/LIVE_PLAN.md.`;
}

async function main() {
  try {
    args = parseArgs(process.argv.slice(2));
  } catch (e) {
    console.error(e.message);
    console.error(usage());
    return 3;
  }
  if (args.help) { console.log(usage()); return 0; }
  try {
    guardPaths(args);
    adminCreds = await readAdminFixture(args.fixture);
  } catch (e) {
    console.error(e instanceof UsageError ? e.message : 'Fixture tidak dapat dibaca sebagai JSON.');
    return 3;
  }
  remember(adminCreds.email, adminCreds.password);
  console.log(`Target ${ORIGIN}${API}; SHA ${args.sha}; fixture admin: ada (nilai tidak ditampilkan).`);
  if (!args.execute) {
    console.log('Mode rencana (tanpa jaringan). Fase:');
    for (const p of PHASES) console.log(`- ${p.name}: ${p.cases.join('; ')}`);
    console.log(`Kasus wajib: ${REQUIRED_CASES.length} (unik ${new Set(REQUIRED_CASES).size}).`);
    console.log('Tambahkan --execute setelah root memverifikasi SHA deployment lewat SSH.');
    return 0;
  }
  outDir = path.resolve(args.out);
  await fs.mkdir(outDir, { recursive: true, mode: 0o700 });
  try {
    state = await loadState();
  } catch (e) {
    console.error(e.message);
    return 3;
  }
  for (const c of Object.values(state.actors)) remember(c.email, c.password);
  order = Object.keys(state.cases).length;
  await save();
  let exit = 0;
  try {
    for (const phase of PHASES) {
      if (state.phases[phase.name] === 'done') { console.log(`= ${phase.name}: sudah selesai`); continue; }
      console.log(`= ${phase.name}`);
      state.phases[phase.name] = 'running';
      await save();
      await phase.run();
      state.phases[phase.name] = 'done';
      await save();
    }
  } catch (e) {
    exit = e instanceof Uncertain ? 2 : 1;
    const current = PHASES.find(p => state.phases[p.name] === 'running');
    if (current) state.phases[current.name] = e instanceof Uncertain ? 'blocked-uncertain' : 'failed';
    console.error(`BERHENTI: ${e instanceof CaseFailure || e instanceof Uncertain ? e.message : `${e.name}: ${String(e.message).replace(UUID_RE, '{id}')}`}`);
  } finally {
    await logoutSessions();
    await save();
    await writeAndroidFixture();
  }
  console.log(`Ringkasan publik: ${path.join(outDir, SUMMARY_FILE)}`);
  console.log(`State/fixture privat: ${path.join(outDir, STATE_FILE)}, ${path.join(outDir, ANDROID_FILE)}`);
  return exit;
}

process.exitCode = await main();
