# Malikia Pulse - documentation technique detaillee

Derniere mise a jour: 2026-04-25

## 1. Objet du document

Ce document decrit `Malikia Pulse` comme module technique et fonctionnel de `Malikia Pro`.

Le but est de donner a l equipe:

- une vision claire du role du module
- une description fidele de son fonctionnement actuel
- une vue detaillee des techniques utilisees
- un recapitulatif des flux metier et techniques
- une base de reference pour la maintenance, la roadmap et les futurs refactors

## 2. Resume executif

`Malikia Pulse` est le module de publication sociale multi-comptes de `Malikia Pro`.

Son objectif n est pas seulement de "poster sur un reseau social".
Dans notre plateforme, Pulse sert de couche de diffusion marketing reliee aux objets business deja presents:

- `promotions`
- `products`
- `services`
- `campaigns`

Le module permet a un owner ou a un membre d equipe autorise de:

- connecter des comptes sociaux par tenant
- composer un contenu simple `texte + image + lien`
- cibler un ou plusieurs comptes connectes
- sauvegarder en brouillon
- programmer une publication
- publier immediatement
- reutiliser des templates
- dupliquer ou rouvrir un post publie
- soumettre un post a approbation si le workflow equipe l exige

Le branding visible reste `Malikia Pulse`.
La cle technique du module dans la plateforme est `social`.

## 3. Positionnement produit dans Malikia Pro

`Malikia Pulse` est un module marketing transverse.

Il ne remplace pas:

- les campagnes marketing
- les promotions
- le catalogue produits/services
- le CRM

Il agit plutot comme une couche de diffusion qui consomme du contenu venant de ces modules pour accelerer la communication externe.

En pratique:

- les modules metier produisent de la matiere marketing
- Pulse la transforme en brouillon publiable
- Pulse orchestre la diffusion multi-comptes
- l historique Pulse garde la trace du cycle de publication

## 4. Ce que Pulse est, et ce que Pulse n est pas

### Ce que Pulse est

- un module activable/desactivable par tenant
- un workspace Inertia/Vue dedie
- un systeme de connexion OAuth de comptes sociaux
- un orchestrateur de posts multi-cibles
- un workflow simple d approbation optionnel
- un point d entree de publication depuis plusieurs modules business

### Ce que Pulse n est pas

- un reseau social interne
- un moteur avance d analytics sociales
- un calendrier editorial complet
- un outil de community management avec commentaires/messages inbox
- un module d authentification utilisateur, meme si le repo contient aussi des briques `social auth` a cote

Important:

- le coeur de `Malikia Pulse` repose sur `SocialAccountConnection`, `SocialPost`, `SocialPostTarget`, `SocialPostTemplate` et `SocialApprovalRequest`
- les classes `UserSocialAccount` et `Auth\\SocialAuth*` appartiennent a une logique adjacente d authentification sociale, pas au coeur de Pulse V1

## 5. Stack technique utilisee

Le module reutilise le socle standard du produit.

### Backend

- `PHP`
- `Laravel`
- `Eloquent ORM`
- `Form validation Laravel`
- `Jobs Laravel`
- `HTTP client Laravel`
- `Migrations additives`
- `Feature flags tenant`

### Frontend

- `Vue 3`
- `Inertia.js`
- `Tailwind CSS`
- `vue-i18n`

### Techniques transverses importantes

- `OAuth 2.0 Authorization Code Flow`
- `PKCE` pour `X`
- `encrypted:array` pour stocker les credentials sensibles
- `JSON payloads` pour garder des structures de contenu souples
- `queue par cible` pour publier de maniere resiliente
- `fake publish mode` en local/testing pour eviter de dependre des reseaux reels
- `middleware company.feature:social` pour couper tout le module quand la feature est off

## 6. Activation du module et gouvernance plateforme

`Malikia Pulse` est gouverne comme un vrai module de plateforme.

### Cle technique

- `social`

### Branding visible

- `Malikia Pulse`

### Activation tenant

Le module est branche dans la logique de features tenant:

