# Malikia Pro RBAC - User story de migration roles et permissions

Derniere mise a jour: 2026-05-18

## 1. But du document

Ce document cadre la migration progressive de la gestion des acces Malikia Pro
vers un modele RBAC.

RBAC signifie Role-Based Access Control:

- un membre d equipe recoit un role
- un role contient des permissions
- les permissions controlent les pages, actions, donnees et elements visibles
- les fonctionnalites actives du compte restent verifiees separement

L objectif est de remplacer progressivement la logique actuelle de permissions
attachees directement aux membres par une source de verite plus claire:

`role -> permissions -> acces`.

## 2. Contexte actuel

Malikia Pro possede deja une base partielle de permissions:

- `users.role_id` pointe vers la table `roles` globale de l application
- `team_members.role` stocke un role texte simple comme `admin` ou `member`
- `team_members.permissions` stocke une liste JSON de permissions directes
- `TeamMember::hasPermission()` est deja utilise dans plusieurs policies
- plusieurs policies protegent deja des actions sensibles
- le sidebar et le workspace hub lisent les permissions partagees par Inertia

Cette base est utile, mais elle n est pas suffisante pour une vraie
configuration entreprise.

Probleme principal:

- les permissions sont actuellement attachees au membre
- les roles ne sont pas configurables par entreprise
- les acces UI et backend ne sont pas encore centralises autour d un role metier
- la navigation peut encore etre influencee trop directement par les modules

## 3. Decision d architecture

La table `roles` actuelle doit rester dediee aux roles globaux des utilisateurs:

- owner
- employee
- client
- admin
- superadmin

Elle ne doit pas etre reutilisee directement pour les roles metier d entreprise.

La migration RBAC doit ajouter une couche dediee:

- `company_roles`
- `permissions`
- `company_role_permission`
- `team_members.company_role_id`

Le champ `team_members.permissions` reste temporairement supporte pour la
compatibilite pendant la migration.

## 4. User story principale

En tant que proprietaire d entreprise,
je veux configurer des roles et permissions pour mon equipe,
afin de controler precisement ce que chaque membre peut voir, faire et gerer
dans Malikia Pro.

## 5. Objectifs produit

- Fournir des roles de base preconfigures.
- Permettre de creer des roles personnalises.
- Assigner un role principal a chaque membre d equipe.
- Filtrer la navigation selon les permissions du role.
- Proteger les routes et actions backend avec les memes permissions.
- Garder les fonctionnalites actives du compte comme contrainte supplementaire.
- Preparer une base solide pour reservations, presence, chaises, ventes,
  clients, equipe, finances, campagnes et rapports.

## 6. Non-objectifs de la phase 1

La phase 1 ne doit pas:

- migrer toutes les policies de la plateforme en une seule PR
- supprimer immediatement `team_members.permissions`
- changer le role global des utilisateurs dans `users.role_id`
- casser les membres existants
- rendre inactive une permission deja utilisee en production
- construire toute l interface avancee avant que le modele de donnees soit stable

## 7. Modele de donnees cible

### 7.1 `company_roles`

Champs attendus:

- `id`
- `company_id`, nullable pour les roles systeme globaux
- `name`
- `slug`
- `description`
- `is_system`
- `is_default`
- `is_editable`
- `is_deletable`
- `is_active`
- `created_at`
- `updated_at`

Regles:

- un role systeme peut etre fourni par defaut
- un role personnalise appartient a une entreprise
- le role Owner est protege
- un role utilise par un membre ne peut pas etre supprime sans gestion explicite

### 7.2 `permissions`

Champs attendus:

- `id`
- `group`
- `name`
- `slug`
- `description`
- `created_at`
- `updated_at`

Regles:

- `slug` est la valeur technique utilisee dans le code
- `group` sert a organiser l interface
- les permissions doivent etre seedables et versionnees

### 7.3 `company_role_permission`

Champs attendus:

