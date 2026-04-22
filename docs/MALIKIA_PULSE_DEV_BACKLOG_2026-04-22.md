# Malikia Pulse dev backlog and implementation tracker

Derniere mise a jour: 2026-04-22

## 0. Etat d'avancement implementation

Suivi courant:

- `PULSE-001` fait dans le workspace
- `PULSE-002` fait, socle comptes + OAuth + UI livres
- `PULSE-003` fait
- `PULSE-004` fait
- `PULSE-005` fait
- `PULSE-006` a faire
- `PULSE-007` a faire
- `PULSE-008` a faire
- `PULSE-009` a faire
- `PULSE-010` a faire

Bloc deja livre dans le workspace:

- feature flag tenant `social` branche
- permissions equipe `social.view / social.manage / social.publish / social.approve` exposees
- module `social` expose dans les reglages plan / super admin
- migration additive `social_account_connections` en place
- model `SocialAccountConnection` aligne
- registre `SocialProviderRegistry` ajoute
- providers cibles `facebook / instagram / linkedin / x` cadres
- routes web et api `social.accounts.*` ajoutees
- CRUD owner pour les comptes Pulse ajoute
- lecture read-only pour les team members avec droits `social.*` ajoutee
- callback OAuth + persistence chiffree des credentials ajoutes
- refresh token manuel par provider ajoute
- schema additif `social_posts` et `social_post_targets` en place
- models `SocialPost` et `SocialPostTarget` alignes
- workspace Pulse `index / accounts / composer` branche
- draft backend `social.posts.*` ajoute
- orchestration de publication Pulse ajoutee
- jobs `PublishSocialPostTargetJob` par target ajoutes
- routes `social.posts.publish` et `social.posts.schedule` ajoutees
- publication immediate + planification simple branchees
- calcul du statut global `draft / scheduled / publishing / published / partial_failed / failed` branche
- suites feature dediees Pulse ajoutees

Bloc actuellement en cours:

- lancement de `PULSE-006` historique + duplication + repost

Bloc restant pour arriver a un MVP V1 propre:

- ajouter historique, duplication et repost
- brancher templates, prefill et suggestions

## 1. But du document

Ce document transforme la user story Pulse en plan dev executable et suivi durable.

Le but est simple:

- savoir quoi coder d'abord
- savoir ce qui est deja livre
- savoir ce qui reste dans chaque ticket
- eviter que le scope derive pendant l'evolution du module
- garder une trace propre des validations et des blocages

## 2. Comment utiliser ce document

Regle de suivi:

1. avant de commencer une session, relire `section 0` et le ticket courant
2. passer le ticket ou sous-bloc en `en cours` dans cette doc si besoin
3. coder uniquement le scope du ticket courant
4. lancer les verifications du ticket
5. mettre a jour:
   - l'etat du ticket
   - les verifications realisees
   - le journal de suivi en fin de document

Statuts recommandes:

- `a faire`
- `en cours`
- `bloque`
- `fait`
- `partiel`
- `reporte`

Regle importante:

- un ticket ne passe en `fait` que si:
  - le scope cible est livre
  - les acceptance criteria sont couverts
  - les tests de verification ont ete executes

## 3. Scope produit a garder en tete

Rappel du scope V1:

- connecter plusieurs comptes sociaux par tenant
- composer un post `texte + image + lien`
- cibler plusieurs comptes
- publier maintenant ou planifier
- conserver un historique simple
- reutiliser templates, duplication et repost
- pre-remplir depuis `promotions / products / services / campaigns`

Hors scope V1 initial:

- analytics avances par plateforme
- automatisations basees sur evenements
- planning editorial riche
- moteur IA complexe de variantes par reseau

## 4. Regles dev non negociables

