# Traductions - status et deploiement restant

Derniere mise a jour: 2026-04-11

Ce document sert de reference pour terminer la mise en place FR / EN / ES sur le site public et les couches de contenu associees.

## 1. Status actuel

### Deja en place
- Le frontend Vue charge maintenant `fr`, `en` et `es` via [resources/js/i18n/index.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/i18n/index.js).
- La fallback frontend est definie sur `en`, et `es` est merge au-dessus de `en` pour eviter les trous de traduction.
- La configuration Laravel expose bien `supported_locales = ['fr', 'en', 'es']` dans [config/app.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/config/app.php).
- La resolution backend des locales est centralisee dans [app/Support/LocalePreference.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/LocalePreference.php).
- Le changement de langue utilisateur passe par `POST /locale` dans [routes/web.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/routes/web.php).
- La fallback backend de contenu localise est deja couverte pour les pages, sections et mega menus.

### Travail termine dans la tranche recente
- `AppSeo` a ete remis dans l arbre Inertia et le JSON-LD ne casse plus le build Vue.
- [resources/js/Components/UI/CookieBanner.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/UI/CookieBanner.vue) utilise maintenant `vue-i18n`.
- [resources/js/Components/Public/PublicFooterMenu.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/Public/PublicFooterMenu.vue) utilise de nouvelles cles i18n pour le support, le contact et les badges store.
- [resources/js/Layouts/SettingsLayout.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Layouts/SettingsLayout.vue) n embarque plus des labels figes en francais pour la navigation settings.
- [resources/js/app.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/app.js) traduit maintenant le toast de session expiree.
- Le mega menu est maintenant stabilise sur `fr / en / es` de bout en bout:
  - builder locale-aware
  - fallback renderer corrige
  - seeders trilingues
  - ecrans SuperAdmin mega menu localises
- Les parcours publics transactionnels critiques avancent maintenant aussi par `vue-i18n`:
  - [resources/js/Pages/Public/QuoteAction.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/QuoteAction.vue)
  - [resources/js/Pages/Public/InvoicePay.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/InvoicePay.vue)
  - [resources/js/Pages/Public/WorkAction.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/WorkAction.vue)
- Les cles ont ete ajoutees dans [resources/js/i18n/fr.json](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/i18n/fr.json), [resources/js/i18n/en.json](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/i18n/en.json) et [resources/js/i18n/es.json](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/i18n/es.json).
- [lang/es/welcome.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/lang/es/welcome.php) et [lang/es/mail.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/lang/es/mail.php) existent maintenant et alimentent le backend `es` au lieu de retomber silencieusement sur `en`.
- Les templates email transactionnels utilisent maintenant des cles de traduction au lieu de labels FR/EN codes en dur:
  - [resources/views/emails/auth/invite.blade.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/views/emails/auth/invite.blade.php)
  - [resources/views/emails/auth/reset-password.blade.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/views/emails/auth/reset-password.blade.php)
  - [resources/views/emails/auth/two-factor-code.blade.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/views/emails/auth/two-factor-code.blade.php)
  - [resources/views/emails/billing/upcoming-reminder.blade.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/views/emails/billing/upcoming-reminder.blade.php)
  - [resources/views/emails/demo_workspaces/access.blade.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/views/emails/demo_workspaces/access.blade.php)
  - [resources/views/emails/notifications/digest.blade.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/views/emails/notifications/digest.blade.php)
- [app/Support/PublicPageStockImages.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicPageStockImages.php) couvre maintenant les `alt_es` manquants sur la librairie d images publiques.
- Les presets localises de sections publiques ont ete sortis dans [resources/js/utils/publicSectionPresets.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/publicSectionPresets.js), et sont maintenant utilises par:
  - [resources/js/Pages/SuperAdmin/Sections/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Sections/Edit.vue)
  - [resources/js/Pages/SuperAdmin/Pages/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Pages/Edit.vue)
- [database/seeders/MegaMenuSeeder.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/database/seeders/MegaMenuSeeder.php) regenere maintenant les pages produits, solutions, industries, contact et partners avec une locale `es` complete sur les titres, sous-titres et sections attendues par le sync public.
- [app/Support/PublicProductPageNarratives.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicProductPageNarratives.php) ne retombe plus sur `en` pour les 7 pages produits publiques: `sales-crm`, `reservations`, `operations`, `commerce`, `marketing-loyalty`, `ai-automation`, `command-center`.
- Les tests de non-regression ont ete etendus pour verrouiller:
  - les narratives produits en `es`
  - la regeneration `public-copy:sync` des pages publiques avec `es`
  - le seed de la page `partners`, qui etait definie mais pas semee