- `company_role_id`
- `permission_id`
- `created_at`
- `updated_at`

Regles:

- une permission peut appartenir a plusieurs roles
- un role contient plusieurs permissions

### 7.4 `team_members`

Champ a ajouter:

- `company_role_id`, nullable au depart

Regles:

- un membre a un role principal
- le role principal devient la source de permission par defaut
- `team_members.permissions` reste lu comme fallback temporaire

## 8. Roles de base

### 8.1 Owner

Role systeme non supprimable.

Permissions:

- acces complet a toutes les fonctionnalites actives de l entreprise
- gestion de l entreprise
- gestion des membres
- gestion des roles et permissions
- gestion des parametres
- gestion de la facturation
- acces aux rapports
- acces aux ventes, reservations, clients, produits, services et finances

Implementation:

- le proprietaire du compte garde un bypass via `isAccountOwner()`
- le role Owner ne doit pas etre assigne comme role editable a un membre non owner

### 8.2 Manager

Role modifiable.

Permissions typiques:

- gestion equipe
- gestion planning
- gestion reservations
- gestion presence equipe
- consultation rapports operationnels
- acces clients

### 8.3 Coiffeur

Role modifiable.

Permissions typiques:

- voir ses reservations
- gerer son check-in
- gerer son statut de presence
- voir ses clients associes
- voir services
- voir son planning
- activer sa chaise si elle lui est assignee

### 8.4 Vendeur

Role modifiable.

Permissions typiques:

- voir produits
- creer ventes
- gerer caisse selon autorisation
- voir clients liees aux ventes

### 8.5 Receptionniste

Role modifiable.

Permissions typiques:

- voir clients
- creer clients
- gerer reservations
- gerer file d attente
- voir presence sans gestion complete equipe

### 8.6 Comptable

Role modifiable.

Permissions typiques:

- voir factures
- creer factures
- voir depenses
- approuver depenses selon configuration
- voir rapports financiers

### 8.7 Employe standard

Role modifiable.

Permissions typiques:

- voir son planning
- voir ses taches
- gerer sa propre presence

## 9. Catalogue initial de permissions

### Clients

- `view_clients`
- `create_clients`
- `update_clients`
- `delete_clients`
- `export_clients`
- `view_client_notes`
- `manage_client_notes`

### Reservations

- `view_reservations`
- `create_reservations`
- `update_reservations`
- `cancel_reservations`
- `manage_reservation_calendar`
- `assign_reservations`
- `view_all_reservations`
- `view_own_reservations`

### Services

- `view_services`
- `create_services`
- `update_services`
- `delete_services`
- `manage_service_categories`

### Produits

- `view_products`
- `create_products`
- `update_products`
- `delete_products`
- `manage_inventory`
- `adjust_stock`

### Ventes

- `view_sales`
- `create_sales`
- `refund_sales`
- `apply_discount`
- `view_sales_reports`
- `manage_cash_register`

### Equipe

- `view_team_members`
- `create_team_members`
- `update_team_members`
- `deactivate_team_members`
- `assign_roles`
- `manage_team_schedule`

### Presence

- `view_presence`
- `manage_own_presence`
- `manage_team_presence`
- `view_presence_reports`

### Chaises et postes

- `view_chairs`
- `manage_chairs`
- `assign_chairs_to_members`
- `activate_chair_on_check_in`

### Finances

- `view_invoices`
- `create_invoices`
- `update_invoices`
- `approve_invoices`
- `view_expenses`
- `create_expenses`
- `approve_expenses`
- `view_financial_reports`

### Parametres

- `view_settings`
- `manage_company_settings`
- `manage_billing_settings`
- `manage_integrations`
- `manage_roles_permissions`

### Rapports

- `view_reports`
- `view_team_reports`
- `view_sales_reports`
- `view_financial_reports`
- `export_reports`

### Campagnes

- `view_campaigns`
- `create_campaigns`
- `update_campaigns`
- `send_campaigns`
- `manage_campaign_templates`

