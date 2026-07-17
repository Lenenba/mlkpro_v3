# MLK Pro — notes de preuve de l’audit du 16 juillet 2026

Ce fichier consigne les mesures, constats de code, résultats de validation et sources officielles utilisés dans le rapport. Il ne contient aucun secret ni valeur de configuration sensible.

## Périmètre et méthode

- Audit statique du dépôt Laravel/Vue, des routes, contrôleurs, services, modèles, migrations, tests, files asynchrones, planificateur et configuration Vite.
- Construction de production avec `npm run build`.
- Contrôles `composer audit --locked`, `npm audit --omit=dev --json`, `composer qa:test`, `composer qa:format`, `composer qa:analyse`, rapports d’observabilité et de capacité intégrés.
- Inspection visuelle de captures déjà présentes dans le dépôt. Le navigateur interactif de la session n’était pas disponible; aucune conclusion d’ergonomie interactive n’est donc présentée comme vérifiée en direct.
- Benchmark fondé sur les pages produit, sécurité, démonstration et aide officielles des concurrents, consultées le 16 juillet 2026.

## Empreinte du produit

- Stack: Laravel 11.47, PHP 8.4.23, Inertia 2, Vue 3, Vite 6, Tailwind 3 et Preline.
- 1 083 routes au total, dont 391 sous `/api`.
- 408 fichiers JavaScript/Vue représentant 179 643 lignes.
- 845 fichiers PHP dans l’application et les routes, représentant 178 248 lignes.
- 166 contrôleurs, environ 302 services/modules, 151 modèles et 273 migrations.
- 1 119 tests Pest recensés. Tous ont réussi en deux segments: la commande Composer a été interrompue par son délai fixe de 300 secondes, puis les 53 fichiers restants ont réussi directement, soit 270 tests et 2 794 assertions sur ce second segment.
- `composer qa:format` a réussi. `composer qa:analyse` a atteint environ 90 % sans erreur signalée avant le délai Composer de 300 secondes; le contrôle statique complet reste donc non confirmé.

## Mesures de construction et de latence locale

La construction Vite de production a réussi en 1 min 28 s avec 2 603 modules. Principaux actifs mesurés:

| Actif | Taille brute | Taille gzip |
|---|---:|---:|
| app JavaScript | 644,10 kB | 188,60 kB |
| app CSS | 267,25 kB | non rapportée |
| langue française | 393,19 kB | 116,10 kB |
| langue anglaise | 363,39 kB | 106,60 kB |
| langue espagnole | 346,33 kB | 102,60 kB |
| AuthenticatedLayout | 210,95 kB | 48,80 kB |
| schedule | 231,29 kB | 68,70 kB |
| vuedraggable | 177,25 kB | 61,80 kB |

Les 308 morceaux JavaScript totalisent 7,26 MB bruts et 1,91 MB gzip, sans signifier qu’ils sont tous chargés ensemble.

Mesures locales indicatives, en environnement de développement avec débogage actif:

- accueil public: première réponse froide à 17,75 s, puis environ 0,69 à 1,35 s; réponse HTML de 159 591 octets;
- connexion: environ 0,29 à 0,61 s; 107 936 octets;
- tarification: environ 0,53 à 1,09 s; 221 922 octets;
- la table Ziggy `@routes` représente environ 101 708 caractères injectés dans chaque page HTML.

Le rapport `observability:report --json` était `critical`, mais sur un seul échantillon de route `welcome` à 7 779,6 ms. Le rapport `capacity:report --json` était `warning` parce que tous les scénarios manquaient de données. Ces valeurs ne constituent pas une mesure de production exploitable; elles justifient l’instrumentation.

## Constats techniques prioritaires

### Files asynchrones

