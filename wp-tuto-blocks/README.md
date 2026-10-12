# WP Tuto Blocks

Plugin WordPress ajoutant des blocs Gutenberg pour la rédaction de tutoriels et de formations.

## Blocs inclus

### 1. Spoiler / Solution cachée

Bloc conteneur dont le contenu est masqué par défaut et révélé au clic.  
Idéal pour les **solutions de TP** ou les sections "indice".

**Fonctionnalités :**
- Supporte tous les blocs Gutenberg à l'intérieur (paragraphe, code, image…)
- 5 variantes de couleur : Info, Astuce, Succès, Attention, Danger
- Texte du bouton personnalisable (état fermé et ouvert)
- Option "ouvert par défaut"
- Animation CSS fluide

### 2. Texte avec badges numérotés

Paragraphe enrichi permettant d'insérer des badges Bootstrap pill numérotés via la syntaxe `<<N>>`.

**Utilisation :**
```
Cliquez sur le menu <<1>>, puis sur "Paramètres" <<2>> et validez <<3>>.
```

Rendu : Cliquez sur le menu **[1]** puis sur "Paramètres" **[2]** et validez **[3]**.

- 7 couleurs Bootstrap : primary, secondary, success, danger, warning, info, dark
- Rendu côté serveur (PHP) — compatible cache
- Prévisualisation live dans l'éditeur

---

## Installation

### Pré-requis
- WordPress 6.3+
- PHP 8.0+
- Node.js 18+ & npm (pour la compilation)

### Étapes

1. **Cloner / télécharger** le plugin dans `wp-content/plugins/wp-tuto-blocks/`

2. **Compiler les assets JS :**
   ```bash
   cd wp-content/plugins/wp-tuto-blocks
   npm install
   npm run build
   ```

3. **Activer le plugin** dans WordPress > Extensions.

### Développement

```bash
npm run start   # Mode watch avec hot-reload
npm run build   # Build de production
```

---

## Structure du plugin

```
wp-tuto-blocks/
├── wp-tuto-blocks.php          # Fichier principal
├── package.json
├── assets/
│   └── frontend.js             # Script toggle spoiler (frontend)
└── blocks/
    ├── spoiler/
    │   ├── block.json
    │   ├── index.js            # Éditeur (JSX)
    │   ├── style.css           # Frontend + éditeur
    │   └── editor.css          # Éditeur uniquement
    └── badge-text/
        ├── block.json
        ├── index.js            # Éditeur (JSX)
        ├── render.php          # Rendu serveur
        ├── style.css           # Frontend
        └── editor.css          # Éditeur uniquement
```

---

## FAQ

**Q : Le plugin charge-t-il Bootstrap ?**  
R : Non. Les styles des badges Bootstrap pill sont inclus directement dans le CSS du plugin. Si votre thème charge déjà Bootstrap 4/5, les styles sont compatibles.

**Q : Le contenu du bloc Badge Text est-il mis en cache ?**  
R : Oui. Le rendu est fait côté serveur (PHP), il est donc parfaitement compatible avec tous les plugins de cache WordPress.

**Q : Puis-je ajouter un bloc Code ou Image dans un Spoiler ?**  
R : Oui, le bloc Spoiler est un conteneur (`InnerBlocks`) qui accepte tous les blocs Gutenberg natifs.

---

## Roadmap

- [ ] Bloc "Étapes numérotées" avec timeline visuelle
- [ ] Bloc "Onglets" pour organiser du contenu
- [ ] Export PDF d'un article de formation
