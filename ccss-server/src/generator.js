/**
 * generator.js  (ESM)
 * ───────────────────
 * Génère le Critical CSS avec Playwright natif (sans Penthouse/critical).
 *
 * Stratégie :
 *  1. Chromium charge la page en headless
 *  2. On attend que le réseau soit idle (pas de requête pendante)
 *  3. On injecte un script dans la page qui :
 *     - Collecte toutes les règles CSS de tous les stylesheets
 *     - Filtre celles qui s'appliquent aux éléments visibles dans le viewport
 *  4. On retourne le CSS filtré et minifié
 *
 * Avantages vs Penthouse :
 *  - Pas de timeout à 45s sur le rendu JS
 *  - Contrôle fin du chargement (on attend networkidle)
 *  - On peut bloquer les ressources inutiles (images, fonts…)
 *  - Beaucoup plus rapide (~5-15s vs 45s+)
 */

import { chromium } from 'playwright';
import { logger }   from './logger.js';

const VIEWPORTS = {
  desktop: { width: 1300, height: 900 },
  mobile:  { width: 390,  height: 844 },
};

const PAGE_TIMEOUT   = parseInt(process.env.PAGE_TIMEOUT_MS   || '30000', 10); // chargement page
const GLOBAL_TIMEOUT = parseInt(process.env.GENERATION_TIMEOUT_MS || '60000', 10);

/**
 * Génère le Critical CSS d'une URL.
 * @param {string}  url
 * @param {object}  options
 * @param {boolean} options.mobile
 * @returns {Promise<string>} CSS critique minifié
 */
export async function generateCCSS(url, { mobile = false } = {}) {
  const { width, height } = mobile ? VIEWPORTS.mobile : VIEWPORTS.desktop;

  logger.debug(`🌐 Début génération Playwright natif`, { url, width, height });

  return withTimeout(
    _generate(url, width, height, mobile),
    GLOBAL_TIMEOUT,
    `Timeout global (${GLOBAL_TIMEOUT}ms) pour ${url}`
  );
}