- `app/Jobs/AnalyzePlanScanJob.php:26-40` demande le workload `plan_scans`. La configuration actuelle le résout vers `default`, file consommée par le travailleur de développement; le service l’exécute aussi en ligne en environnement local par défaut. Une surcharge `ASYNC_QUEUE_PLAN_SCANS=plan-scans` exigerait toutefois d’ajouter cette file au worker concerné.
- `app/Jobs/PublishSocialPostTargetJob.php:17-27` demande le workload `social_publish`, absent de `config/async.php:4-46`; son fallback `social-publish` n’est pas consommé par le travailleur de développement déclaré dans `composer.json:65`.
- L’inventaire doit donc vérifier les valeurs d’environnement et les workers de chaque environnement, puis couvrir automatiquement la correspondance workloads/files/workers afin d’éviter toute dérive de configuration.
- `AnalyzePlanScanJob` intercepte les erreurs sans les relancer à `app/Jobs/AnalyzePlanScanJob.php:148-170`, ce qui neutralise les tentatives et le backoff prévus.

### Requêtes et pagination

- Boîte de demandes: lecture large puis pagination en mémoire dans `app/Queries/Requests/BuildRequestInboxIndexData.php:47-69` et `:279-290`.
- Pipeline CRM: lecture large et pagination de collection dans `app/Queries/CRM/BuildSalesPipelineIndexData.php:100-164` et `:425-454`.
- Relance des devis: `app/Queries/Quotes/BuildQuoteRecoveryIndexData.php:62-79` et `:263-277`.
- Tableau prospects: agrégation PHP de toutes les lignes actives dans `app/Queries/Prospects/BuildProspectDashboardData.php:19-37`.

Les DTO et props Inertia peuvent rester inchangés pendant que filtres, tris, agrégations et pagination migrent vers SQL dans les Query Objects.

### JavaScript et navigation

- `resources/js/app.js:189-203`, `:301-306` et `:315-317` déclenchent l’initialisation complète de Preline après chaque montage de composant, avec temporisation, puis après navigation. Cette stratégie multiplie les parcours du DOM.
- `import.meta.glob` découpe déjà les pages; il faut préserver cette architecture et optimiser les dépendances globales.
- Le système i18n charge de gros blocs de langue et la langue anglaise de repli. Un découpage par domaine fonctionnel réduirait le coût initial sans modifier les clés ni le comportement.

### Serveur, cache et partage Inertia

- `app/Http/Controllers/DashboardController.php:897-950` effectue de nombreux `count`/`sum` indépendants; la synthèse financière à `:2042-2125` en ajoute d’autres. Le cache est consulté tardivement par rapport au travail effectué.
- `app/Http/Middleware/HandleInertiaRequests.php:40-164` partage propriétaire, fonctionnalités, abonnement, permissions, maintenance, notifications, planning et démo à chaque requête authentifiée.
- `resources/js/Components/NotificationBell.vue:31-56` interroge notifications et planning toutes les 30 secondes par onglet.
- Les valeurs par défaut locales pour cache, session et file utilisent la base de données, malgré une configuration Redis disponible. Les valeurs de production doivent être vérifiées avant toute migration.
- `app/Services/Observability/ObservabilityCacheStore.php:12-39` réalise un read-modify-write de tableaux complets sans verrou; des compteurs Redis atomiques ou un APM sont plus adaptés.

### Taille, médias et maintenabilité

- Les fichiers JavaScript/Vue les plus grands atteignent 5 041, 3 852, 3 596, 3 291 et 3 278 lignes.
- Les plus grands fichiers PHP incluent `AssistantWorkflowService.php` à 3 192 lignes et `ExpenseController.php` à 2 927 lignes.
- Les médias publics représentent environ 20,5 MB, dont 10,1 MB d’images; plusieurs JPG marketing pèsent entre 500 et 925 kB.
- Montserrat est importée via CSS. Préconnexion, balise de chargement ou auto-hébergement WOFF2 sont préférables.
- Le niveau PHPStan actuel est 0; il devrait augmenter progressivement avec une baseline, module par module.