- `app/Services/CompanyFeatureService.php`
- `app/Services/SuperAdminPlatformSettingsService.php`
- `app/Http/Controllers/SuperAdmin/TenantController.php`

Consequence:

- si `social` est desactive, les routes du workspace Pulse sont masquees ou bloquees
- les actions `Publier avec Malikia Pulse` ne doivent pas apparaitre
- les comptes, posts, templates et approvals ne doivent pas etre exposes

### Permissions equipe

Permissions exposees:

- `social.view`
- `social.manage`
- `social.publish`
- `social.approve`

Elles sont gerees via:

- `app/Http/Controllers/TeamMemberController.php`

## 7. Modele d acces reel

Le module applique une logique d acces orientee `owner` + `team`.

### Owner

L owner du workspace a tous les droits:

- voir le module
- gerer les comptes
- gerer les brouillons
- publier
- approuver

### Team members

La logique actuelle est la suivante:

- `social.view`:
  - acces lecture au workspace
- `social.manage`:
  - creation et edition de brouillons/templates
  - pas de gestion des comptes OAuth
- `social.publish`:
  - peut preparer et soumettre un post pour approbation
- `social.approve`:
  - peut approuver ou rejeter

Nuance importante:

- dans le controleur Pulse, la publication finale equipe est reservee aux utilisateurs qui cumulent `social.publish` et `social.approve`
- un membre avec seulement `social.publish` peut soumettre pour approbation, mais pas lancer la publication finale seul
- la gestion des comptes sociaux reste owner-only

## 8. Architecture fonctionnelle du module

Le module est organise autour de 5 surfaces principales:

- `Overview`
- `Accounts`
- `Composer`
- `Templates`
- `History`

Ces surfaces sont servies par:

- `resources/js/Pages/Social/Index.vue`
- `resources/js/Pages/Social/Accounts.vue`
- `resources/js/Pages/Social/Composer.vue`
- `resources/js/Pages/Social/Templates.vue`
- `resources/js/Pages/Social/History.vue`

Avec les composants UI principaux:

- `SocialWorkspaceHeader.vue`
- `SocialAccountManager.vue`
- `SocialPostComposer.vue`
- `SocialTemplateManager.vue`
- `SocialPostHistory.vue`
- `SocialPlatformLogo.vue`

## 9. Routes et surfaces HTTP

Le module expose des routes web et API.

### Routes web workspace

- `GET /social`
- `GET /social/composer`
- `GET /social/templates`
- `GET /social/history`
- `GET /social/accounts`

### Actions backend

- `POST /social/suggestions`
- `POST /social/posts`
- `PUT /social/posts/{post}`
- `POST /social/posts/{post}/publish`
- `POST /social/posts/{post}/schedule`
- `POST /social/posts/{post}/submit-approval`
- `POST /social/posts/{post}/approve`
- `POST /social/posts/{post}/reject`
- `POST /social/posts/{post}/duplicate`
- `POST /social/posts/{post}/repost`
- `POST /social/templates`
- `PUT /social/templates/{template}`
- `DELETE /social/templates/{template}`

### Gestion des comptes sociaux

- `POST /social/accounts`
- `PUT /social/accounts/{connection}`
- `POST /social/accounts/{connection}/authorize`
- `POST /social/accounts/{connection}/refresh`
- `POST /social/accounts/{connection}/test`
- `POST /social/accounts/{connection}/disconnect`
- `DELETE /social/accounts/{connection}`
- `GET /integrations/social/{platform}/callback`

### Guard global

Les routes sont protegees par:

- `company.feature:social`

Cela coupe le module entier au niveau route si la feature est desactivee.

## 10. Architecture applicative

L architecture Pulse suit une separation simple et saine:

### 1. Controllers

Ils servent:

- les pages Inertia
- les endpoints JSON
- la validation d entree
- la resolution des droits

Classes principales:

- `SocialPostController`
- `SocialAccountConnectionController`

### 2. Services metier

La logique metier est centralisee dans des services dedies:

- `SocialAccountConnectionService`
- `SocialPostService`
- `SocialPublishingService`
- `SocialApprovalService`
- `SocialTemplateService`
- `SocialPrefillService`
- `SocialSuggestionService`
- `SocialMediaAssetService`
- `SocialProviderRegistry`

