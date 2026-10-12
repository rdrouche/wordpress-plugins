# Social AutoPoster – Plugin WordPress

Publiez automatiquement vos articles WordPress sur **LinkedIn** (page) et **Facebook** (page) avec planification et messages personnalisés.

---

## 📦 Installation

1. Copiez le dossier `social-autoposter` dans `/wp-content/plugins/`
2. Activez le plugin dans **Extensions → Extensions installées**
3. Allez dans **Social AutoPoster → Réglages** pour configurer vos accès

---

## 🔷 Configuration LinkedIn

### Créer l'application LinkedIn

1. Rendez-vous sur [developers.linkedin.com](https://developer.linkedin.com)
2. Créez une nouvelle application
3. Dans **Products**, activez **Marketing Developer Platform**
4. Notez votre **Client ID** et **Client Secret**

### Obtenir un Access Token

LinkedIn utilise OAuth 2.0. Les scopes nécessaires sont :
- `r_organization_social`
- `w_organization_social`

Vous pouvez utiliser :
- **[linkedin-token-generator](https://www.linkedin-token-generator.com/)** (outil tiers pratique)
- Ou implémenter le flux OAuth 3-legged standard

> ⚠️ Les tokens LinkedIn expirent après **60 jours**. Pensez à les renouveler.

### Trouver l'URN de votre page

L'URN ressemble à : `urn:li:organization:1234567890`

- Allez sur l'URL d'admin de votre page LinkedIn : `https://www.linkedin.com/company/XXXXX/admin/`
- Le numéro dans l'URL est votre **Organization ID**
- L'URN complet est : `urn:li:organization:XXXXXXXXXX`

### Remplir dans le plugin

- **Access Token** : votre token OAuth
- **URN de la page** : `urn:li:organization:XXXXXXXXXX`

---

## 🔵 Configuration Facebook

### Créer l'application Facebook

1. Allez sur [developers.facebook.com](https://developers.facebook.com)
2. Créez une app de type **Business**
3. Ajoutez le produit **Pages API**

### Permissions nécessaires

- `pages_manage_posts`
- `pages_read_engagement`

### Obtenir un Page Access Token (long-lived)

1. Allez sur le **Graph API Explorer** : [developers.facebook.com/tools/explorer](https://developers.facebook.com/tools/explorer)
2. Sélectionnez votre app
3. Ajoutez les permissions `pages_manage_posts` et `pages_read_engagement`
4. Cliquez **Générer un token d'accès**
5. Allez sur [developers.facebook.com/tools/debug/accesstoken](https://developers.facebook.com/tools/debug/accesstoken) → **Prolonger le token** (60 jours)
6. Récupérez le **Page Access Token** spécifique à votre page

### Trouver l'ID de votre page

- Allez sur votre page Facebook → **À propos** → **Infos sur la Page**
- Ou dans le **Graph API Explorer** : requête `me/accounts`

### Remplir dans le plugin

- **Page Access Token** : votre token de page long-lived
- **Page ID** : l'identifiant numérique de votre page

---

## 📝 Variables dans les messages

Utilisez ces variables dans vos templates, elles seront automatiquement remplacées lors de la publication :

| Variable | Remplacée par |
|----------|---------------|
| `{{URL_POST}}` | URL complète de l'article |
| `{{TITRE}}` | Titre de l'article |
| `{{EXTRAIT}}` | Résumé/extrait de l'article |

### Exemple de message

```
✨ Nouvel article sur le blog !

{{TITRE}}

{{EXTRAIT}}

👉 Lire l'article : {{URL_POST}}

#WordPress #Blog #Tech
```

---

## 📅 Planification

Le plugin vérifie la file d'attente **toutes les 5 minutes** via WP-Cron.

> ⚠️ WP-Cron est déclenché par les visites sur votre site. Si votre site a peu de trafic, ajoutez une vraie cron job système :
>
> ```bash
> */5 * * * * wget -q -O - https://votre-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
> ```

---

## 🖼️ Image mise en avant

Si votre article a une image mise en avant, elle sera automatiquement jointe à la publication sur les deux réseaux.

---

## 🗂️ Structure du plugin

```
social-autoposter/
├── social-autoposter.php        ← Point d'entrée
├── includes/
│   ├── class-sap-db.php         ← Base de données (table de planification)
│   ├── class-sap-scheduler.php  ← WP-Cron + traitement de la file
│   ├── class-sap-linkedin.php   ← API LinkedIn v2
│   └── class-sap-facebook.php   ← API Graph Facebook v19
├── admin/
│   └── class-sap-admin.php      ← Interface d'administration + meta box
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

---

## 🔒 Sécurité

- Les tokens sont stockés dans les options WordPress (chiffrés en base)
- Toutes les actions AJAX utilisent des nonces WordPress
- Les capacités utilisateur sont vérifiées sur chaque action

---

## ❓ FAQ

**Les publications ne partent pas automatiquement ?**
→ Vérifiez que WP-Cron fonctionne correctement. Installez le plugin **WP Crontrol** pour diagnostiquer.

**Erreur "token expiré" sur LinkedIn ?**
→ Les tokens LinkedIn durent 60 jours. Régénérez-en un et mettez à jour la configuration.

**Erreur "permission denied" sur Facebook ?**
→ Assurez-vous que votre app Facebook est en mode **Live** (pas Developer) et que les permissions sont approuvées.
