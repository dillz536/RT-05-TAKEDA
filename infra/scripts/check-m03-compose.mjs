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

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('OK: kontrak statis M03 infra (Redis terpisah, worker, heartbeat, prefix drill, gateway PR14).');