### Boutique et vitrine

- `view_storefront`
- `manage_storefront`
- `manage_public_services`
- `manage_public_products`

## 10. Compatibilite avec les permissions existantes

Le projet utilise deja des slugs comme:

- `reservations.view`
- `reservations.queue`
- `reservations.manage`
- `jobs.view`
- `jobs.edit`
- `tasks.view`
- `tasks.create`
- `tasks.edit`
- `tasks.delete`
- `sales.manage`
- `sales.pos`
- `quotes.view`
- `quotes.edit`
- `campaigns.view`
- `campaigns.manage`
- `campaigns.send`
- `invoices.view`
- `expenses.view`

La migration doit eviter une rupture brutale.

Approche recommandee:

1. introduire les nouvelles permissions RBAC
2. ajouter une table d alias ou une map technique entre anciens et nouveaux slugs
3. faire evoluer `TeamMember::hasPermission()` pour accepter les deux formats
4. migrer les policies progressivement vers les nouveaux slugs
5. supprimer les anciens slugs seulement apres couverture complete

Exemple:

- ancien: `reservations.manage`
- nouveau possible: `update_reservations`, `cancel_reservations`,
  `manage_reservation_calendar`

## 11. Logique d acces

Une page ou action est autorisee seulement si:

- la fonctionnalite est active pour l entreprise
- l utilisateur est proprietaire du compte ou possede la permission requise
- la policy autorise l acces aux donnees ciblees
- les contraintes metier specifiques sont respectees

Exemples:

- `presence` active + `view_presence` permet de voir la page presence
- `presence` active + `manage_own_presence` permet son propre check-in
- `presence` active + `manage_team_presence` permet de gerer les autres membres
- `reservations` active + `view_own_reservations` permet de voir ses reservations
- `reservations` active + `view_all_reservations` permet de voir toute l equipe
- `settings` + `manage_roles_permissions` permet de modifier les roles

## 12. Sidebar et workspace hub

La navigation doit devenir permission-based.

Regle:

- une feature active rend un module possible
- une permission rend le module visible pour l utilisateur

Exemples:

- sans `view_presence`, l utilisateur ne voit pas Presence
- sans `view_reservations`, il ne voit pas Reservations
- sans `manage_roles_permissions`, il ne voit pas Roles et permissions
- sans `view_financial_reports`, il ne voit pas les rapports financiers

Le frontend doit utiliser les permissions partagees par Inertia.
Le backend reste la source de verite.

## 13. Protection backend

Ne jamais se limiter au frontend.

La migration doit introduire ou renforcer:

- policies Laravel
- gates Laravel
- middleware de permission
- form requests pour les actions sensibles
- verification explicite dans les services critiques

Middleware cible:

```php
permission:view_presence
permission:manage_roles_permissions
permission:assign_roles
```

Exemples:

- creer un role exige `manage_roles_permissions`
- assigner un role exige `assign_roles`
- modifier la presence d un autre membre exige `manage_team_presence`
- gerer sa propre presence exige `manage_own_presence`

## 14. Interface attendue

Route produit:

`Parametres > Equipe et acces > Roles et permissions`

Fonctions:

- voir les roles disponibles
- voir le nombre de membres par role
- creer un role
- modifier un role
- dupliquer un role
- desactiver un role
- supprimer un role personnalise non utilise
- consulter les permissions d un role

L edition d un role doit etre organisee par groupes:

- Clients
- Reservations
- Services
- Produits
- Ventes
- Equipe
- Presence
- Chaises et postes
- Finances
- Parametres
- Rapports
- Campagnes
- Boutique

L interface doit eviter une liste interminable.
Utiliser:

- tabs
- sections pliables
- cards compactes
- recherche de permission

## 15. Assignation d un role a un membre

Dans la fiche d un membre:

- afficher le role actuel
- permettre de selectionner un role actif
- expliquer les permissions principales du role
- interdire l assignation si l utilisateur connecte n a pas `assign_roles`

