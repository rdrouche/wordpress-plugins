=== Polylang Auto Translator ===
Contributors: yourname
Tags: polylang, translate, deepl, google translate, libretranslate, multilingual
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.1.1
Requires PHP: 8.0
License: GPLv2 or later

Traduction automatique des articles via DeepL, Google Translate ou LibreTranslate, intégré à Polylang.

== Description ==

Ce plugin ajoute la traduction automatique dans WordPress en s'appuyant sur Polylang.

= Compatibilité testée =

* WordPress 6.9.x → 7.0.2
* Polylang 3.8.4 → 3.8.6 (gratuit et Pro)
* PHP 8.0 → 8.3

= Fonctionnalités =

* **5 services de traduction** : DeepL, Google Translate (API), Google Translate (gratuit sans clé), LibreTranslate (instance privée), LibreTranslate (sans clé)
* **Fallback automatique** : DeepL → Google Translate gratuit ou LibreTranslate quand le quota est dépassé
* **Traduction automatique** au clic sur « + » dans la colonne de traduction Polylang
* **Source = langue par défaut** définie dans Polylang
* **Protection des blocs code / pre** et compositions Gutenberg complexes
* **Contrôle d'intégrité du balisage** : si le service de traduction perd des délimiteurs de blocs Gutenberg, la traduction est refusée au lieu de casser l'article
* **Traduction de l'extrait** si présent
* **Traduction des taxonomies** (catégories, tags, taxonomies personnalisées) traduisibles via Polylang
* **Réécriture des liens internes** vers leur équivalent dans la langue cible
* **Mots non traduits** : liste configurable (Active Directory, Docker, Kubernetes…)
* **Traductions personnalisées** par langue : FR:Sommaire|EN:Table of Contents
* **Retraduire depuis la source** : bouton dans la barre latérale d'un article déjà traduit

= Prérequis =

* Polylang (version gratuite ou Polylang Pro)
* Au moins un service de traduction configuré

== Installation ==

1. Décompressez `wp-polylang-translator.zip` dans `/wp-content/plugins/`
2. Activez le plugin depuis le menu **Extensions**
3. Allez dans **Réglages > Polylang Translator** pour configurer vos clés API

== Configuration ==

= DeepL =
* Obtenez votre clé API sur https://www.deepl.com/pro-api
* Les clés gratuites se terminent par `:fx`
* Activez le fallback vers Google Translate si nécessaire

= Google Translate =
* Activez l'API Cloud Translation (v2) dans la Google Cloud Console
* Créez une clé API et collez-la dans les réglages

= LibreTranslate =
* Renseignez l'URL complète de votre instance (ex : https://translate.example.com)
* La clé API est optionnelle selon la configuration de votre instance

= Mots non traduits =
Un mot par ligne. Ces mots sont remplacés par des jetons avant traduction et restaurés ensuite.

= Traductions personnalisées =
Format : `CODE:terme_source|CODE:terme_cible`
Exemple : `FR:Sommaire|EN:Table of Contents`
Plusieurs paires sur la même ligne, séparées par `|`.
Ces correspondances sont appliquées APRÈS la traduction automatique.

== Utilisation ==

1. Dans Polylang, cliquez sur **+** pour créer une traduction d'un article
2. La traduction démarre automatiquement (vous verrez une barre de progression)
3. Vérifiez et ajustez avant de publier
4. Pour retraduire un article déjà traduit : ouvrez-le et cliquez sur **Retraduire depuis la source** dans la barre latérale

== Dépannage ==

= « Aucun article source trouvé dans la langue par défaut » =

L'article n'est plus relié à sa source dans le groupe de traductions Polylang,
ou la langue par défaut renvoyée par Polylang n'est pas un slug (locale `fr_FR`
au lieu de `fr`). Depuis la 1.1.0 :

* tous les codes langue sont normalisés vers un slug Polylang existant ;
* le groupe de traductions est réaffirmé après chaque `wp_update_post()` ;
* la boîte latérale se rabat sur n'importe quelle autre langue du groupe ;
* un bloc de diagnostic affiche la langue de l'article, la langue par défaut
  et le contenu du groupe de traductions.

= L'article traduit est vide / aucun bloc Gutenberg =