### 3. Providers

Le module utilise un pattern `provider adapter` pour isoler les differences entre plateformes.

Contrat central:

- `PlatformPublisherInterface`

Abstractions:

- `AbstractPlatformPublisher`
- `AbstractOauthPlatformPublisher`

Providers concrets:

- `FacebookPagePlatformPublisher`
- `InstagramBusinessPlatformPublisher`
- `LinkedInPagePlatformPublisher`
- `XProfilePlatformPublisher`

### 4. Persistance

La persistance repose sur:

- migrations additives
- modeles Eloquent
- payloads JSON souples
- relations explicites entre post global et cibles de publication

### 5. Queue

La publication reelle est decouplee dans des jobs:

- `PublishSocialPostTargetJob`

Chaque cible sociale est publiee independamment.

## 11. Modele de donnees

Le coeur Pulse s appuie sur 5 tables principales.

### 11.1 `social_account_connections`

But:

- stocker les comptes sociaux connectes pour un tenant

Champs importants:

- `user_id`
- `platform`
- `label`
- `display_name`
- `account_handle`
- `external_account_id`
- `auth_method`
- `credentials`
- `permissions`
- `status`
- `is_active`
- `connected_at`
- `last_synced_at`
- `token_expires_at`
- `last_error`
- `metadata`

Caracteristiques techniques:

- `credentials` est caste en `encrypted:array`
- `permissions` est un `json`
- `metadata` est un `json`
- contrainte d unicite sur `user_id + platform + external_account_id`

Statuts supportes:

- `draft`
- `pending`
- `connected`
- `error`
- `reconnect_required`
- `expired`
- `disconnected`

### 11.2 `social_posts`

But:

- stocker le post global, independamment des cibles reseaux

Champs importants:

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
- `metadata`

Statuts supportes:

- `draft`
- `scheduled`
- `pending_approval`
- `publishing`
- `published`
- `partial_failed`
- `failed`

### 11.3 `social_post_targets`

But:

- decrire l etat d un meme post sur chaque compte cible

Champs importants:

- `social_post_id`
- `social_account_connection_id`
- `status`
- `published_at`
- `failed_at`
- `failure_reason`
- `metadata`

Statuts supportes:

- `pending`
- `scheduled`
- `publishing`
- `published`
- `failed`
- `canceled`

Decision importante:

- `social_account_connection_id` est nullable
- si une connexion est supprimee, l historique du target reste exploitable grace aux snapshots ranges dans `metadata`

### 11.4 `social_post_templates`

But:

- memoriser des structures reutilisables de posts

Champs importants:

- `user_id`
- `name`
- `content_payload`
- `media_payload`
- `link_url`
- `metadata`

Le template garde aussi:

- les ids de connexions cible selectionnees
- des snapshots des comptes cibles
- le CTA du lien

### 11.5 `social_approval_requests`

But:

- porter l historique des demandes d approbation

Champs importants:

- `social_post_id`
- `requested_by_user_id`
- `resolved_by_user_id`
- `status`
- `note`
- `requested_at`
- `approved_at`
- `rejected_at`
- `metadata`

Statuts supportes:

- `pending`
- `approved`
- `rejected`

## 12. Pourquoi des payloads JSON ont ete choisis

Pulse manipule du contenu semi-structure:

- texte
- image
- lien
- CTA
- snapshots de comptes
- metadata d approbation
- metadata de publication
- status summaries

Le choix de `content_payload`, `media_payload` et `metadata` permet:

- de rester souple sans exploser le schema en colonnes peu utilisees
- d ajouter des metadonnees provider-specifiques sans migration systematique
- de garder des snapshots resilients meme si un compte est renomme ou supprime
- de faciliter duplication, repost et templates

Le contrepoint est connu:

- certaines recherches et analytics SQL sont moins directes

Pour le perimetre V1, ce compromis est bon.

## 13. Providers supportes actuellement

Pulse supporte actuellement 4 familles de comptes:

