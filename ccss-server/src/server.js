/**
 * server.js  (ESM)
 * ─────────────────
 * Serveur Express qui reçoit les demandes de génération CCSS
 * depuis le mu-plugin WordPress et les traite en file d'attente.
 */
import express  from 'express';
import crypto   from 'crypto';
import { Queue }        from './queue.js';
import { logger }       from './logger.js';
import { generateCCSS } from './generator.js';

const app  = express();
const PORT = process.env.PORT || 3000;

// Secret HMAC partagé avec WordPress (optionnel)
// Configurer CCSS_SECRET dans .env + wp-config.php
const SECRET = process.env.CCSS_SECRET || null;

app.use(express.json({ limit: '5mb' }));

// ── Log de chaque requête entrante ────────────────────────────────────────────
app.use((req, _res, next) => {
  logger.info(`→ ${req.method} ${req.path}`, {
    ip:   req.ip,
    body: req.method === 'POST' ? req.body : undefined,
  });
  next();
});

// ── Vérification signature HMAC (si SECRET configuré) ─────────────────────────
function verifySecret(req, res, next) {
  if (!SECRET) return next();
  const sig = req.headers['x-ccss-signature'];
  if (!sig) {
    logger.warn('🔐 Requête sans signature rejetée');
    return res.status(401).json({ error: 'Signature manquante' });
  }
  const expected = crypto
    .createHmac('sha256', SECRET)
    .update(JSON.stringify(req.body))
    .digest('hex');
  if (sig !== expected) {
    logger.warn('🔐 Signature invalide', { received: sig });
    return res.status(403).json({ error: 'Signature invalide' });
  }
  next();
}

// ── File d'attente (évite 50 Chromium en même temps) ─────────────────────────
const queue = new Queue({
  concurrency: parseInt(process.env.CONCURRENCY || '2', 10),
});

// ─────────────────────────────────────────────────────────────────────────────
//  POST /generate
//  Corps JSON attendu (envoyé par ccss-bridge.php) :
//    {
//      url          : "https://monsite.com/ma-page/",
//      callback_url : "https://monsite.com/wp-json/litespeed/v3/ccss",
//      token        : "...",   ← token LSCache pour le callback
//      type         : "page|post|homepage|...",
//      mobile       : false,
//    }
// ─────────────────────────────────────────────────────────────────────────────
app.post('/generate', verifySecret, async (req, res) => {
  const { url, callback_url, token, type = 'page', mobile = false } = req.body;

  if (!url || !callback_url) {
    logger.error('❌ Paramètres manquants', { body: req.body });
    return res.status(400).json({ error: 'url et callback_url sont requis' });
  }

  logger.info(`📥 Requête CCSS reçue`, { url, type, mobile });

  // Réponse immédiate 202 — le traitement est asynchrone
  res.status(202).json({ status: 'queued', url, queue_size: queue.size() + 1 });

  queue.add(async () => {
    logger.info(`⚙️  Génération en cours`, { url, type, mobile });
    try {
      const ccss = await generateCCSS(url, { mobile });
      logger.info(`✅ CCSS généré (${Buffer.byteLength(ccss, 'utf8')} octets)`, { url });
      await sendCallback(callback_url, token, url, ccss, type);
    } catch (err) {
      logger.error(`❌ Échec génération`, { url, error: err.message, stack: err.stack });
      await sendCallback(callback_url, token, url, null, type, err.message);
    }
  });
});

// ── GET /status ───────────────────────────────────────────────────────────────
app.get('/status', (_req, res) => {
  res.json({
    status:      'ok',
    queue_size:  queue.size(),
    concurrency: queue.concurrency,
    uptime_s:    Math.floor(process.uptime()),
  });
});

// ── GET /health — pour Docker/k8s ────────────────────────────────────────────
app.get('/health', (_req, res) => res.json({ ok: true }));

// ─────────────────────────────────────────────────────────────────────────────
//  Envoyer le CCSS généré au callback WordPress
//  LSCache attend un POST sur /wp-json/litespeed/v3/ccss avec :
//    { url, type, data (le CSS), success }
// ─────────────────────────────────────────────────────────────────────────────
async function sendCallback(callbackUrl, token, pageUrl, ccss, type, errorMsg = null) {
  const { default: fetch } = await import('node-fetch');

  const body = {
    url:     pageUrl,
    type,
    success: ccss !== null,
    data:    ccss || '',
    ...(errorMsg && { error: errorMsg }),
  };

  logger.debug(`📤 Callback vers WordPress`, { callbackUrl, success: body.success });

  try {
    const res = await fetch(callbackUrl, {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'X-LSCWP-TOKEN': token || '',
        'User-Agent':    'CCSS-Self-Hosted/1.0',
      },
      body:    JSON.stringify(body),
      timeout: 15000,
    });

    const text = await res.text();
    if (res.ok) {
      logger.info(`✅ Callback accepté par WordPress`, { status: res.status });
    } else {
      logger.warn(`⚠️  Callback rejeté`, { status: res.status, response: text });
    }
  } catch (err) {
    logger.error(`❌ Callback échoué`, { callbackUrl, error: err.message });
  }
}

// ── Démarrage ─────────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  logger.info(`🚀 Serveur CCSS démarré sur le port ${PORT}`);
  logger.info(`   Concurrence Chromium : ${queue.concurrency}`);
  logger.info(`   Secret configuré     : ${SECRET ? 'OUI' : 'NON (désactivé)'}`);
  logger.info(`   LOG_LEVEL            : ${process.env.LOG_LEVEL || 'info'}`);
});

// ─────────────────────────────────────────────────────────────────────────────
//  POST /generate-sync
//  Mode synchrone : attend la génération et retourne le CSS directement.
//  Utilisé par le mu-plugin WordPress (pas de callback nécessaire).
//
//  Corps attendu :
//    { url: "https://...", mobile: false }
//  Réponse :
//    { css: "...", url: "...", size: 1234 }   (200 OK)
//    { error: "...", url: "..." }              (500)
// ─────────────────────────────────────────────────────────────────────────────
app.post('/generate-sync', verifySecret, async (req, res) => {
  const { url, mobile = false } = req.body;

  if (!url) {
    return res.status(400).json({ error: 'url est requis' });
  }

  logger.info(`📥 [SYNC] Requête CCSS`, { url, mobile });

  // En mode synchrone, on bloque dans la file et on attend le résultat
  // Le timeout HTTP côté WordPress est 90s — on a le temps
  try {
    const css = await queue.add(() => generateCCSS(url, { mobile }));
    const size = Buffer.byteLength(css, 'utf8');
    logger.info(`✅ [SYNC] CCSS ok (${size} octets)`, { url });
    res.status(200).json({ css, url, size });
  } catch (err) {
    logger.error(`❌ [SYNC] Échec génération`, { url, error: err.message });
    res.status(500).json({ error: err.message, url });
  }
});