Les délimiteurs de blocs (`<!-- wp:paragraph -->`) sont protégés par des jetons
`<wppt id="N">` avant l'envoi à l'API. Si l'API les renvoie modifiés
(auto-fermés, guillemets simples, attributs réordonnés) ou les supprime, les
blocs disparaissent. Depuis la 1.1.0 la restauration se fait **par identifiant**
et non plus par comparaison de chaîne, et un contrôle d'intégrité bloque
l'enregistrement si des marqueurs manquent.

= L'éditeur affiche un article vide alors que la traduction est partie =

Vérifiez le statut de l'article cible. Une traduction créée via « + » reste au
statut `auto-draft` tant qu'elle n'a pas été enregistrée : l'éditeur de blocs
traite alors l'article comme vierge et ignore le `post_content` présent en base.
Depuis la 1.1.1 le plugin promeut automatiquement l'article en `draft` lors de
l'enregistrement de la traduction.

Pour confirmer le diagnostic sur une installation existante :

    wp post list --post_status=auto-draft --fields=ID,post_title,post_date
    wp post get <ID> --field=post_content | head

Si `post_content` contient bien du HTML avec des `<!-- wp:` alors que l'éditeur
est vide, c'est exactement ce cas de figure.

= Voir les erreurs =

Les 20 derniers messages sont conservés dans l'option `wppt_log`. Activez
`WP_DEBUG_LOG` pour les retrouver aussi dans `wp-content/debug.log`.
Les erreurs de traduction s'affichent désormais sous forme de notice dans
l'écran d'édition (et non plus uniquement dans les logs).

== Changelog ==

= 1.1.1 =
* Correction majeure : la traduction est promue de `auto-draft` à `draft` lors de l'enregistrement. Un article resté en `auto-draft` est considéré comme vierge par l'éditeur de blocs, qui ignore le contenu enregistré en base — d'où un article sans aucun bloc Gutenberg alors que les appels API aboutissaient
* Correction : le bouton « Retraduire depuis la source » ne réinjecte plus le contenu dans l'éditeur avant de recharger. Le contenu étant déjà enregistré côté serveur, l'injection marquait le post comme modifié et provoquait l'alerte « quitter le site ? », puis l'affichage d'un état incohérent après rechargement
* Ajout : journalisation post-enregistrement (statut avant/après, longueur du titre et du contenu, nombre de blocs) dans l'option `wppt_log`
* Documentation : section de dépannage « éditeur vide » avec commandes WP-CLI de vérification

= 1.1.0 =
* Compatibilité WordPress 7.0.2 et Polylang 3.8.6
* Correction : normalisation systématique des codes langue (locale `fr_FR` → slug `fr`) pour `pll_default_language()`, `pll_get_post_language()` et les paramètres `new_lang`
* Correction : la langue et le groupe de traductions Polylang sont réaffirmés après `wp_update_post()` (`pll_set_post_language` + `pll_save_post_translations`), ce qui corrige « Aucun article source trouvé dans la langue par défaut »
* Correction : restauration des fragments protégés par identifiant, tolérante aux réécritures de balisage par l'API (`<wppt id="3"/>`, guillemets simples, attributs supplémentaires) — corrige la perte des blocs Gutenberg
* Ajout : contrôle d'intégrité du balisage après traduction ; en cas de perte de marqueurs, la traduction du contenu est annulée et le contenu source est recopié tel quel plutôt que de produire un article vide
* Correction : le verrou anti-double-exécution est désormais libéré en fin de traitement (auparavant un échec bloquait toute nouvelle tentative pendant 5 minutes)
* Ajout : notices d'erreur/succès affichées dans l'écran d'édition après la redirection
* Ajout : journal des 20 derniers événements dans l'option `wppt_log`
* Ajout : bloc de diagnostic Polylang dans la boîte latérale « Traduction automatique »
* Amélioration : repli sur une autre langue du groupe quand la langue par défaut est absente
* Amélioration : `set_time_limit` porté à 300 s pour les articles longs
* Ajout : valeurs par défaut manquantes à l'activation (`deepl_fallback_service`, `relink_internal_urls`)

= 1.0.0 =
* Version initiale