Regle MVP:

- un membre a un seul role principal

Multi-role:

- non inclus en phase 1
- peut etre etudie plus tard si besoin

## 16. Presence et chaises

Ce cas doit guider le design.

Regles metier:

- une chaise peut etre assignee a un membre
- une chaise active non assignee n est pas operationnelle
- une chaise assignee n est disponible que si le membre est checked-in
- si le membre est offline, la chaise n est pas disponible
- si le membre est busy, la chaise est occupee
- si le membre est en pause, la chaise n est pas disponible

Permissions liees:

- `view_chairs`
- `manage_chairs`
- `assign_chairs_to_members`
- `view_presence`
- `manage_own_presence`
- `manage_team_presence`
- `activate_chair_on_check_in`

Exemples:

- un coiffeur peut faire son propre check-in
- un coiffeur ne peut pas check-in un autre membre
- un manager peut gerer la presence equipe
- seul un profil autorise peut assigner des chaises

## 17. Decoupage de livraison en 3 phases

### Phase 1 - Fondation RBAC et compatibilite

Objectif:

Mettre en place la nouvelle source de verite sans casser les permissions
actuelles.

Livrables:

- migrations `company_roles`, `permissions`, `company_role_permission`
- ajout de `team_members.company_role_id`
- modeles Eloquent et relations
- seed des permissions de base
- seed des roles de base
- service central de resolution des permissions
- adaptation de `TeamMember::hasPermission()`
- fallback temporaire vers `team_members.permissions`
- map d alias entre anciennes permissions et nouvelles permissions
- assignation technique d un role principal a un membre

Tests:

- owner possede toutes les permissions
- role systeme Owner existe et reste protege
- role personnalisable peut etre cree
- role peut recevoir des permissions
- membre peut recevoir un role
- permission du role est resolue par `TeamMember::hasPermission()`
- permissions directes existantes restent compatibles
- alias anciens comme `reservations.manage` continuent de fonctionner

Definition of done:

- l ancien systeme continue de fonctionner
- le nouveau systeme peut deja resoudre les permissions par role
- aucune route existante importante ne regresse

### Phase 2 - Enforcement backend et navigation permission-based

Objectif:

Faire appliquer les permissions RBAC dans les endroits critiques, puis rendre
la navigation coherente avec ces permissions.

Livrables:

- middleware `permission`
- gates utiles
- partage Inertia des permissions resolues
- helper frontend centralise `hasPermission`
- sidebar base sur permissions
- workspace hub base sur permissions
- SettingsLayout base sur permissions
- premiere integration backend sur Presence
- premiere integration backend sur Chaises/Postes
- protections creation/modification/assignation roles
- refus backend des routes sensibles sans permission

Tests:

- sans `view_presence`, route presence refusee
- sans `view_presence`, Presence est absente de la navigation
- avec `view_presence`, Presence est visible si la feature est active
- feature inactive masque le module meme si permission presente
- avec `manage_own_presence`, membre gere sa propre presence
- sans `manage_team_presence`, membre ne gere pas les autres
- avec `manage_team_presence`, manager gere la presence equipe
- sans `assign_roles`, impossible d assigner un role
- sans `manage_roles_permissions`, impossible de gerer les roles

Definition of done:

- le frontend ne montre plus les acces critiques uniquement parce qu un module
  est actif
- le backend refuse les acces directs non autorises
- Presence et Chaises deviennent les premiers modules pilotes RBAC

### Phase 3 - Interface admin et migration progressive des modules

Objectif:

Donner aux proprietaires une vraie interface de configuration, puis migrer les
modules un par un vers la logique RBAC.

Livrables:

- page `Parametres > Equipe et acces > Roles et permissions`
- liste des roles
- compteur de membres par role
- creation de role
- edition de role
- duplication de role
- activation/desactivation de role
- suppression des roles personnalises non utilises
- interface de permissions groupees par domaine
- assignation du role dans la fiche membre
- migration progressive des modules restants

