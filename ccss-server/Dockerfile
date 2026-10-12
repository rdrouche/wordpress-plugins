# ─────────────────────────────────────────────────────────────────────────────
#  Dockerfile — Serveur CCSS auto-hébergé
#  Base : image officielle Playwright (inclut Chromium + toutes ses dépendances)
# ─────────────────────────────────────────────────────────────────────────────
#FROM mcr.microsoft.com/playwright:v1.59.1-jammy
FROM mcr.microsoft.com/playwright:v1.63.0-jammy

WORKDIR /app

# ── Dépendances npm ────────────────────────────────────────────────────────────
COPY package.json ./
RUN npm install --omit=dev

# ── Code source ────────────────────────────────────────────────────────────────
COPY src/ ./src/

# ── Dossier de logs (monté en volume en production) ────────────────────────────
RUN mkdir -p /logs && chmod 777 /logs

# ── Port exposé ────────────────────────────────────────────────────────────────
EXPOSE 3000

# ── Healthcheck Docker ─────────────────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
  CMD curl -f http://localhost:3000/health || exit 1

# ── Démarrage ──────────────────────────────────────────────────────────────────
CMD ["node", "src/server.js"]