## Sécurité et dépendances

- Des identifiants Twilio ont été révélés dans le contexte IDE/conversation. Le jeton doit être révoqué et remplacé immédiatement, puis l’historique et les journaux concernés contrôlés. Aucune valeur secrète n’est reproduite ici.
- `.env` n’est pas suivi par Git et figure dans `.gitignore`.
- `composer audit --locked` a signalé des avis 2026, dont des avis de sévérité élevée pour Laravel, Symfony HttpKernel, Symfony Mime et PHPUnit, ainsi que des avis Guzzle, CommonMark et PsySH.
- `npm audit --omit=dev --json` a signalé `lodash` en sévérité élevée et modérée, ainsi que PostCSS en sévérité modérée; des correctifs sont disponibles.
- `twilio/sdk` est déclaré avec la contrainte `*`; il faut le contraindre à une version compatible et revue.

Sources d’avis citées:

- https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq
- https://github.com/advisories/GHSA-crmm-hgp2-wgrp
- https://github.com/advisories/GHSA-6439-2f28-8p8q
- https://github.com/advisories/GHSA-qpmx-3rfj-7rhv
- https://github.com/advisories/GHSA-r5fr-rjxr-66jc
- https://github.com/advisories/GHSA-qx2v-qp2m-jg93

## Forces fonctionnelles vérifiées dans le dépôt

Le produit couvre déjà CRM/prospects, clients, devis, travaux, tâches, factures, paiements, dépenses et comptabilité, réservations/file/kiosque, inventaire/ventes, campagnes et social, assistant IA, portail client, Stripe/Paddle, multi-entreprise, multi-devise, rôles/permissions, multilingue et espaces de démonstration.

Les tests de comptabilité couvrent notamment comptes, mappages, journal, taxes, export, révision/réconciliation, clôture/réouverture de périodes, piste d’audit et résumé mobile.

## Benchmark concurrentiel officiel

### QuickBooks

- Référence généraliste PME: comptabilité, banque, taxes, factures/paiements, projets, stock, prévisions et hub client.
- Intuit Intelligence et agents spécialisés; écosystème de plus de 450 applications annoncé par l’éditeur.
- Sources: https://quickbooks.intuit.com/ca/pricing/ ; https://quickbooks.intuit.com/ca/intuit-intelligence/ ; https://quickbooks.intuit.com/ca/how-it-works/ ; https://quickbooks.intuit.com/learn-support/en-ca/help-article/small-business-processes/test-drive-quickbooks-online/L9C12ODlA_CA_en_CA ; https://quickbooks.intuit.com/ca/security/

### Xero

- Collaboration comptable, utilisateurs illimités selon les offres, Hubdoc et flux bancaires.
- JAX met en avant des actions expliquées, journalisées et soumises à révision; l’éditeur annonce plus de 1 000 applications et 21 000 institutions financières.
- Sources: https://www.xero.com/ca/accounting-software/all-features/ ; https://www.xero.com/ca/ai-in-accounting/ ; https://www.xero.com/ca/ai-in-accounting/jax/ ; https://central.xero.com/s/article/Use-the-demo-company ; https://www.xero.com/us/security/

### FreshBooks

- Flux services particulièrement lisible: proposition, dépôt, projet/temps/dépense, facture, paiement et relances.
- Interface et application mobile simples; l’éditeur annonce plus de 100 intégrations.
- Sources: https://www.freshbooks.com/en-ca/invoicing-software ; https://www.freshbooks.com/en-ca/accounting-app ; https://support.freshbooks.com/hc/en-us/articles/115015407988-How-do-I-use-my-dashboard ; https://www.freshbooks.com/policies/security-safeguards

### Zoho Books