async function _generate(url, width, height, mobile) {
  let browser = null;

  try {
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
      ],
    });

    const context = await browser.newContext({
      viewport: { width, height },
      userAgent: mobile
        ? 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 Chrome/90.0 Mobile Safari/537.36'
        : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
      // Bloquer images, fonts, médias — on n'en a pas besoin pour le CSS
      // On garde JS activé car beaucoup de sites l'utilisent pour le layout
    });

    // Bloquer les ressources inutiles pour le CSS + tous les trackers/analytics
    // pour ne pas polluer les stats Matomo, GA, etc.
    const BLOCKED_PATTERNS = [
      // Matomo / Piwik
      /\/matomo\.js/,
      /\/piwik\.js/,
      /\/matomo\.php/,
      /\/piwik\.php/,
      // Google Analytics / GTM
      /google-analytics\.com/,
      /googletagmanager\.com/,
      /gtag\/js/,
      // Autres trackers courants
      /hotjar\.com/,
      /clarity\.ms/,
      /facebook\.net\/tr/,
      /doubleclick\.net/,
    ];

    await context.route('**/*', (route) => {
      const reqUrl = route.request().url();
      const type   = route.request().resourceType();

      // Bloquer par type de ressource inutile pour le CSS
      if (['image', 'media', 'font'].includes(type)) {
        return route.abort();
      }

      // Bloquer les trackers par pattern d'URL
      for (const pattern of BLOCKED_PATTERNS) {
        if (pattern.test(reqUrl)) {
          logger.debug(`🚫 Tracker bloqué : ${reqUrl}`);
          return route.abort();
        }
      }

      route.continue();
    });

    const page = await context.newPage();

    // ── Vérification HTTP avant de charger avec Chromium ─────────────────────
    // On suit les redirections et on vérifie que le code final est bien 200.
    // Cela évite de lancer Chromium sur des 301 (mauvais slug) ou 404.
    {
      const { default: fetch } = await import('node-fetch');
      let headRes;
      try {
        headRes = await fetch(url, {
          method:   'HEAD',
          timeout:  8000,
          redirect: 'follow',
        });
      } catch (err) {
        throw new Error(`Pré-vérification réseau échouée : ${err.message}`);
      }

      if (headRes.status !== 200) {
        throw new Error(`HTTP ${headRes.status} — URL incorrecte ou redirigée (attendu 200)`);
      }

      const ct = headRes.headers.get('content-type') || '';
      if (ct && !ct.includes('text/html')) {
        throw new Error(`Content-Type non HTML : ${ct}`);
      }

      logger.debug(`✓ Pré-vérification OK (200 HTML)`, { url });
    }

    // Charger la page — on accepte aussi 'domcontentloaded' si networkidle timeout
    logger.debug(`⏳ Chargement de la page`, { url, timeout: PAGE_TIMEOUT });

    try {
      await page.goto(url, {
        waitUntil: 'networkidle',
        timeout:   PAGE_TIMEOUT,
      });
    } catch (err) {
      // networkidle peut timeout sur des sites avec des polling WS — on continue quand même
      if (err.message.includes('Timeout') || err.message.includes('timeout')) {
        logger.warn(`⚠️  networkidle timeout — on continue avec l'état actuel`, { url });
      } else {
        throw err;
      }
    }

    logger.debug(`✓ Page chargée`, { url });

    // Extraire le CSS critique via évaluation JS dans la page
    const css = await page.evaluate(({ viewWidth, viewHeight }) => {
      /**
       * Algorithme d'extraction du CSS critique :
       * 1. Collecter toutes les règles CSS de tous les stylesheets
       * 2. Pour chaque règle, vérifier si elle s'applique à un élément
       *    qui est dans le viewport (above the fold)
       * 3. Retourner les règles pertinentes
       */

      const viewportRect = { top: 0, left: 0, bottom: viewHeight, right: viewWidth };

      // Vérifier si un élément est au moins partiellement visible dans le viewport
      function isAboveFold(el) {
        if (!el || !el.getBoundingClientRect) return false;
        const rect = el.getBoundingClientRect();
        return (
          rect.bottom >= 0 &&
          rect.right  >= 0 &&
          rect.top    <= viewHeight &&
          rect.left   <= viewWidth
        );
      }

      // Vérifier si une règle CSS correspond à un élément above the fold
      function ruleMatchesAboveFold(rule) {
        try {
          if (rule.type === CSSRule.STYLE_RULE) {
            // Règle de style normale
            const selector = rule.selectorText;
            if (!selector) return false;

            // Certains sélecteurs sont toujours pertinents
            const alwaysInclude = [
              ':root', 'html', 'body', '*',
              '::before', '::after', ':before', ':after',
            ];
            for (const s of alwaysInclude) {
              if (selector.includes(s)) return true;
            }

            // Vérifier si le sélecteur correspond à un élément visible
            try {
              const elements = document.querySelectorAll(selector);
              for (const el of elements) {
                if (isAboveFold(el)) return true;
              }
            } catch (e) {
              // Sélecteur invalide ou non supporté → inclure par précaution
              return true;
            }
            return false;

          } else if (rule.type === CSSRule.MEDIA_RULE) {
            // @media — garder si au moins une règle enfant matche
            const mediaText = rule.conditionText || rule.media?.mediaText || '';
            // Exclure les media queries qui ne correspondent pas à l'écran actuel
            if (mediaText.includes('print')) return false;
            if (mediaText.includes('speech')) return false;

            for (const childRule of rule.cssRules || []) {
              if (ruleMatchesAboveFold(childRule)) return true;
            }
            return false;

          } else if (rule.type === CSSRule.KEYFRAMES_RULE) {
            // @keyframes — inclure si référencé (trop complexe à vérifier → inclure)
            return true;

          } else if (rule.type === CSSRule.FONT_FACE_RULE) {
            // @font-face — inclure (peut affecter le FOUC)
            return true;

          } else if (rule.type === CSSRule.SUPPORTS_RULE) {
            // @supports — inclure si au moins une règle enfant matche
            for (const childRule of rule.cssRules || []) {
              if (ruleMatchesAboveFold(childRule)) return true;
            }
            return false;

          } else {
            // Autres règles (variables, charset…) → inclure
            return true;
          }
        } catch (e) {
          return true; // En cas d'erreur → inclure par sécurité
        }
      }

      // Collecter toutes les règles CSS critiques
      const criticalRules = [];

      for (const sheet of document.styleSheets) {
        try {
          // Ignorer les stylesheets cross-origin sans CORS
          const rules = sheet.cssRules || sheet.rules;
          if (!rules) continue;

          for (const rule of rules) {
            if (ruleMatchesAboveFold(rule)) {
              criticalRules.push(rule.cssText);
            }
          }
        } catch (e) {
          // CORS error sur un stylesheet externe → skip
        }
      }

      // Minification basique : supprimer commentaires et espaces superflus
      const raw = criticalRules.join('\n');
      return raw
        .replace(/\/\*[\s\S]*?\*\//g, '') // supprimer commentaires
        .replace(/\s+/g, ' ')              // espaces multiples → un seul
        .replace(/\s*{\s*/g, '{')
        .replace(/\s*}\s*/g, '}')
        .replace(/\s*:\s*/g, ':')
        .replace(/\s*;\s*/g, ';')
        .replace(/\s*,\s*/g, ',')
        .trim();

    }, { viewWidth: width, viewHeight: height });

    logger.debug(`📊 Extraction terminée`, {
      url,
      octets: Buffer.byteLength(css || '', 'utf8'),
      mobile,
    });

    if (!css || css.trim().length === 0) {
      throw new Error('CSS critique vide — la page n\'a peut-être pas de stylesheet accessible');
    }

    return css;

  } finally {
    if (browser) {
      await browser.close();
      logger.debug(`🔒 Browser fermé`, { url });
    }
  }
}

function withTimeout(promise, ms, message) {
  return Promise.race([
    promise,
    new Promise((_, reject) => setTimeout(() => reject(new Error(message)), ms)),
  ]);
}
