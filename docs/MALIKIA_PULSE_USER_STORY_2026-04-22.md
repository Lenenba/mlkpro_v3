///# Malikia Pulse - analyse produit et user story

Derniere mise a jour: 2026-04-22

## 1. Resume

`Malikia Pulse` est un module de publication reseaux sociaux pour `Malikia Pro`.

Le besoin n'est pas de creer un simple ecran "poster sur Facebook".
Le bon positionnement produit, dans notre contexte, est:

- un module activable par tenant
- branche sur les objets deja presents `promotions / products / services / campaigns`
- utilisable par owner et equipe selon permissions
- simple pour une petite entreprise
- extensible pour approvals, analytics et automations plus tard

## 2. Pourquoi ce module colle a notre contexte

Aujourd'hui, `Malikia Pro` a deja plusieurs briques compatibles avec ce besoin:

- gestion de modules via `CompanyFeatureService`
- permissions equipe par module via `TeamMemberController`
- logique de campagnes deja en place
- formulaires create/edit/show pour `promotions`, `products`, `services`, `campaigns`
- scheduler et jobs Laravel deja utilises dans d'autres modules

Conclusion:

- `Malikia Pulse` doit etre pense comme un nouveau module CRM/marketing
- il ne doit jamais s'afficher si le module n'est pas actif
- il doit reutiliser les patterns deja presents pour `campaigns`

## 3. Positionnement recommande

Nom produit visible:

- `Malikia Pulse`

Cle technique recommandee:

- `social`

Pourquoi `social`:

- plus court et plus coherent avec `campaigns`, `products`, `services`
- plus simple pour `hasFeature('social')`
- le branding reste `Malikia Pulse` dans l'UI

Permissions recommandees:

- `social.view`
- `social.manage`
- `social.publish`
- `social.approve`

## 4. User story coeur

### Story principale

En tant que proprietaire d'entreprise ou membre d'equipe autorise,
je veux connecter mes comptes reseaux sociaux et publier ou planifier un post vers un ou plusieurs comptes directement depuis `Malikia Pro`,
afin de promouvoir rapidement mes produits, promotions, services et campagnes sans quitter la plateforme.

### Valeur business

- reduire le temps entre creation d'une offre et sa diffusion
- centraliser la publication marketing dans l'outil deja utilise au quotidien
- augmenter la visibilite des produits, promotions et campagnes
- rendre l'equipe plus autonome sans multiplier les outils externes

## 5. Sous-stories prioritaires

### US-01 - Connexion des comptes

En tant qu'utilisateur autorise,
je veux connecter plusieurs comptes sociaux a mon espace,
afin de pouvoir publier sur tous mes reseaux depuis une seule interface.

Acceptance criteria:

1. je peux connecter plusieurs comptes d'une meme plateforme ou de plateformes differentes
2. je vois le statut de connexion de chaque compte
3. je peux renommer un compte connecte pour le reconnaitre facilement
4. je peux desactiver ou supprimer un compte connecte
5. si le module `social` n'est pas actif, aucune UI de connexion n'est exposee

### US-02 - Publication multi-comptes

En tant qu'utilisateur autorise,
je veux composer un post et choisir un ou plusieurs comptes cibles,
afin de publier le meme contenu sur plusieurs reseaux en une seule action.

Acceptance criteria:

1. un post supporte `texte`, `image`, `lien` et `date de planification`
2. je peux choisir un ou plusieurs comptes connectes
3. le systeme cree un statut global et un statut par compte cible
4. je peux enregistrer en brouillon, planifier ou publier maintenant
5. l'historique indique `draft / scheduled / published / failed`

### US-03 - Publication directe depuis les modules metier

En tant qu'utilisateur autorise,
je veux trouver une action `Publier avec Malikia Pulse` dans `promotions`, `products`, `services` et `campaigns`,
afin de generer un post a partir du contenu deja saisi.

Acceptance criteria:

1. depuis `promotion create/edit`, je peux ouvrir le composeur social avec contenu pre-rempli
2. depuis `product create/edit`, je peux ouvrir le composeur social avec contenu pre-rempli
3. depuis `service create/edit`, je peux ouvrir le composeur social avec contenu pre-rempli
4. depuis `campaign create/edit/show`, je peux ouvrir le composeur social avec contenu pre-rempli
5. si le module source ou `social` n'est pas actif, l'action n'apparait pas

### US-04 - Suggestions intelligentes

En tant qu'utilisateur autorise,
je veux obtenir une legende auto-generee, des hashtags et des CTA proposes,
afin de publier plus vite sans repartir de zero.

Acceptance criteria:

1. une promotion peut proposer une legende basee sur le titre, l'offre, la date et le lien
2. un produit peut proposer une legende basee sur le nom, le prix, le benefice client et le lien
3. un service peut proposer une legende basee sur le nom, le resultat attendu et le lien
4. le systeme propose une liste courte de hashtags modifiables
5. le systeme propose 2 a 5 CTA reutilisables