- Densité fonctionnelle, approbations, portails, modules/fonctions personnalisés.
- Zia peut créer et rechercher des transactions, visualiser, prévoir et signaler des anomalies; Zoho propose aussi un MCP officiel.
- Sources: https://www.zoho.com/ca/books/accounting-software-features/ ; https://www.zoho.com/us/books/help/ai-features/ai-features.html ; https://www.zoho.com/ca/books/ ; https://www.zoho.com/ca/books/videos/general/zb-navigation.html ; https://www.zoho.com/trust.html

### Sage

- Bon ancrage canadien et bilingue, avec TPS/TVH/TVP/TVQ, ARC, banque, facture, trésorerie et paie.
- Sources: https://www.sage.com/en-ca/sage-business-cloud/accounting/ ; https://www.sage.com/en-ca/sage-business-cloud/accounting/features/accounts-payable/ ; https://www.sage.com/en-ca/sage-business-cloud/accounting/features/invoicing/ ; https://www.sage.com/en-ca/trust-security/security/technical/standards-compliance/

### Wave

- Entrée gratuite avec estimations, factures, factures fournisseurs et tenue de livres; le plan Pro ajoute import bancaire, OCR et rappels.
- Limité au Canada et aux États-Unis, avec des rôles plus restreints.
- Sources: https://www.waveapps.com/pricing ; https://www.waveapps.com/accounting ; https://www.waveapps.com/receipts/ ; https://www.waveapps.com/payments ; https://www.waveapps.com/wave-app ; https://support.waveapps.com/hc/en-us/articles/115004085146-How-Wave-keeps-your-data-secure

### Odoo

- ERP intégré où les opérations alimentent la comptabilité, avec automatisation, rapprochement et PWA.
- Les chiffres de vitesse et d’automatisation cités sur ses pages sont des déclarations marketing de l’éditeur, non des mesures indépendantes.
- Sources: https://www.odoo.com/app/accounting-features ; https://www.odoo.com/app/accounting ; https://www.odoo.com/documentation/19.0/applications/finance/accounting/bank/reconciliation.html ; https://www.odoo.com/documentation/19.0/administration/mobile.html ; https://www.odoo.com/security ; https://demo.odoo.com

### Pennylane

- OS financier orienté collaboration entreprise/comptable: achat, approbation, paiement et écriture, avec IA et mobile solides.
- Positionnement principalement français plutôt que canadien.
- Sources: https://www.pennylane.com/fr/toutes-nos-fonctionnalites ; https://help.pennylane.com/fr/articles/523947-utiliser-l-ia-dans-pennylane ; https://www.pennylane.com/fr/application-mobile ; https://www.pennylane.com/fr/securite ; https://help.pennylane.com/fr/articles/43472-securiser-son-compte-avec-l-authentification-a-deux-facteurs-2fa ; https://www.pennylane.com/fr/expert-comptable/ged-collaboration

## Audit visuel sur captures locales

Les captures `tmp-home-full.png` et `tmp-sales-crm-fixed-wait.png` montrent une base de marque cohérente: vert sombre, typographie éditoriale et hiérarchie globalement propre. Les écarts observables sont le défilement très long, des zones de média vides ou peu démonstratives, quelques incohérences de langue et peu de preuves visuelles du produit. Les concurrents les plus convaincants montrent davantage d’écrans réels, d’actions, de résultats et de confiance. Ces constats portent sur les captures du dépôt, pas sur une session interactive en direct.

## Garde-fous proposés

- Conserver routes, noms, props Inertia, DTO, événements et contrats API pendant les optimisations internes.
- Utiliser des drapeaux de fonctionnalité, requêtes parallèles en mode ombre et comparaison de résultats avant bascule.
- Ajouter des tests de contrat pour les files, Query Objects, permissions et schémas de réponse.
- Déployer un module à la fois avec canari, métriques avant/après et retour arrière documenté.
- Ne migrer cache, queue puis sessions vers Redis qu’après vérification de la configuration de production et test de reprise.
- Faire prévisualiser, approuver, annuler et auditer toute action IA qui modifie des données financières ou opérationnelles.