- [app/Http/Controllers/Settings/CompanySettingsController.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Http/Controllers/Settings/CompanySettingsController.php), [resources/js/Pages/Settings/Company.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Settings/Company.vue), [resources/js/Pages/Public/Store.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/Store.vue) et [resources/js/Pages/Public/Showcase.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/Showcase.vue) gerent maintenant le hero personnalise `es` de bout en bout:
  - edition dans les settings
  - persistence backend
  - captions par slide
  - fallback propre `es -> en -> fr` au rendu public
- [resources/js/i18n/es.json](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/i18n/es.json) couvre maintenant tout le sous-bloc `settings.company.store` utilise par l ecran Company:
  - hero settings
  - generation IA
  - labels de l editeur
- [tests/Feature/CompanySettingsStoreTranslationsTest.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/tests/Feature/CompanySettingsStoreTranslationsTest.php) verrouille la sauvegarde du hero personnalise `es` cote settings.
- [resources/js/Components/DatePicker.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/DatePicker.vue), [resources/js/Components/DateTimePicker.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/DateTimePicker.vue) et [resources/js/Components/Assistant/GlobalAssistant.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/Assistant/GlobalAssistant.vue) ne forcent plus un comportement `fr/en` seulement:
  - label horaire et action d effacement localises
  - synthese vocale / reconnaissance vocale compatibles `es`
- Le build Vite passe.

### Couverture deja verifiee
- [tests/Feature/SpanishLocaleSupportTest.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/tests/Feature/SpanishLocaleSupportTest.php) couvre:
  - l enregistrement de `es` comme preference utilisateur
  - la fallback des sections vers `en` quand `es` est partiel
  - la fallback des pages vers `en` quand `es` est partiel
  - la fallback des mega menus vers `en`
  - les defaults `welcome` espagnols
  - les labels transactionnels email en `es`
  - les `alt` publics `es` pour les visuels source-repo
- [tests/Feature/PublicCopySyncCommandTest.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/tests/Feature/PublicCopySyncCommandTest.php) couvre la regeneration du copy public depuis les sources repo.
- Verification 2026-04-11:
  - `php artisan test tests/Feature/SpanishLocaleSupportTest.php tests/Feature/PublicCopySyncCommandTest.php tests/Unit/MegaMenuServicesTest.php`
  - `npm run build`
- Verification complementaire 2026-04-11:
  - `php artisan test tests/Feature/SpanishLocaleSupportTest.php tests/Feature/PublicCopySyncCommandTest.php tests/Feature/PublicProductPagesTest.php tests/Unit/MegaMenuServicesTest.php`
- Verification approfondie 2026-04-11:
  - `php artisan test tests/Feature/CompanySettingsStoreTranslationsTest.php tests/Feature/SpanishLocaleSupportTest.php tests/Feature/PublicCopySyncCommandTest.php tests/Feature/PublicProductPagesTest.php tests/Feature/SoloOwnerOnlyAccessTest.php tests/Feature/MultiCurrencyBillingTest.php`
  - `npm run build`
- Verification finale de la passe 2026-04-11:
  - `php artisan test tests/Feature/CompanySettingsStoreTranslationsTest.php tests/Feature/SpanishLocaleSupportTest.php`
  - `npm run build`

## 2. Architecture actuelle

### Frontend
- Source des labels UI: `resources/js/i18n/*.json`
- Initialisation i18n: [resources/js/i18n/index.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/i18n/index.js)
- Strategie actuelle:
  - `fr` = `fr.json + marketing.fr.json`
  - `en` = `en.json + marketing.en.json`
  - `es` = `(en.json + marketing.en.json) + (es.json + marketing.es.json)`
- Regle pratique: toute chaine purement UI doit passer par `t(...)`.

