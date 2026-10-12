=== WP Sondage ===
Contributors:      rdromain
Tags:              poll, sondage, survey, vote, shortcode, polylang
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.6.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Plugin de sondage avancé : radio/checkbox, sous-questions conditionnelles, antiflood RGPD, lazy-loading, thème sombre/clair, Polylang/WPML, stats admin.

== Description ==

WP Sondage permet de créer des sondages intégrés à votre thème WordPress.

= Fonctionnalités principales =

* Types de réponses : radio (choix unique) ou checkbox (choix multiples)
* Sous-questions conditionnelles (affichées si un choix parent est sélectionné)
* Catégories de sondages (taxonomie hiérarchique)
* Date de fin optionnelle / clôture manuelle
* Résultats publics ou privés (admin uniquement)
* Activation/désactivation front-end par sondage
* Bouton "Voir les résultats" sans voter (si résultats publics)

= Intégration thème =

* Page archive native WordPress (pas de CPT archive) — breadcrumb Rank Math automatique
* Pagination full AJAX (pas d'intérêt SEO)
* Dark mode automatique via variables CSS du thème (--rdr-* pour GeneratePress)
* Skeleton loader pendant le chargement AJAX
* Zéro jQuery en front — Vanilla JS uniquement

= Compatibilité cache & CDN =

Le plugin est conçu pour fonctionner avec les caches de page sans purge manuelle :

* Aucun nonce n'est écrit dans le HTML (il serait périmé une fois la page en cache)
* Les nonces d'écriture sont servis par les réponses AJAX, jamais mises en cache
* Les endpoints en lecture seule ne demandent aucun nonce (rate-limit à la place)
* La détection de bots se fait côté AJAX uniquement — jamais au rendu, ce qui
  évitait qu'une page vide générée pour un crawler soit servie à tous
* L'état personnel du visiteur (a-t-il voté) est chargé après le rendu
* admin-ajax.php est exclu automatiquement (LiteSpeed, WP Rocket, W3TC, Cloudflare)
* Retry automatique côté JS si un nonce périmé est détecté

Testé avec LiteSpeed Cache, WP Rocket, W3 Total Cache et Cloudflare.

= Antiflood RGPD =

* Hash SHA-256 de l'IP + User-Agent — aucune donnée personnelle stockée en clair
* Aucun cookie utilisé
* Bots/crawlers détectés et exclus — les shortcodes retournent "" pour les robots

= Compatibilité =

* Polylang : une page archive par langue, traduction des questions et réponses
* WPML : idem via icl_register_string / icl_t
* Rank Math SEO : breadcrumb HOME → Page archive → Titre du sondage

= Statistiques admin =

* Vue globale : KPI, graphique d'activité 30 jours, top 5 sondages
* Vue par sondage : répartition des votes, activité 30j, distribution horaire

== Installation ==

1. Téléchargez et décompressez le fichier zip
2. Uploadez le dossier `wp-sondage` dans `wp-content/plugins/`
3. Activez le plugin depuis "Extensions"
4. Allez dans **Sondages → Réglages** pour configurer la page archive

= Configuration de la page archive =

1. Créez une page WordPress normale avec le slug `sondages` (ou celui de votre choix)
2. Ajoutez une description dans le contenu de la page si souhaité
3. Dans **Sondages → Réglages**, sélectionnez cette page
4. Avec Polylang : répétez l'opération pour chaque langue

Aucun shortcode n'est nécessaire sur la page archive — la liste est injectée automatiquement.

== Shortcodes ==

= [wps_poll id="ID"] =
Affiche un sondage spécifique. Chargement lazy par défaut.

Paramètres :
* **id** (requis) : ID du sondage
* **lazy** : `1` (défaut) ou `0` pour un chargement immédiat

= [wps_poll id="ID" lazy="0"] =
Affiche un sondage sans lazy-loading.

= [wps_random] =
Affiche un sondage aléatoire ouvert que le visiteur n'a pas encore rempli.

= [wps_random category="slug"] =
Idem, filtré par catégorie.

== Notes techniques ==

= Slugs =
* CPT : `survey` (ex. `domain.com/survey/mon-sondage/`)
* Taxonomie : `survey-cat`
* Ces slugs neutres évitent tout conflit avec la page archive nommée "sondages"

= Tables créées =
* `{prefix}wps_votes` : votes avec hash IP/UA, timestamp
* `{prefix}wps_choices` : réponses avec support de l'arborescence parent/enfant

= Hooks disponibles =
* `wps_poll_question` : filter — traduit la question (Polylang/WPML)
* `wps_choice_label` : filter — traduit un label de réponse
* `wps_results_data` : filter — modifie les données de résultats AJAX
* `wps_after_save_poll` : action — déclenché après sauvegarde complète

== Changelog ==

= 1.5.0 =
* Nouveau : page WordPress native comme archive (fin du CPT archive)
* Nouveau : pagination full AJAX sur la page archive
* Nouveau : skeleton loader pendant le chargement
* Nouveau : sélecteur de page par langue dans les réglages (Polylang)
* Nouveau : breadcrumb Rank Math automatique (filtre rank_math/breadcrumbs/items)
* Fix : slug CPT changé en `survey` (évite le conflit avec la page "sondages")
* Fix : `has_archive` désactivé sur le CPT
* Fix : correction de l'appel `pll_languages_list()` avec affichage drapeau + slug

= 1.4.0 =
* Migration complète vers Vanilla JS (suppression de jQuery en front)
* Skeleton loader pour les widgets lazy
* CSS réduit de 22% en réutilisant les variables --rdr-* du thème GeneratePress
* Fix dark mode sur la liste des sondages (variables thème au lieu de media query)
* Fix doublons CSS supprimés

= 1.3.1 =
* Fix modal stats admin : boucle de scroll infinie
* Modal injectée dans <body> (hors contexte scrollable WP admin)
* Canvas Charts à hauteur fixe en px (suppression du vh)

= 1.3.0 =
* Nouveau : bouton "Voir les résultats" sans voter (aperçu)
* Nouveau : page de statistiques admin (KPI + graphiques Chart.js)
* Nouveau : modal de détail par sondage (activité, distribution horaire)

= 1.2.0 =
* Nouveau : actions bulk dans la liste admin (clore, ouvrir, masquer, reset)
* Nouveau : tri par votes fonctionnel (sous-requête JOIN)
* Nouveau : valeurs par défaut pré-remplies à la création
* Fix spinner CSS (double déclaration display)
* Fix thème dark forcé sur thème clair

= 1.1.0 =
* Nouveau : widget WordPress natif (sidebar)
* Nouveau : template single-wps_poll.php
* Nouveau : uninstall.php (nettoyage complet)
* Fix : résolution parent_id des sous-questions en 2 passes

= 1.0.0 =
* Version initiale

== Upgrade Notice ==

= 1.5.0 =
Après la mise à jour, allez dans Sondages → Réglages pour configurer la page archive.
Lancez une régénération des permaliens (Réglages → Permaliens → Enregistrer).