1. ne jamais exposer Pulse si `hasFeature('social')` est faux
2. ne jamais exposer une action Pulse si le module source est desactive
3. garder un scope tenant strict sur comptes, posts, templates et approvals
4. reutiliser les patterns `campaigns` quand ils sont utiles, sans dupliquer inutilement
5. garder une couche provider claire entre le coeur Pulse et les reseaux sociaux
6. commencer par un schema additif et lisible avant l'UI
7. pousser la publication reelle en job par target des que le workflow sort du trivial
8. privilegier des suites de tests dediees Pulse plutot que diluer dans des tests trop larges

## 5. Fichiers coeur a proteger

### Backend

- `app/Services/CompanyFeatureService.php`
- `app/Http/Controllers/TeamMemberController.php`
- `app/Http/Controllers/SuperAdmin/TenantController.php`
- `app/Services/SuperAdminPlatformSettingsService.php`
- `app/Models/User.php`
- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/PromotionController.php`
- `app/Http/Controllers/ProductController.php`
- `app/Http/Controllers/ServiceController.php`
- `app/Http/Controllers/CampaignController.php`
- `app/Services/Campaigns/*`

### Frontend

- `resources/js/Layouts/*`
- `resources/js/Pages/Promotions/*`
- `resources/js/Pages/Product/*`
- `resources/js/Pages/Service/*`
- `resources/js/Pages/Campaigns/*`
- futur module `resources/js/Pages/Social/*`
- i18n `resources/js/i18n/modules/*`

### Tests existants a proteger

- `tests/Feature/TeamMemberPermissionModulesTest.php`
- `tests/Feature/SuperAdminModuleAuthorizationTest.php`
- `tests/Unit/CompanyFeatureServiceTest.php`
- `tests/Feature/PlanEntitlementSyncCommandTest.php`
- `tests/Feature/CampaignsMarketingModuleTest.php`

## 6. Suites de tests Pulse recommandees

Suites deja presentes:

- `tests/Feature/SocialAccountConnectionsPulseTest.php`
- `tests/Feature/SocialAccountConnectionManagementTest.php`

Suites a creer ensuite:

- `tests/Feature/SocialPostsSchemaTest.php`
- `tests/Feature/SocialComposerFeatureTest.php`
- `tests/Feature/SocialPublishingFeatureTest.php`
- `tests/Feature/SocialHistoryFeatureTest.php`
- `tests/Feature/SocialTemplateFeatureTest.php`
- `tests/Feature/SocialPrefillFeatureTest.php`
- `tests/Feature/SocialSuggestionsFeatureTest.php`
- `tests/Feature/SocialApprovalWorkflowTest.php`
- `tests/e2e/pulse-smoke.spec.js`

Regle:

- ne pas diluer Pulse dans un seul mega test campaigns
- garder un socle de non-regression Pulse lisible ticket par ticket

## 7. Sprint 1 - Fondations module et connexions

Objectif:

- rendre Pulse activable
- poser le socle data des comptes connectes
- verrouiller les acces et la structure provider

Tickets:

- `PULSE-001`
- `PULSE-002`

### PULSE-001 - Module flag `social` + permissions `social.*`

#### But

Rendre Pulse visible et gouvernable comme un vrai module de plateforme.

#### Etat

- `fait` dans le workspace au `2026-04-22`

#### Livrables

- ajout de `social` dans `CompanyFeatureService`
- exposition du module dans les reglages super admin / plan
- permissions equipe `social.*`
- garde feature dans les surfaces Pulse

#### Fichiers probables

- `app/Services/CompanyFeatureService.php`
- `app/Http/Controllers/TeamMemberController.php`
- `app/Http/Controllers/SuperAdmin/TenantController.php`
- `app/Services/SuperAdminPlatformSettingsService.php`
- `resources/js/Pages/SuperAdmin/Settings/Edit.vue`
- `resources/js/i18n/modules/*/super_admin.json`
- `resources/js/i18n/modules/*/team.json`

#### Acceptance criteria

1. un tenant peut activer ou desactiver `social`
2. les droits `social.*` apparaissent seulement si le module est actif
3. aucune surface Pulse ne fuit quand `social` est off

#### Verification recommandee

```powershell
php artisan test tests/Feature/TeamMemberPermissionModulesTest.php
php artisan test tests/Feature/SuperAdminModuleAuthorizationTest.php
php artisan test tests/Unit/CompanyFeatureServiceTest.php
php artisan test tests/Feature/PlanEntitlementSyncCommandTest.php
```

### PULSE-002 - Schema comptes connectes + providers

#### But

Poser le socle data et metier des comptes sociaux connectes.

#### Etat

- `fait`
- socle backend, callback OAuth, refresh provider et UI comptes livres le `2026-04-22`

#### Livrables cibles

- migration additive `social_account_connections`
- model `SocialAccountConnection`
- registre providers sociaux
- API owner CRUD + lecture equipe
- feature gating `social`
- definition claire du scope OAuth reel et du hors-scope `PULSE-002`

#### Livraison constatee

- migration `social_account_connections` en place
- model `SocialAccountConnection` aligne
- relation `User -> socialAccountConnections` en place
- registre `SocialProviderRegistry` ajoute
- providers cadres:
  - `FacebookPagePlatformPublisher`
  - `InstagramBusinessPlatformPublisher`
  - `LinkedInPagePlatformPublisher`
  - `XProfilePlatformPublisher`
- service `SocialAccountConnectionService` ajoute
- controller `SocialAccountConnectionController` ajoute
- routes:
  - `social.accounts.index`
  - `social.accounts.store`
  - `social.accounts.update`
  - `social.accounts.authorize`
  - `social.accounts.refresh`
  - `social.accounts.disconnect`
  - `social.accounts.destroy`
  - `social.accounts.oauth.callback`
- access:
  - owner peut gerer
  - team member avec `social.*` peut voir en lecture seule
- OAuth reel:
  - `oauth_state` + expiration persistants
  - PKCE `x` persiste pendant le redirect
  - code OAuth echange contre token chiffre
  - refresh manuel par provider disponible
  - callback refuse si le module `social` est desactive
- tests dedies ajoutes et verts

#### Sous-decoupage interne recommande

##### PULSE-002A - data model et providers

- `fait`

##### PULSE-002B - CRUD backend et access control

- `fait`

##### PULSE-002C - OAuth callback et persistence reelle des secrets

- `fait`

##### PULSE-002D - UI comptes Pulse

- `fait`

#### Fichiers probables

- `database/migrations/*social_account_connections*`
- `app/Models/SocialAccountConnection.php`
- `app/Services/Social/*`
- `app/Http/Controllers/SocialAccountConnectionController.php`
- `routes/web.php`
- `routes/api.php`
- futur `resources/js/Pages/Social/Accounts.vue`

#### Notes d'implementation

- plusieurs comptes d'une meme plateforme doivent etre supportes
- unicite a garantir par `tenant + platform + external_account_id`
- les credentials doivent rester chiffres
- l'OAuth reel ne doit pas etre bricole dans le controller
- garder la logique provider dans des classes dediees

#### Frontiere `PULSE-002`

Inclus dans `PULSE-002`:

- preparation d'un brouillon de compte Pulse par tenant
- redirect OAuth par provider avec `state` securise
- persistence chiffree des credentials, scopes et dates d'expiration
- refresh manuel des tokens via la strategie provider
- UI comptes Pulse owner + lecture seule equipe
- blocage des surfaces et du callback si `social` est off

Hors scope `PULSE-002`:

- decouverte automatique des pages/profils distants apres OAuth
- refresh automatique en job ou cron
- publication reelle de posts
- schema `posts + targets`
- composeur et historique

#### Acceptance criteria

1. un owner peut enregistrer plusieurs comptes sociaux
2. le systeme expose le statut et les metadonnees utiles par compte
3. un membre equipe autorise peut consulter sans modifier
4. si `social` est off, toutes les routes Pulse sont bloquees
5. la frontiere entre socle provider et OAuth reel est documentee

#### Verification realisee

```powershell
php artisan test tests/Feature/SocialAccountConnectionsPulseTest.php tests/Feature/SocialAccountConnectionManagementTest.php tests/Feature/SocialAccountConnectionOauthTest.php
```

## 8. Sprint 2 - Posts, targets et composeur

Objectif:

- rendre Pulse capable de stocker un post multi-cibles
- exposer un composeur simple mais propre

Tickets:

- `PULSE-003`
- `PULSE-004`

### PULSE-003 - Schema posts + targets + statuses

#### But

Ajouter les objets persistants qui decrivent un post Pulse et ses cibles.

#### Etat

- `fait`

#### Livraison constatee

- migrations additives `social_posts` et `social_post_targets` en place
- model `SocialPost` ajoute
- model `SocialPostTarget` ajoute
- relations:
  - `User -> socialPosts`
  - `SocialPost -> targets`
  - `SocialPostTarget -> socialAccountConnection`
- statuts globaux et par target cadres
- test dedie `SocialPostsSchemaTest` ajoute et vert

#### Livrables

- migration additive `social_posts`
- migration additive `social_post_targets`
- model `SocialPost`
- model `SocialPostTarget`
- relations vers `SocialAccountConnection`
- statuts globaux et par target

#### Champs recommandes

- `user_id`
- `created_by_user_id`
- `updated_by_user_id`
- `source_type`
- `source_id`
- `content_payload`
- `media_payload`
- `link_url`
- `status`
- `scheduled_for`
- `published_at`
- `failed_at`
- `failure_reason`

#### Statuts recommandes

- post global:
  - `draft`
  - `scheduled`
  - `publishing`
  - `published`
  - `partial_failed`
  - `failed`
- target:
  - `pending`
  - `scheduled`
  - `publishing`
  - `published`
  - `failed`
  - `canceled`

#### Fichiers probables

- nouveaux fichiers dans `database/migrations/`
- `app/Models/SocialPost.php`
- `app/Models/SocialPostTarget.php`
- `tests/Feature/SocialPostsSchemaTest.php`

#### Acceptance criteria

1. un post peut cibler plusieurs comptes
2. le systeme distingue statut global et statut par target
3. le schema reste additif et lisible

#### Verification realisee

```powershell
php artisan test tests/Feature/SocialPostsSchemaTest.php
```

### PULSE-004 - Composeur social simple

#### But

Donner une premiere UI propre pour creer un brouillon Pulse.

#### Etat

- `fait`

#### Livraison constatee

- page `Social/Index` ajoutee comme point d entree du module
- page `Social/Composer` ajoutee
- composant `SocialPostComposer` ajoute
- draft backend:
  - `social.index`
  - `social.composer`
  - `social.posts.store`
  - `social.posts.update`
- selection multi-comptes connectes active
- support:
  - texte
  - image via URL
  - lien
  - planification simple
- preview immediate ajoutee
- acces:
  - owner peut gerer
  - team member `social.manage / social.publish` peut gerer
  - team member `social.view` reste en lecture seule
- test dedie `SocialComposerFeatureTest` ajoute et vert

#### Livrables

- page ou workspace `Social/Index`
- vue `Comptes`
- vue `Composer`
- formulaire `texte + image + lien + planification`
- selection multi-comptes
- preview simple

#### Fichiers probables

- `resources/js/Pages/Social/Index.vue`
- `resources/js/Pages/Social/Accounts.vue`
- `resources/js/Pages/Social/Composer.vue`
- composants partages dans `resources/js/Pages/Social/Components/`
- controller ou endpoint de draft

#### Notes d'implementation

- rester simple
- zero jargon tokens / API
- ne pas melanger publication reelle et sauvegarde de draft

#### Acceptance criteria

1. un utilisateur peut creer et sauver un brouillon
2. il peut selectionner un ou plusieurs comptes
3. l'UI reste legere et comprehensible

#### Verification realisee

```powershell
php artisan test tests/Feature/SocialComposerFeatureTest.php
npm run build
```

## 9. Sprint 3 - Publication et historique

Objectif:

- rendre Pulse utilisable de bout en bout pour publier et suivre

Tickets:

- `PULSE-005`
- `PULSE-006`

### PULSE-005 - Publication immediate et planifiee

#### But

Executer la publication reelle vers chaque compte cible.

#### Etat

- `fait`

#### Livraison constatee

- service `SocialPublishingService` ajoute
- job `PublishSocialPostTargetJob` ajoute avec une execution par target
- endpoints:
  - `social.posts.publish`
  - `social.posts.schedule`
- publication immediate queuee par target
- planification simple queuee avec delai par target
- calcul du statut global du post ajoute:
  - `publishing`
  - `published`
  - `partial_failed`
  - `failed`
- erreurs de target lisibles remontees dans `failure_reason`
- permission `social.publish` appliquee a la publication et a la planification
- composeur Pulse mis a jour avec actions `publier maintenant` et `programmer`
- test dedie `SocialPublishingFeatureTest` ajoute et vert

#### Livrables

- service d'orchestration de publication
- job par target
- publication immediate
- planification simple
- calcul du statut global

#### Notes d'implementation

- une job par target
- ne pas publier inline dans le controller
- tolerer les echecs partiels
- messages d'erreur lisibles

#### Acceptance criteria

1. un owner autorise peut publier maintenant
2. un utilisateur autorise peut planifier
3. les statuts des targets et du post global sont fiables

#### Verification realisee

```powershell
php artisan test tests/Feature/SocialPublishingFeatureTest.php tests/Feature/SocialComposerFeatureTest.php tests/Feature/SocialPostsSchemaTest.php tests/Feature/SocialAccountConnectionsPulseTest.php tests/Feature/SocialAccountConnectionManagementTest.php tests/Feature/SocialAccountConnectionOauthTest.php
npm run build
```

### PULSE-006 - Historique + duplication + repost

#### But

Afficher les posts passes et accelerer la reutilisation.

#### Etat

- `a faire`

#### Livrables

- page `Historique`
- filtres simples
- duplication de draft / published / failed
- repost editable

#### Acceptance criteria

1. l'utilisateur retrouve ses derniers posts
2. il peut dupliquer rapidement un contenu
3. il peut relancer un post publie en mode repost

## 10. Sprint 4 - Productivite editoriale

Objectif:

- faire gagner du temps concret aux utilisateurs

Tickets:

- `PULSE-007`
- `PULSE-008`
- `PULSE-009`

### PULSE-007 - Templates

#### But

Permettre la reutilisation des structures de posts frequentes.

#### Etat

- `a faire`

#### Livrables

- schema `social_post_templates`
- CRUD template
- chargement d'un template dans le composeur

### PULSE-008 - Prefill depuis `promotion / product / service / campaign`

#### But

Ouvrir le composeur avec un contenu deja prepare depuis les modules metier.

#### Etat

- `a faire`

#### Livrables

- action `Publier avec Malikia Pulse` dans:
  - `promotions`
  - `products`
  - `services`
  - `campaigns`
- mapping backend des donnees source -> payload Pulse

#### Regle

- si le module source ou `social` est off, l'action n'apparait pas

### PULSE-009 - Suggestions caption + hashtags + CTA

#### But

Accelerer la redaction sans rendre l'UI confuse.

#### Etat

- `a faire`

#### Livrables

- endpoint de suggestions
- captions de base
- hashtags modifiables
- CTA reutilisables

#### Regle

- suggestions discretes
- jamais obligatoires

## 11. Sprint 5 - Gouvernance optionnelle

Objectif:

- ajouter l'approbation sans freiner les owners

Ticket:

- `PULSE-010`

### PULSE-010 - Approval workflow optionnel

#### But

Permettre un mode validation avant publication pour les equipes.

#### Etat

- `a faire`

#### Livrables

- schema `social_approval_requests` si retenu
- statut `pending_approval`
- actions `approve / reject`
- permission `social.approve`

#### Acceptance criteria

1. l'owner peut continuer a publier sans friction
2. l'equipe peut soumettre pour approbation
3. un approbateur peut valider ou refuser

## 12. Ordre d'execution recommande

Ordre recommande:

1. `PULSE-003`
2. `PULSE-004`
3. `PULSE-005`
4. `PULSE-006`
5. `PULSE-007`
6. `PULSE-008`
7. `PULSE-009`
8. `PULSE-010`

Regle:

- ne pas ouvrir une UI riche avant d'avoir un contrat data stable
- ne pas brancher la publication reelle avant d'avoir `posts + targets`
- ne pas lancer le workflow d'approbation avant un parcours owner stable

## 13. Routine de suivi a chaque session

### Avant de coder

- relire le ticket courant
- confirmer le scope `in / out`
- verifier les dependances
- noter si le ticket est `en cours`

### Pendant

- livrer un bloc coherent a la fois
- garder les tests au plus pres du ticket
- noter tout changement de scope dans cette doc

### Apres

- executer les verifications du ticket
- mettre a jour `section 0`
- ajouter une ligne au journal

## 14. Commandes de verification recommandees

Verification minimale continue:

```powershell
php artisan test tests/Feature/TeamMemberPermissionModulesTest.php
php artisan test tests/Feature/SuperAdminModuleAuthorizationTest.php
php artisan test tests/Unit/CompanyFeatureServiceTest.php
php artisan test tests/Feature/CampaignsMarketingModuleTest.php
php artisan test tests/Feature/SocialAccountConnectionsPulseTest.php tests/Feature/SocialAccountConnectionManagementTest.php tests/Feature/SocialAccountConnectionOauthTest.php
```

Verification a partir du moment ou le frontend Pulse existe:

```powershell
npm run build
php artisan test tests/Feature/SocialComposerFeatureTest.php tests/Feature/SocialPublishingFeatureTest.php
```

Verification de sortie MVP:

```powershell
php artisan test tests/Feature/SocialAccountConnectionsPulseTest.php tests/Feature/SocialAccountConnectionManagementTest.php tests/Feature/SocialPostsSchemaTest.php tests/Feature/SocialComposerFeatureTest.php tests/Feature/SocialPublishingFeatureTest.php tests/Feature/SocialHistoryFeatureTest.php tests/Feature/SocialTemplateFeatureTest.php tests/Feature/SocialPrefillFeatureTest.php tests/Feature/SocialSuggestionsFeatureTest.php
npm run build
npx playwright test tests/e2e/pulse-smoke.spec.js
```

## 15. Journal de suivi

### 2026-04-22

- creation de la user story Pulse dans `docs/MALIKIA_PULSE_USER_STORY_2026-04-22.md`
- `PULSE-001` observe comme livre dans le workspace
- `PULSE-002A` livre:
  - migration `social_account_connections`
  - model `SocialAccountConnection`
  - registry providers Pulse
- `PULSE-002B` livre:
  - service backend de gestion des connexions
  - endpoints `social.accounts.*`
  - access owner + read-only team member
  - tests feature verts
- `PULSE-002C` livre:
  - redirect OAuth + callback reel
  - tokens chiffres et dates d expiration persistés
  - refresh provider manuel
  - callback bloque si le module `social` est coupe
- `PULSE-002D` livre:
  - page Inertia `Social/Accounts`
  - gestion UI des comptes Pulse
  - integration hub croissance + breadcrumbs
- `PULSE-002` cloture:
  - ticket passe `fait`
  - verification executee avec `tests/Feature/SocialAccountConnectionsPulseTest.php`, `tests/Feature/SocialAccountConnectionManagementTest.php`, `tests/Feature/SocialAccountConnectionOauthTest.php`
- `PULSE-003` livre:
  - schema `social_posts`
  - schema `social_post_targets`
  - relations owner / post / target / connection
  - suite `tests/Feature/SocialPostsSchemaTest.php`
- `PULSE-004` livre:
  - workspace Pulse `index / composer`
  - draft backend `social.posts.*`
  - selection multi-comptes et preview simple
  - suite `tests/Feature/SocialComposerFeatureTest.php`
- creation de cette doc de pilotage pour suivre la suite sans derive de scope