| Cle | Label | Type cible | OAuth | Particularites |
| --- | --- | --- | --- | --- |
| `facebook` | `Facebook Pages` | `page` | oui | refresh type Facebook Graph |
| `instagram` | `Instagram Business` | `business_account` | oui | refresh long-lived token |
| `linkedin` | `LinkedIn Pages` | `organization` | oui | organisation pages |
| `x` | `X Profiles` | `profile` | oui | utilise `PKCE` |

Pattern retenu:

- un provider implemente `PlatformPublisherInterface`
- le `SocialProviderRegistry` enregistre les implementations
- l orchestration centrale ne depend pas des details de Facebook, Instagram, LinkedIn ou X

Avantage:

- ajouter un nouveau provider ne force pas a reecrire le coeur Pulse

## 14. Flow detaille de connexion d un compte social

Le flow de connexion est le suivant.

### Etape 1 - Creation d un brouillon de connexion

L owner cree un compte social local:

- plateforme
- label
- infos de presentation

Le systeme cree un `SocialAccountConnection` en `draft`.

### Etape 2 - Debut OAuth

Le service:

- selectionne le provider
- genere un `state`
- stocke `oauth_state`
- stocke `oauth_state_expires_at`
- positionne le statut a `pending`
- renvoie une `redirect_url`

Pour `X`, un `code_verifier` PKCE est aussi stocke en metadata.

### Etape 3 - Callback provider

Le callback:

- verifie le `state`
- verifie l expiration du flow
- verifie que le tenant a toujours la feature `social`
- laisse le provider echanger le `code` contre un `access_token`

### Etape 4 - Persistence securisee

Le resultat est normalise puis stocke:

- `credentials`
- `permissions`
- `status = connected`
- `token_expires_at`
- `metadata.oauth_ready = true`

Les credentials sont chiffres en base via le cast Eloquent `encrypted:array`.

### Etape 5 - Test et refresh

L owner peut:

- tester la connexion
- refresh les tokens
- deconnecter
- supprimer

Le systeme met a jour:

- `last_synced_at`
- `last_error`
- `status`
- `token_expires_at`

## 15. Flow detaille de composition d un post

### 15.1 Entree dans le composeur

Le composeur peut etre ouvert:

- directement depuis le module Pulse
- depuis `promotion`
- depuis `product`
- depuis `service`
- depuis `campaign`

Ces flux passent par `source_type` et `source_id`.

### 15.2 Prefill metier

`SocialPrefillService`:

- valide le type de source
- verifie que la feature source est active
- charge l objet source dans le tenant
- construit un brouillon de texte/image/lien

Sources autorisees actuellement:

- `promotion`
- `product`
- `service`
- `campaign`

### 15.3 Suggestions intelligentes

`SocialSuggestionService` produit:

- `captions`
- `hashtags`
- `ctas`

Le systeme tient compte de:

- la langue
- le type de source
- le secteur de l entreprise
- le type d entreprise `products` ou `services`
- la presence ou non d un lien

Important:

- ce n est pas un moteur LLM externe dans l etat actuel
- les suggestions sont calculees par logique applicative et copy locale FR/EN/ES
- cela garantit rapidite, faible cout et comportement deterministe

### 15.4 Upload media

L image peut venir:

- d une URL
- d un upload utilisateur

`SocialMediaAssetService`:

- stocke les uploads dans `social/{context}/{owner_id}`
- utilise `FileHandler::storeFile`
- renvoie un payload normalise de media

## 16. Flow detaille de sauvegarde d un brouillon

Lors de la sauvegarde:

- les comptes cibles sont valides
- le contenu est valide
- la source est valide si presente
- `SocialPost` est cree ou mis a jour
- les `SocialPostTarget` sont recrees depuis la selection

Regles importantes:

- il faut au moins un compte social connecte cible
- il faut au moins un des elements: `texte`, `image`, `lien`
- un post planifie prend `status = scheduled`
- un post non planifie prend `status = draft`

Chaque target garde un snapshot en metadata:

- label
- provider_label
- platform
- display_name
- account_handle
- target_type

## 17. Flow detaille de publication

La publication suit un modele `global post + one job per target`.

