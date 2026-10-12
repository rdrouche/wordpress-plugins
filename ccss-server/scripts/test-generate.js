#!/usr/bin/env node
/**
 * test-generate.js  (ESM)
 *
 * Usage :
 *   node scripts/test-generate.js https://example.com
 *   node scripts/test-generate.js https://example.com --mobile
 *   CCSS_SERVER=http://localhost:3000 node scripts/test-generate.js https://example.com
 *   node scripts/test-generate.js https://example.com --direct   ← génération directe sans serveur
 */
import { fileURLToPath } from 'url';
import { dirname, join }  from 'path';

const url_arg = process.argv.find(a => a.startsWith('http')) || 'https://example.com';
const mobile  = process.argv.includes('--mobile');
const server  = process.env.CCSS_SERVER || 'http://localhost:3000';

console.log('─────────────────────────────────────────────');
console.log('  Test du serveur CCSS Self-Hosted');
console.log('─────────────────────────────────────────────');
console.log(`  Serveur : ${server}`);
console.log(`  URL     : ${url_arg}`);
console.log(`  Mobile  : ${mobile}`);
console.log('─────────────────────────────────────────────\n');

const { default: fetch } = await import('node-fetch');

// ── 1. Healthcheck ────────────────────────────────────────────────────────────
console.log('1️⃣  Test du healthcheck…');
try {
  const res  = await fetch(`${server}/health`);
  const data = await res.json();
  if (data.ok) {
    console.log('   ✅ Serveur en ligne\n');
  } else {
    console.error('   ❌ Healthcheck échoué\n');
    process.exit(1);
  }
} catch (err) {
  console.error(`   ❌ Impossible de joindre le serveur : ${err.message}`);
  console.error('      → Vérifier que le conteneur est démarré : docker-compose up -d\n');
  process.exit(1);
}

// ── 2. Status de la file ──────────────────────────────────────────────────────
console.log('2️⃣  Status de la file…');
const statusRes  = await fetch(`${server}/status`);
const statusData = await statusRes.json();
console.log(`   File d'attente : ${statusData.queue_size} tâche(s)`);
console.log(`   Concurrence    : ${statusData.concurrency}`);
console.log(`   Uptime         : ${statusData.uptime_s}s\n`);

// ── 3. Test de génération ─────────────────────────────────────────────────────
console.log('3️⃣  Test de génération CCSS…');
console.log('   (callback renvoyé vers httpbin.org pour inspection)\n');

const payload = {
  url:          url_arg,
  callback_url: 'https://httpbin.org/post',
  token:        'test-token-123',
  type:         'page',
  mobile,
};

const t0  = Date.now();
const res = await fetch(`${server}/generate`, {
  method:  'POST',
  headers: { 'Content-Type': 'application/json' },
  body:    JSON.stringify(payload),
});
const data = await res.json();
const ms   = Date.now() - t0;

if (res.status === 202) {
  console.log(`   ✅ Requête acceptée en ${ms}ms`);
  console.log(`   Réponse :`, data);
  console.log('\n   ⏳ Génération en arrière-plan. Suivre les logs :');
  console.log('   docker-compose logs -f ccss-server');
} else {
  console.error(`   ❌ Erreur ${res.status} :`, data);
  process.exit(1);
}

// ── 4. Génération directe (--direct) ─────────────────────────────────────────
if (process.argv.includes('--direct')) {
  console.log('\n4️⃣  Génération CCSS directe (sans passer par le serveur Express)…');
  const { generateCCSS } = await import('../src/generator.js');
  try {
    const t1   = Date.now();
    const ccss = await generateCCSS(url_arg, { mobile });
    const ms2  = Date.now() - t1;
    console.log(`   ✅ Généré en ${ms2}ms — ${Buffer.byteLength(ccss, 'utf8')} octets`);
    console.log('\n   Extrait :');
    console.log('   ' + ccss.substring(0, 300) + '…');
  } catch (err) {
    console.error(`   ❌ Erreur : ${err.message}`);
  }
}

console.log('\n─────────────────────────────────────────────');
console.log('  Tests terminés.');
console.log('─────────────────────────────────────────────\n');
