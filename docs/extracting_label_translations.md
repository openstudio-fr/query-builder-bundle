# Extraction des traductions `#labelsByLang` vers le translator Symfony (XLIFF)

## Contexte

Le controller Stimulus [query_builder_controller.js](../query-builder-bundle/assets/controllers/query_builder_controller.js) embarque en dur ~80 lignes de labels EN/FR (`#labelsByLang`, lignes 42-119). Toute correction ou ajout de langue impose de modifier le controller. Décision utilisateur (QCM) : extraire vers des catalogues XLIFF gérés par le translator Symfony — les labels deviennent des fichiers de traduction standards, surchargeables et extensibles par l'application consommatrice sans forker le bundle.

## Architecture cible

Flux : XLIFF (bundle ou app) → `TranslatorInterface` dans `QueryBuilderType::buildView()` → tableau imbriqué `labels` → data-attribute Stimulus `labels: Object` → le controller lit `this.labelsValue` au lieu de `#labelsByLang[lang]`.

Le translator de FrameworkBundle découvre automatiquement le dossier `translations/` à la racine d'un bundle : aucune config à ajouter. Une app surcharge ou ajoute une locale en déposant `query_builder.de.xlf` dans son propre `translations/` (priorité app > bundle, mécanisme Symfony natif).

## Étapes

### 1. Catalogues XLIFF — nouveaux fichiers

- `translations/query_builder.en.xlf`
- `translations/query_builder.fr.xlf`

Domaine : `query_builder`. Clés à plat en notation pointée, reproduisant la structure imbriquée actuelle (contenu copié 1:1 depuis `#labelsByLang`) :

```
fields.title, value.title,
operators.title, operators.=, operators.!=, operators.<, operators.>, operators.<=, operators.>=,
operators.between, operators.notBetween, operators.contains, operators.doesNotContain,
operators.beginsWith, operators.endsWith, operators.in, operators.notIn,
operators.null, operators.notNull, operators.regex, operators.notRegex,
operators.<=ndays, operators.>=ndays, operators.valuesList,
addRule.label, addRule.title, removeRule.label, removeRule.title,
addGroup.label, addGroup.title, removeGroup.label, removeGroup.title,
and, or, not, validationError
```

Note : `not` et `validationError` ne sont lus nulle part dans le JS (clés mortes déjà présentes) — conservées à l'identique (candidates aux futures props `notToggle`/validation de react-querybuilder), signalé au rapport final.

### 2. `composer.json`

Ajouter `"symfony/translation": "^7.4"` au `require`.

### 3. [QueryBuilderType.php](../query-builder-bundle/src/Form/QueryBuilderType.php)

- Injecter `Symfony\Contracts\Translation\TranslatorInterface $translator` au constructeur.
- Constante privée listant les clés ci-dessus.
- `buildView()` (après le `substr($lang, 0, 2)` existant, ligne 87) : construire `$view->vars['labels']` — tableau imbriqué reconstruit depuis les clés pointées (split sur le **premier** `.` uniquement : `fields.title` → `['fields']['title']`, `and` reste scalaire ; les noms d'opérateurs ne contiennent pas de point).
- **Fallback `en` préservé** (comportement actuel : langue inconnue → labels anglais, indépendamment de la config de fallback de l'app) : si le translator est un `TranslatorBagInterface`, pour chaque clé absente de `getCatalogue($lang)` (le `has()` remonte la chaîne de fallback), traduire avec la locale explicite `'en'` ; sinon `trans($key, [], 'query_builder', $lang)` simple.

### 4. [config/services.php](../query-builder-bundle/config/services.php)

Sur le service `QueryBuilderType` : `->arg('$translator', service('translator'))` (le translator est auto-activé par FrameworkBundle dès que le composant est installé).

### 5. [templates/form/query_builder.html.twig](../query-builder-bundle/templates/form/query_builder.html.twig)

Ajouter `labels: labels,` dans l'appel `stimulus_controller(...)`, à côté des 4 values existantes.

### 6. [query_builder_controller.js](../query-builder-bundle/assets/controllers/query_builder_controller.js)

- Supprimer `#labelsByLang` (lignes 42-119) et `#fallbackLang` (lignes 39-40).
- `static values` : ajouter `labels: { type: Object, default: {} }`. **Garder `lang`** (encore utilisé ligne 147 pour `localeCompare`).
- `#getLabelsTranslation()` (ligne 592-593) : remplacer la résolution par `const labelsByLang = this.labelsValue;` — le reste (`#getTranslations`, `#getLabelTranslation`, `#makeOperatorLabel`, `#getCombinators`) fonctionne sans changement.

### 7. README

Section « Language » (lignes 258-269) : documenter le nouveau fonctionnement — labels dans `translations/query_builder.{en,fr}.xlf`, surcharge/ajout de locale via le `translations/` de l'application, fallback anglais conservé.

## Vérification

1. Gate qualité du bundle : `composer install` puis php-cs-fixer, PHPStan, Psalm (scripts composer existants du repo — pas de PHPUnit ni de test JS dans ce bundle, à signaler).
2. Contrôle statique croisé : diff clé-à-clé entre l'ancien objet `#labelsByLang` et les deux XLIFF (aucune valeur perdue ou modifiée).
3. Validation XLIFF : `bin/console lint:xliff translations/` si une app hôte est dispo, sinon `xmllint`.
4. Si une app consommatrice locale existe : rendu du widget en `fr` puis `lang: 'en'` puis langue inconnue (`de`) → labels anglais attendus ; vérifier le `data-query-builder-labels-value` dans le DOM.

## Hors périmètre / non fait

- Pas de purge des clés mortes `not`/`validationError` (conservées, signalées).
- Pas d'ajout de tests (le bundle n'a aucune infra de test).
- Le bundle page-builder-bundle (répertoire principal) n'est pas touché.