### US-05 - Templates, duplication et repost

En tant qu'utilisateur autorise,
je veux reutiliser un template, dupliquer un ancien post ou republier un contenu,
afin de gagner du temps sur les communications recurrentes.

Acceptance criteria:

1. je peux sauvegarder un post comme template
2. je peux dupliquer un brouillon, un post publie ou un post echoue
3. je peux relancer un post publie en mode `repost`
4. les medias et cibles sont recuperes de facon editable

### US-06 - Workflow d'approbation optionnel

En tant que proprietaire ou manager,
je veux activer une validation avant publication pour l'equipe,
afin de controler la communication sans ralentir les owners.

Acceptance criteria:

1. l'owner peut publier sans approbation s'il a `social.publish`
2. un membre equipe sans droit de publication finale peut soumettre `for approval`
3. un utilisateur avec `social.approve` peut approuver ou refuser
4. le statut du post suit `draft -> pending_approval -> scheduled/published/failed`
5. si le workflow n'est pas active, il n'ajoute aucune friction

## 6. UX cible

Le module doit rester tres simple.

Principes UX:

- un ecran principal avec 4 vues maximum: `Comptes`, `Composer`, `Programmation`, `Historique`
- un composeur clair avec preview immediate
- zero jargon technique sur les tokens ou API
- choix multi-comptes avec chips simples
- suggestions IA discretes, jamais envahissantes
- integration directe dans les ecrans `promotion / product / service / campaign`

## 7. Cadrage architecture recommande

## Backend

Entites recommandees:

- `SocialAccountConnection`
- `SocialPost`
- `SocialPostTarget`
- `SocialPostTemplate`
- `SocialApprovalRequest` si workflow retenu en V1

Champs clefs:

- `account_id` ou owner scope
- `platform`
- `external_account_id`
- `display_name`
- `status`
- `last_synced_at`
- `source_type` et `source_id` pour lier `promotion/product/service/campaign`
- `content_payload`
- `media_payload`
- `scheduled_for`
- `published_at`
- `failed_at`
- `failure_reason`

Pattern recommande:

- une couche `PlatformPublisherInterface`
- un adaptateur par reseau social
- une job de publication par cible
- une orchestration centrale pour gerer le statut global

## Frontend

Points d'entree recommandes:

- menu lateral `Malikia Pulse` visible si `hasFeature('social')`
- carte/action `Publier avec Malikia Pulse` dans:
  - `promotions`
  - `products`
  - `services`
  - `campaigns`

Composants cibles:

- `Social/Index`
- `Social/Composer`
- `Social/History`
- `Social/Accounts`
- `Social/Templates`

## Permissions et modules

Points non negociables:

1. ajouter `social` dans `CompanyFeatureService`
2. exposer le module dans les reglages super admin/plan
3. filtrer l'UI avec `hasFeature('social')`
4. ajouter les permissions `social.*` dans `TeamMemberController`
5. ne jamais afficher une action de publication si le module source ou `social` est off

## 8. Portee MVP recommandee

### MVP V1

- connexion multi-comptes
- publication texte + image + lien
- publication immediate
- planification simple
- historique
- templates
- duplication/repost
- prefill depuis `promotion / product / service / campaign`
- suggestions de caption/hashtags/CTA

### V1.1

- workflow d'approbation equipe
- filtres avances dans l'historique
- indicateurs simples par post

### V2

- analytics par plateforme
- automations basees sur evenements Malikia Pro
- variantes de copy par plateforme
- calendrier editorial avance

## 9. Risques produit a cadrer tot

- differences de capacites par plateforme
- gestion des medias et formats
- expiration/revocation des connexions
- messages d'erreur comprehensibles pour PME
- validation des permissions equipe avant envoi

## 10. Recommendation finale

Pour `Malikia Pro`, la bonne lecture n'est pas "un autre module marketing".
La bonne lecture est:

- `Malikia Pulse` = couche de diffusion sociale reliee aux objets business deja crees dans le produit

La meilleure user story a retenir pour lancer le chantier est donc:

> En tant que proprietaire d'entreprise ou membre d'equipe autorise, je veux connecter mes comptes sociaux et publier ou planifier un post vers un ou plusieurs reseaux directement depuis les fiches `promotion`, `product`, `service` et `campaign`, afin de diffuser mes offres rapidement sans quitter `Malikia Pro`.

## 11. Proposition de decoupage backlog

- `PULSE-001` module flag `social` + permissions `social.*`
- `PULSE-002` schema comptes connectes + providers
- `PULSE-003` schema posts + targets + statuses
- `PULSE-004` composeur social simple
- `PULSE-005` publication immediate et planifiee
- `PULSE-006` historique + duplication + repost
- `PULSE-007` templates
- `PULSE-008` prefill depuis `promotion/product/service/campaign`
- `PULSE-009` suggestions caption + hashtags + CTA
- `PULSE-010` approval workflow optionnel