### 17.1 Publication immediate ou planifiee

`SocialPublishingService`:

- verifie la propriete du post
- bloque les republis non valides
- valide la presence des targets
- valide la date future si planification
- positionne les targets en `pending` ou `scheduled`
- positionne le post en `publishing` ou `scheduled`

### 17.2 Dispatch des jobs

Pour chaque target valide:

- un `PublishSocialPostTargetJob` est dispatch
- la queue ciblee est `social-publish`

Si la publication est planifiee:

- le job est delaye a `scheduled_for`

### 17.3 Publication par cible

Chaque job:

- recharge la target
- recharge le post et la connexion
- verifie que la connexion est toujours active
- appelle le provider correspondant
- marque la target en succes ou en echec

### 17.4 Recalcul du statut global

Apres chaque traitement, `refreshPostStatus` recalcule le statut global du post selon l etat des targets:

- tout publie -> `published`
- tout echoue -> `failed`
- mix succes/echec -> `partial_failed`
- encore des scheduled -> `scheduled`
- encore des envois en cours -> `publishing`

C est un point cle du design:

- le statut global n est pas "devine"
- il est derive de la realite target par target

## 18. Flow detaille d approbation

L approbation est geree via `SocialApprovalService`.

### Soumission

Un utilisateur autorise peut:

- soumettre un brouillon ou un post planifie
- ajouter une note

Effets:

- creation d un `SocialApprovalRequest`
- passage du post en `pending_approval`

### Approbation

Un utilisateur avec `social.approve` peut:

- approuver
- conserver la note
- relancer la vraie publication

Effets:

- la demande passe en `approved`
- le post est soit publie maintenant, soit reprogramme selon le `requested_mode`

### Rejet

En cas de rejet:

- la demande passe en `rejected`
- le post revient en `draft` ou `scheduled`

Design important:

- l approbation ne remplace pas le moteur de publication
- elle l encapsule

## 19. Templates, duplication et repost

### Templates

Les templates servent a memoriser:

- un texte
- une image
- un lien
- un CTA
- une selection de comptes cibles

Ils sont stockes dans `social_post_templates`.

### Duplication

La duplication cree un nouveau brouillon editable a partir d un post existant.

### Repost

Le repost ne s applique qu a un post deja `published`.

### Choix technique important

Lorsqu un ancien post reference des comptes plus disponibles:

- le brouillon duplique garde l information utile
- les targets manquantes sont comptees
- l utilisateur est invite a reconnecter ou reselectionner

Cela evite de perdre le contexte marketing.

## 20. Integrations avec les autres modules

Pulse est branche sur plusieurs modules de Malikia Pro.

### Modules sources actuellement relies

- `Promotions`
- `Products`
- `Services`
- `Campaigns`

### Mecanisme

Ces modules exposent une action du type:

- `Publier avec Malikia Pulse`

Cette action ouvre:

- `route('social.composer', { source_type, source_id })`

Le composeur recupere alors le contexte via `SocialPrefillService`.

### Regles de garde

Une action Pulse ne doit pas apparaitre si:

- la feature `social` est off
- la feature du module source est off
- l utilisateur n a pas les droits minimums pour ouvrir le composeur

## 21. UX et structure frontend

Le workspace Pulse cherche un compromis entre simplicite et profondeur metier.

### Surfaces frontend

- `Social/Index` pour la vue d ensemble
- `Social/Accounts` pour les comptes connectes
- `Social/Composer` pour les brouillons, suggestions, publication et templates
- `Social/Templates` pour la bibliotheque reutilisable
- `Social/History` pour l historique filtre

### Approche UX

- peu d ecrans
- peu de jargon technique
- lecture seule possible pour l equipe
- actions claires pour owner et roles autorises
- messages de statut comprehensibles

### Internationalisation

Le module est traduit via:

- `resources/js/i18n/modules/fr/social.json`
- `resources/js/i18n/modules/en/social.json`
- `resources/js/i18n/modules/es/social.json`

## 22. Securite et robustesse

Le module applique plusieurs garde-fous importants.

### 22.1 Isolation tenant

Toutes les donnees Pulse sont scopees par `user_id` owner.