### Backend
- Normalisation / ordre de resolution: [app/Support/LocalePreference.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/LocalePreference.php)
- Les services de contenu utilisent deja une logique de fallback pour reconstruire un payload complet meme si une locale est partielle.
- Les pages / sections marketing peuvent etre regenerees depuis le repo via:
  - `php artisan public-copy:sync --only=pages`
  - `php artisan public-copy:sync --only=welcome,footer`
  - commande definie dans [routes/console.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/routes/console.php)

### Point important
- Une partie importante du site public n utilise pas encore les JSON i18n frontend.
- Le copy metier est encore fabrique dans des classes PHP source-repo, souvent avec du branching `fr / es / en` inline.
- Ce n est pas bloquant fonctionnellement, mais c est le principal residu de dette pour finir la traduction proprement.

## 3. Zones restantes prioritaires

### Priorite 1 - Copy public seedé / source repo
Ce sont les plus gros blocs restant a traiter.

- [app/Support/PublicProductPageNarratives.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicProductPageNarratives.php)
  - plus gros volume restant
  - narratives produits encore codees en dur
  - logique majoritairement FR / EN avec fallback implicite
- [app/Support/PublicIndustryPageSections.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicIndustryPageSections.php)
  - sections industrie encore branchees par locale dans le code
- [app/Support/WelcomeEditorialSections.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/WelcomeEditorialSections.php)
  - bon candidat pour sortir le copy de la logique PHP
- [app/Support/WelcomeStockImages.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/WelcomeStockImages.php)
  - surtout des `alt` et labels locaux
- [app/Support/PublicPageStockImages.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicPageStockImages.php)
  - `alt_es` completes dans la passe recente
  - a garder dans le perimetre QA apres sync des pages industries / produits

### Priorite 2 - Services de sync / reconstruction
- [app/Services/PublicCopySyncService.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/PublicCopySyncService.php)
- [app/Services/PlatformWelcomePageService.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/PlatformWelcomePageService.php)
- [app/Services/PlatformSectionContentService.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/PlatformSectionContentService.php)
- [app/Services/WelcomeContentService.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/WelcomeContentService.php)

Objectif:
- garder la logique de structure dans les services
- deplacer progressivement les chaines et blocs editoriaux vers des sources de traduction ou des payloads localises plus lisibles

### Priorite 3 - UI publique restante
- [resources/js/Pages/Public/Page.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/Page.vue)
  - `frontHeroEyebrow` reste branche manuellement selon la locale
- [resources/js/Components/Public/PublicFooterMenu.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/Public/PublicFooterMenu.vue)
  - les `fallbackGroups` restent localises inline
- [resources/js/utils/storyGrid.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/storyGrid.js)
- [resources/js/utils/testimonialGrid.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/testimonialGrid.js)
- [resources/js/utils/publicCatalogSections.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/publicCatalogSections.js)

### Priorite 3 bis - Defaults SuperAdmin
- [resources/js/Pages/SuperAdmin/Sections/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Sections/Edit.vue)
- [resources/js/Pages/SuperAdmin/Pages/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Pages/Edit.vue)
- [resources/js/utils/publicSectionPresets.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/publicSectionPresets.js)

Etat actuel:
- les presets localises ne sont plus dupliques entre l edition des sections et l edition des pages
- les defaults admin ne reinjectent plus de branches `fr / es / en` disperses pour ces blocs

### Priorite 4 - Email / campagnes
Hors site public strict, mais gros volume restant si on veut une base vraiment coherente multilingue.

- [app/Services/Campaigns/EmailTemplateComposer.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/Campaigns/EmailTemplateComposer.php)
- [app/Services/Campaigns/TemplateSeederService.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/Campaigns/TemplateSeederService.php)

## 4. User story de livraison par phases

Objectif produit:
- En tant qu utilisateur final, je peux naviguer en francais, anglais ou espagnol sans voir de labels melanges, de fallback cassé, ni de parcours FR-only.
- En tant qu equipe produit, nous pouvons finir le chantier par verticales completes, avec une definition of done claire a chaque tranche.

### Phase 0 - Socle i18n et stabilisation globale
Status: `terminee`

Perimetre livre:
- chargement `fr / en / es`
- fallback frontend et backend
- switch de langue
- `AppSeo`
- cookie banner, footer, settings layout
- mega menu public + admin + seeding + renderer

Sortie attendue:
- aucune regression de locale sur les briques transverses

### Phase 1 - Parcours publics transactionnels
Status: `terminee`

