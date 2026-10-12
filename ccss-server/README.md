# CCSS Self-Hosted — Remplacement de QUIC.cloud pour LSCache

Génère le **Critical CSS** (CCSS) de vos sites WordPress en local,
sans les quotas journaliers de QUIC.cloud.

---

## Architecture

```
WordPress (LSCache)
  │  mu-plugin ccss-bridge.php
  │  filtre litespeed_ccss_url  →  redirige vers votre conteneur
  ▼
Conteneur Docker  (Node.js + Playwright + critical)
  │  Express reçoit la requête
  │  Chromium headless charge la page
  │  Extrait le CSS above-the-fold
  ▼
Callback REST WordPress
  /wp-json/litespeed/v3/ccss
  │  LSCache reçoit le CCSS et l'écrit sur disque
  ▼
Fichier .css servi directement ✓
```

---

## Installation du conteneur

### 1. Cloner / copier ce dossier sur votre serveur

```bash
scp -r ccss-server/ user@votre-serveur:/opt/ccss-server/
```

### 2. Configurer

```bash
cd /opt/ccss-server/
cp .env.example .env
nano .env
```

Variables importantes dans `.env` :

| Variable | Description | Défaut |
|---|---|---|
| `CONCURRENCY` | Nb de Chromium simultanés (≈ RAM / 400 Mo) | `2` |
| `LOG_LEVEL` | `debug` pour le débogage, `info` en prod | `info` |
| `CCSS_SECRET` | Secret HMAC partagé avec WordPress | vide |
| `GENERATION_TIMEOUT_MS` | Timeout par génération | `60000` |

### 3. Lancer le conteneur

```bash
docker-compose up -d
docker-compose logs -f   # Suivre les logs en temps réel
```

### 4. Vérifier que le serveur est en ligne

```bash
curl http://localhost:3000/health
# → {"ok":true}

curl http://localhost:3000/status
# → {"status":"ok","queue_size":0,"concurrency":2,"uptime_s":12}
```

---

## Installation côté WordPress

### 1. Copier le mu-plugin

```bash
cp wordpress/ccss-bridge.php /chemin/vers/votre-wp/wp-content/mu-plugins/
```

> Les mu-plugins sont chargés automatiquement sans activation.

### 2. Configurer wp-config.php

Ajouter **avant** la ligne `/* That's all, stop editing! */` :

```php
// CCSS Self-Hosted — URL de votre conteneur (sans slash final)
define( 'CCSS_SERVER_URL', 'http://votre-serveur:3000' );

// Secret HMAC (même valeur que dans .env)
// Laisser vide si le serveur est en réseau privé
define( 'CCSS_SECRET', '' );

// Logs de débogage dans wp-content/debug.log
define( 'CCSS_DEBUG', true );  // Mettre false en production
```

### 3. Vérifier dans l'admin

Aller dans **LiteSpeed Cache > Page Optimization**.
Une notice bleue confirme que le bridge est actif :

```
🔧 CCSS Self-Hosted Bridge actif
Serveur CCSS : http://votre-serveur:3000
```

### 4. Activer le CCSS dans LSCache

- **LiteSpeed Cache > Page Optimization > CSS Settings**
- `Load CSS Asynchronously` → **ON**
- Sauvegarder

---

## Tester

```bash
# Test complet (healthcheck + envoi de requête)
node scripts/test-generate.js https://votre-site.com

# Test en mode mobile
node scripts/test-generate.js https://votre-site.com --mobile

# Générer le CCSS directement (sans passer par le serveur Express)
node scripts/test-generate.js https://votre-site.com --direct
```

---

## Débogage

### Logs du conteneur

```bash
# Tous les logs
docker-compose logs -f ccss-server

# Depuis le début
docker-compose logs --tail=100 ccss-server

# Fichier de log persistant
tail -f logs/ccss.log | jq .   # (si jq est installé)
```

### Logs WordPress

Activer `CCSS_DEBUG` dans `wp-config.php`, puis :

```bash
tail -f wp-content/debug.log | grep "\[CCSS-Bridge\]"
```

### Problèmes courants

| Symptôme | Cause probable | Solution |
|---|---|---|
| `Connection refused` sur le conteneur | Conteneur non démarré | `docker-compose up -d` |
| CCSS vide généré | Page avec erreur JS bloquante | Vérifier la console du site |
| Timeout dépassé | Page trop lente à charger | Augmenter `GENERATION_TIMEOUT_MS` |
| Callback 401 | Token LSCache invalide | Vérifier la clé dans LiteSpeed Cache > General |
| `Signature invalide` | Secrets différents | Vérifier `CCSS_SECRET` dans `.env` et `wp-config.php` |
| Chromium crash | RAM insuffisante | Réduire `CONCURRENCY` ou augmenter `shm_size` |

### Activer les logs debug

Dans `.env` :
```
LOG_LEVEL=debug
```

Dans `wp-config.php` :
```php
define( 'CCSS_DEBUG', true );
```

---

## Ressources serveur recommandées

| Sites | RAM recommandée | CONCURRENCY |
|---|---|---|
| 1-2 sites | 1 Go | 1 |
| 3-5 sites | 2 Go | 2 |
| 10+ sites | 4 Go | 4 |

---

## Sécurité

- Si votre conteneur est sur un réseau **privé** (même serveur ou VPN), le secret n'est pas indispensable.
- Si le conteneur est accessible depuis Internet, **toujours configurer `CCSS_SECRET`**.
- Le mu-plugin byasse la vérification d'IP de QUIC.cloud — ne l'utiliser qu'avec un conteneur de confiance.