### 22.2 Secrets OAuth

- les credentials sont chiffres en base
- le callback verifie un `state`
- le `state` expire
- `X` utilise un verifier `PKCE`

### 22.3 Feature gating

- les routes sont coupees par `company.feature:social`
- les points d entree UI sont conditionnes par les features

### 22.4 Publication resiliente

- une cible en echec n empeche pas les autres d etre publiees
- le statut global peut devenir `partial_failed`

### 22.5 Historique resilient

- les targets gardent des snapshots metadata
- un compte supprime ne detruit pas la lisibilite des anciens posts

### 22.6 Mode local/testing

Par defaut, le publish provider peut fonctionner en `fake mode` en local/test.

Avantages:

- pas besoin de vrais comptes pour tester le flux complet
- pas de publication accidentelle pendant le developpement

## 23. Strategie de tests

Pulse est plutot bien couvert par des suites dediees.

Tests feature principaux:

- `SocialAccountConnectionsPulseTest.php`
- `SocialAccountConnectionManagementTest.php`
- `SocialAccountConnectionOauthTest.php`
- `SocialPostsSchemaTest.php`
- `SocialComposerFeatureTest.php`
- `SocialPublishingFeatureTest.php`
- `SocialHistoryFeatureTest.php`
- `SocialTemplateFeatureTest.php`
- `SocialPrefillFeatureTest.php`
- `SocialSuggestionsFeatureTest.php`
- `SocialApprovalWorkflowTest.php`

Ce que cette strategie couvre bien:

- schema et persistance
- droits et feature flags
- OAuth/callback
- brouillons
- publication
- historique
- templates
- prefill
- suggestions
- workflow d approbation

Ce qu il serait encore pertinent d ajouter:

- un vrai smoke e2e navigateur bout en bout

## 24. Decisions de conception importantes

### 24.1 Cle technique `social`, branding `Malikia Pulse`

Bonne separation entre:

- nom de produit visible
- nom de feature court et stable

### 24.2 Un post global + des targets par compte

C est le bon modele pour:

- suivre un statut global
- garder un detail reel par reseau/cible
- permettre les echec partiels

### 24.3 JSON payloads au lieu d un schema trop rigide

Adaptation utile a un domaine social qui varie vite.

### 24.4 Provider registry + interface

Excellent choix pour:

- l extensibilite
- les tests
- la maintenance

### 24.5 Job par cible

Tres bon choix pour:

- la scalabilite
- la tolerance aux pannes partielles
- la planification

## 25. Limites actuelles connues

Le module est solide pour un MVP/V1, mais il y a encore des limites normales.

### Cote produit

- pas d analytics avancees par plateforme
- pas de calendrier editorial riche
- pas de variantes de copy par reseau
- pas de commentaires/inbox sociaux

### Cote technique

- beaucoup d informations sont dans `metadata`, donc certaines analyses SQL seront moins directes
- la logique d approbation repose surtout sur les permissions, pas encore sur une configuration metier plus fine par tenant
- les providers de publication reelle dependent de configurations externes `services.social.*`

## 26. Roadmap technique recommandee

Les prochaines evolutions coherentes seraient:

### Court terme

- smoke e2e navigateur Pulse
- petits raffinements UX du composeur
- meilleure visualisation des erreurs par target

### Moyen terme

- analytics simples par post et par plateforme
- calendrier editorial
- filtres plus riches dans l historique

### Plus long terme

- automations evenementielles depuis d autres modules
- variantes de copy par reseau
- approbation configurable plus fine

## 27. Conclusion

`Malikia Pulse` est aujourd hui un vrai module transverse de `Malikia Pro`, pas une simple page de publication isolee.

Sa valeur technique tient a 6 choix structurants:

1. une cle module stable `social` et un branding clair `Malikia Pulse`
2. un vrai controle par feature flag et permissions equipe
3. une architecture provider-based propre
4. un modele `post global + targets`
5. une publication asynchrone par job et par cible
6. une integration directe avec les objets business du produit

Dans notre contexte, c est une base saine, evolutive et deja bien industrialisee pour un V1.