But:
- finir les parcours publics sensibles qui doivent etre immediatement coherents pour un client externe

Perimetre:
- [resources/js/Pages/Public/QuoteAction.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/QuoteAction.vue)
- [resources/js/Pages/Public/InvoicePay.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/InvoicePay.vue)
- [resources/js/Pages/Public/WorkAction.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/WorkAction.vue)
- [resources/js/Pages/Public/Store.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/Store.vue) pour les libelles d accessibilite les plus visibles

Definition of done:
- plus aucun titre, bouton, placeholder ou statut visible en dur dans ces parcours
- les pages restent correctes en `fr`, `en`, `es`
- build Vite OK

### Phase 2 - UI publique restante et helpers frontend
Status: `en cours`

Perimetre:
- [resources/js/Pages/Public/Page.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/Public/Page.vue)
- [resources/js/Components/Public/PublicFooterMenu.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Components/Public/PublicFooterMenu.vue) pour les derniers fallbacks inline
- [resources/js/utils/storyGrid.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/storyGrid.js)
- [resources/js/utils/testimonialGrid.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/testimonialGrid.js)
- [resources/js/utils/publicCatalogSections.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/publicCatalogSections.js)
- [resources/js/utils/industryGrid.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/industryGrid.js)
- [resources/js/utils/featureTabs.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/featureTabs.js)
- [resources/js/utils/publicCopy.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/publicCopy.js)

Definition of done:
- toute la UI publique statique passe par `t(...)`
- les helpers ne fabriquent plus de copy inline non centralise

### Phase 3 - SuperAdmin pages et sections
Status: `en cours`

Perimetre:
- [resources/js/Pages/SuperAdmin/Sections/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Sections/Edit.vue)
- [resources/js/Pages/SuperAdmin/Pages/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Pages/Edit.vue)
- ecrans voisins si des defaults FR-only reapparaissent pendant la passe

Definition of done:
- les formulaires et defaults SuperAdmin ne reinjectent plus de texte mono-locale

Progression recente:
- [resources/js/Pages/SuperAdmin/Sections/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Sections/Edit.vue) et [resources/js/Pages/SuperAdmin/Pages/Edit.vue](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Pages/Edit.vue) s appuient maintenant sur [resources/js/utils/publicSectionPresets.js](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/resources/js/utils/publicSectionPresets.js) pour les presets localises partages.

### Phase 4 - Sources editoriales backend Welcome / produits / industries
Status: `en cours`

Perimetre:
- [app/Support/WelcomeEditorialSections.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/WelcomeEditorialSections.php)
- [app/Support/WelcomeStockImages.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/WelcomeStockImages.php)
- [app/Support/PublicProductPageNarratives.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicProductPageNarratives.php)
- [app/Support/PublicIndustryPageSections.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicIndustryPageSections.php)
- services de sync associes

Definition of done:
- le copy editorial est range par locale dans des structures lisibles
- `es` retombe proprement sur `en`
- `public-copy:sync` regenere sans trou

Progression recente:
- [app/Support/PublicIndustryPageSections.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicIndustryPageSections.php) et [app/Support/PublicIndustryShowcaseTabs.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicIndustryShowcaseTabs.php) couvrent maintenant les sections editoriales industries en `es`.
- [app/Support/PublicProductPageNarratives.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Support/PublicProductPageNarratives.php) couvre maintenant les 7 narratives produits en `es` sans fallback visible vers `en`.
- [database/seeders/MegaMenuSeeder.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/database/seeders/MegaMenuSeeder.php) regenere les payloads publics `es` des produits, solutions, industries, contact et partners.

### Phase 5 - Emails et campagnes
Status: `dernier bloc`

Perimetre:
- [app/Services/Campaigns/EmailTemplateComposer.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/Campaigns/EmailTemplateComposer.php)
- [app/Services/Campaigns/TemplateSeederService.php](/c:/Users/JulesRogerSombangnen/Herd/mlkpro_v3/app/Services/Campaigns/TemplateSeederService.php)

Decision attendue:
- choisir si ce module suit les memes cles i18n ou des templates localises par canal

