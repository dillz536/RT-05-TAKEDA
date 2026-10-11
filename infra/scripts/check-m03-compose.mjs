// Pemeriksaan statis kontrak infra M03. Tidak menyambung ke Redis, Neon, atau server.
import { readFileSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const read = (rel) => readFileSync(path.join(root, rel), 'utf8');
const failures = [];
const fail = (detail) => failures.push(detail);

const production = read('infra/compose.production.yml');
const local = read('infra/compose.local.yml');
const vps = read('infra/compose.vps.yml');
const caddy = read('infra/docker/gateway.Caddyfile');
const deploy = read('infra/scripts/deploy-vps.sh');
const ciRedis = read('infra/scripts/ci-redis.sh');
const apiWorkflow = read('.github/workflows/api.yml');
const queue = read('infra/docker/redis-queue.sh');
const cache = read('infra/docker/redis-cache.sh');

const need = (text, needle, label) => {
  if (!text.includes(needle)) fail(`${label} kekurangan ${needle}`);
};

for (const needle of [
  'worker-default:',
  'worker-media:',
  'worker-exports:',
  '--queue=notifications,default',
  '--queue=media',
  '--queue=exports',
  '--tries=5',
  '--timeout=60',
  '--timeout=120',
  'stop_grace_period: 180s',
  'rt05:heartbeat-check',
  '--component", "worker-default"',
  '--component", "worker-media"',
  '--component", "worker-exports"',
  '--component", "scheduler"',
  'REDIS_QUEUE_RETRY_AFTER: "180"',
  'REDIS_QUEUE_CONNECTION: queue',
  'takeda:${APP_ENV:-production}:',
  'REDIS_PASSWORD:?REDIS_PASSWORD wajib',
  'REDIS_CACHE_PASSWORD:?REDIS_CACHE_PASSWORD wajib',
  'REDIS_HOST: redis',
  'REDIS_CACHE_HOST: redis-cache',
  'SESSION_CONNECTION: cache',
  'redis-data:/data',
  'image: redis:7.4-alpine',
  'image: rt05-api:${RT05_IMAGE_TAG:-${RT05_ENV:-local}}',
  'image: rt05-web:${RT05_IMAGE_TAG:-${RT05_ENV:-local}}',
  'rt05-redis-queue.sh',
  'rt05-redis-cache.sh',
]) {
  need(production, needle, 'compose.production.yml');
}

if ((production.match(/stop_grace_period: 180s/g) || []).length < 4) {
  fail('stop grace 180s harus ada pada tiga worker dan scheduler');
}
if (/^  worker:\s*$/m.test(production)) fail('service worker tunggal masih ada');
if (/^\s+ports:\s*$/m.test(production)) fail('compose production tidak boleh memetakan ports host');
if (/6379:6379|0\.0\.0\.0:6379/.test(production)) fail('compose production mempublikasikan 6379');

need(queue, '--appendonly yes', 'redis-queue.sh');
need(queue, '--appendfsync everysec', 'redis-queue.sh');
need(queue, '--maxmemory-policy noeviction', 'redis-queue.sh');
need(cache, '--maxmemory-policy allkeys-lru', 'redis-cache.sh');
need(cache, '--appendonly no', 'redis-cache.sh');
need(local, 'redis-cache:', 'compose.local.yml');
need(local, 'rt05-redis-local:/data', 'compose.local.yml');
need(local, '127.0.0.1:6379:6379', 'compose.local.yml');
need(local, '127.0.0.1:6380:6379', 'compose.local.yml');
if (/0\.0\.0\.0:6379|0\.0\.0\.0:6380/.test(local)) fail('compose local mempublikasikan Redis di semua interface');

need(vps, 'letsencrypt', 'compose.vps.yml');
need(vps, 'traefik.http.routers.rt05-${RT05_ENV}-https.tls=true', 'compose.vps.yml');
need(caddy, 'trusted_proxies static private_ranges', 'gateway.Caddyfile');
need(deploy, 'worker-default worker-media worker-exports', 'deploy-vps.sh');
need(deploy, 'redis redis-cache', 'deploy-vps.sh');
need(deploy, 'require_env REDIS_PASSWORD', 'deploy-vps.sh');
need(deploy, 'health ready gagal', 'deploy-vps.sh');
need(deploy, 'heartbeat $component tidak sehat', 'deploy-vps.sh');
if (/reset\s+--hard/.test(deploy)) fail('deploy-vps.sh masih memakai hard reset');
need(apiWorkflow, 'infra/scripts/ci-redis.sh', 'api.yml');
need(apiWorkflow, 'infra/scripts/m03-recovery-ci.sh', 'api.yml');
need(apiWorkflow, 'redis, pcntl', 'api.yml');
need(ciRedis, '127.0.0.1:6379:6379', 'ci-redis.sh');
need(ciRedis, '127.0.0.1:6380:6379', 'ci-redis.sh');
need(ciRedis, 'rt05ci_|rt05test_', 'ci-redis.sh');
const recovery = read('infra/scripts/m03-recovery-ci.sh');
for (const needle of [
  'rt05:async-smoke',
  'rt05:outbox-dispatch',
  'rt05:heartbeat-check',
  '--expect-inbox=1',
  'queues:notifications:delayed',
  'queues:notifications:reserved',
  'queues:notifications:notify',
  'sentinel',
  'neon.tech',
]) {
  need(recovery, needle, 'm03-recovery-ci.sh');
}
if (/\bFLUSHALL\b|\bFLUSHDB\b/.test(recovery)) fail('m03-recovery-ci.sh memuat perintah hapus seluruh database');

for (const [label, text] of [['ci-redis.sh', ciRedis], ['redis-queue.sh', queue], ['redis-cache.sh', cache], ['deploy-vps.sh', deploy]]) {
  if (/\bFLUSHALL\b|\bFLUSHDB\b/.test(text)) fail(`${label} memuat perintah hapus seluruh database`);
}

const stagingDeploy = read('infra/scripts/deploy-staging-immutable.sh');
const stagingSmoke = read('infra/scripts/staging-runtime-smoke.sh');
const infraWorkflow = read('.github/workflows/infra.yml');
for (const needle of [
  '/opt/rt05-releases',
  'rt05-staging',
  '/root/.rt05/.env.staging',
  'build api web',
  '--no-build',
  'staging-previous-sha',
  'worker-default worker-media worker-exports',
  'health ready gagal',
  'heartbeat $component tidak sehat',
  '[0-9a-f]{40}',
]) {
  need(stagingDeploy, needle, 'deploy-staging-immutable.sh');
}
if (/reset\s+--hard/.test(stagingDeploy)) fail('deploy-staging-immutable.sh memuat hard reset');
if (/checkout[\s\S]{0,80}rt05-takeda|rt05-takeda[\s\S]{0,80}checkout/.test(stagingDeploy)) {
  fail('deploy-staging-immutable.sh menimpa pohon /opt/rt05-takeda');
}
for (const needle of [
  'rt05:db-smoke',
  '--connection=pgsql',
  '--connection=pgsql_migrations',
  'rt05:staging-smoke',
  '--confirm-staging',
  '--phase=prepare',
  '--phase=verify',
  'rt05:outbox-dispatch',
  'rt05-staging-aof-sentinel',
  'appendfsync',
  'everysec',
  'stop worker-default',
  'start worker-default',
  '"${DC[@]}" restart redis',
  'PASS staging-runtime-smoke',
  'rt05-staging',
]) {
  need(stagingSmoke, needle, 'staging-runtime-smoke.sh');
}
if (/restart redis-cache|restart gateway|restart api|restart web|restart scheduler|restart worker-media|restart worker-exports/.test(stagingSmoke)) {
  fail('staging-runtime-smoke.sh merestart service di luar worker-default dan redis antrean');
}
if (!/--connection=pgsql(?!_)/.test(stagingSmoke)) {
  fail('staging-runtime-smoke.sh kekurangan DbSmoke koneksi pgsql runtime');
}
if (apiWorkflow.includes('staging-runtime-smoke') || apiWorkflow.includes('deploy-staging-immutable')) {
  fail('smoke atau deploy staging tidak boleh menjadi drill CI pada api.yml');
}
need(infraWorkflow, 'bash -n infra/scripts/deploy-staging-immutable.sh', 'infra.yml');
need(infraWorkflow, 'bash -n infra/scripts/staging-runtime-smoke.sh', 'infra.yml');
for (const [label, text] of [['deploy-staging-immutable.sh', stagingDeploy], ['staging-runtime-smoke.sh', stagingSmoke]]) {
  if (/\bFLUSHALL\b|\bFLUSHDB\b/.test(text)) fail(`${label} memuat perintah hapus seluruh database`);
  if (/down\s+-v/.test(text)) fail(`${label} memuat down -v`);
  if (/volume\s+rm/.test(text)) fail(`${label} memuat volume rm`);
  if (/system\s+prune/.test(text)) fail(`${label} memuat system prune`);
  if (/\bdocker\s+push\b/.test(text)) fail(`${label} memuat docker push`);
}

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('OK: kontrak statis M03 infra (Redis terpisah, worker, heartbeat, prefix drill, gateway PR14) dan skrip staging M04.');