Ordre recommande de migration:

1. presence
2. chaises
3. reservations
4. equipe
5. clients
6. ventes
7. produits et services
8. finances
9. campagnes
10. rapports
11. parametres

Tests:

- owner peut gerer roles
- manager avec permission peut gerer roles
- role Owner ne peut pas etre supprime
- role systeme protege respecte `is_editable` et `is_deletable`
- role utilise ne peut pas etre supprime sans traitement explicite
- role personnalise non utilise peut etre supprime
- un membre avec role Coiffeur voit seulement les acces prevus
- un membre avec role Manager voit les acces prevus
- chaque module migre a ses tests frontend et backend d acces

Definition of done:

- les roles et permissions sont configurables depuis l interface
- les membres peuvent recevoir un role depuis l interface equipe
- les modules prioritaires ont quitte la logique de permissions directes
- le systeme est pret pour retirer progressivement `team_members.permissions`

## 18. Criteres d acceptation globaux

### Scenario 1 - Owner

Etant donne un proprietaire d entreprise,
quand il ouvre Malikia Pro,
alors il peut voir et gerer toutes les fonctionnalites actives de son compte.

### Scenario 2 - Role personnalise

Etant donne un proprietaire autorise,
quand il cree un role personnalise et lui assigne des permissions,
alors ce role peut etre assigne a un membre.

### Scenario 3 - Membre sans permission

Etant donne un membre sans `view_presence`,
quand il se connecte,
alors il ne voit pas Presence dans la navigation
et un acces direct a la route presence est refuse.

### Scenario 4 - Coiffeur

Etant donne un membre avec le role Coiffeur,
quand il se connecte,
alors il peut voir ses reservations,
gerer son propre check-in,
voir son planning,
mais ne peut pas gerer la presence d un autre membre.

### Scenario 5 - Manager

Etant donne un membre avec le role Manager,
quand il se connecte,
alors il peut voir l equipe,
gerer le planning equipe,
gerer la presence equipe
et acceder aux reservations selon les permissions du role.

### Scenario 6 - Role Owner protege

Etant donne le role Owner,
quand un utilisateur tente de le supprimer,
alors l action est refusee.

### Scenario 7 - Role utilise

Etant donne un role personnalise assigne a un membre,
quand un utilisateur tente de le supprimer,
alors l action est refusee ou demande une reassignation explicite.

### Scenario 8 - Feature inactive

Etant donne une permission presente mais une fonctionnalite inactive,
quand l utilisateur ouvre Malikia Pro,
alors le module reste indisponible.

## 19. Risques

- casser des policies existantes si les anciens slugs sont remplaces trop vite
- confondre role global utilisateur et role metier entreprise
- dupliquer la logique entre frontend et backend
- rendre la navigation incoherente si les helpers ne sont pas centralises
- donner trop d acces par defaut aux roles de base
- oublier les cas de donnees propres au membre, comme `own` vs `all`

## 20. Strategie anti-regression

- garder `TeamMember::hasPermission()` comme facade unique pendant la migration
- ne pas supprimer `team_members.permissions` au debut
- ajouter des tests avant de migrer chaque module
- migrer un module a la fois
- documenter les alias anciens vers nouveaux slugs
- verifier backend et frontend pour chaque permission critique

## 21. Definition of done MVP

La migration RBAC MVP est terminee quand:

- les roles metier entreprise existent
- les permissions existent et sont seedables
- un membre peut recevoir un role principal
- `TeamMember::hasPermission()` lit les permissions du role
- les permissions directes existantes continuent de fonctionner
- Presence et chaises utilisent les nouvelles permissions
- la navigation Presence/Operations respecte les permissions
- les routes sensibles Presence/Chaises sont protegees backend
- la page Roles et permissions est disponible aux utilisateurs autorises
- les tests couvrent owner, manager, coiffeur, role personnalise et refus d acces