### Ordre de travail recommande a partir d ici
1. Finir le reliquat Phase 2 sur `Page.vue`, `PublicFooterMenu.vue` et `publicCatalogSections.js`.
2. Terminer la Phase 3 avec une verification rapide des defaults admin voisins si un nouveau bloc reapparait.
3. Finir le reliquat Phase 4 sur `WelcomeEditorialSections.php` et `WelcomeStockImages.php`.
4. Garder la Phase 5 pour la fin une fois le site public stabilise.

## 5. Convention d implementation recommandee

### A faire
- Garder `en` comme fallback applicatif.
- Utiliser `t(...)` ou `trans(...)` pour toute UI generique.
- Garder le contenu editorial structure dans des payloads localises lisibles.
- Separer:
  - la structure de section
  - les assets / liens / icones
  - le texte par locale
- Quand `es` n est pas pret pour un bloc, laisser une fallback propre vers `en` plutot qu un mix incomplet.

### A eviter
- Ajouter de nouveaux `if ($locale === 'fr') ... else ...`
- Melanger structure, liens, images et copy dans le meme gros tableau non documente
- Duplicater des labels UI deja presents dans `resources/js/i18n/*.json`
- Ecrire du contenu FR-only dans les defaults SuperAdmin si le bloc est cense etre multilingue

## 6. Strategie de refactor recommandee

Pour chaque gros fichier de copy:

1. Identifier les sections stables:
   - id
   - layout
   - href
   - icon
   - image keys

2. Sortir le texte dans une map dediee, par exemple:

```php
private const SALES_CRM_COPY = [
    'fr' => [
        'hero' => [
            'title' => '...',
        ],
    ],
    'en' => [
        'hero' => [
            'title' => '...',
        ],
    ],
    'es' => [
        'hero' => [
            'title' => '...',
        ],
    ],
];
```

3. Centraliser la resolution locale:
   - `LocalePreference::normalize(...)`
   - fallback `en` si `es` incomplet

4. Laisser le service reconstruire la section finale a partir de:
   - `copy`
   - `visuals`
   - `links`
   - `defaults`

## 7. Validation minimale a chaque tranche

### Build
```powershell
& C:\Progra~1\nodejs\node.exe .\node_modules\vite\bin\vite.js build
```

### Tests a lancer
```powershell
php artisan test tests/Feature/CompanySettingsStoreTranslationsTest.php
php artisan test tests/Feature/SpanishLocaleSupportTest.php
php artisan test tests/Feature/PublicCopySyncCommandTest.php
php artisan test tests/Unit/MegaMenuServicesTest.php
```

### Sync de contenu apres refactor de copy public
```powershell
php artisan public-copy:sync --only=pages
php artisan public-copy:sync --only=welcome,footer
```

### QA manuelle
- Basculer FR / EN / ES depuis le language switcher
- Verifier welcome, pricing, contact, produits, industries
- Verifier les titres SEO et les CTA principaux
- Verifier qu une page `es` partielle retombe proprement sur `en`
- Verifier que les alt d images et labels de navigation suivent la locale

## 8. Backlog concret immediat

### Sprint recommande
- Ticket 1: fermer le reliquat Phase 2 sur `Page.vue`, `PublicFooterMenu.vue` et `publicCatalogSections.js`
- Ticket 2: terminer la Phase 3 avec verification des defaults admin restants
- Ticket 3: terminer la Phase 4 sur `WelcomeEditorialSections.php` et `WelcomeStockImages.php`
- Ticket 4: verifier le reliquat editorial public regenere apres `public-copy:sync`
- Ticket 5: preparer le dernier bloc Phase 5 autour des emails / campagnes

### Definition of done
- Aucun label UI public visible ne reste FR-only hors contenu editorial voulu
- Les pages publiques principales ont un payload FR / EN / ES coherent
- `es` retombe sur `en` sans trou visuel ni objet casse
- Le build Vite passe
- Les tests de fallback et de resync passent

## 9. Notes de vigilance

- `LocalePreference::DEFAULT_SUPPORTED` reste `['fr', 'en']`, mais la config effective ajoute `es` via `config/app.php`. Ce n est pas un bug tant que la config est chargee, mais il faut le garder en tete si un contexte de test contourne la config.
- Le plus gros risque n est plus technique, il est editorial: divergence entre copy repo, copy regeneree et labels UI.
- Il vaut mieux finir par verticales completes plutot que disperser des petites corrections sur tout le projet.
