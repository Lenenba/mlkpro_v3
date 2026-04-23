# Social Auth + Onboarding First User Story

Derniere mise a jour: 2026-04-23

## 1. Resume

`Malikia Pro` doit permettre a un nouveau prospect de commencer son onboarding par une authentification simple avant de remplir les autres informations de configuration.

Le parcours cible est:

- choisir un mode d acces des la premiere etape
- continuer avec `Google`, `Microsoft`, `Facebook` ou `email + mot de passe`
- creer ou lier automatiquement le compte proprietaire
- reprendre ensuite l onboarding entreprise sans redemander les informations deja connues

Decision produit recommandee:

- experience visible: `Continuer avec Google`, `Continuer avec Microsoft`, `Continuer avec Facebook`, `Continuer avec email`
- ordre de livraison recommande pour reduire le risque: `Google` -> `Microsoft` -> `Facebook`
- `email + mot de passe` reste disponible comme fallback permanent

## 2. Pourquoi ce besoin est utile

Aujourd hui, l onboarding web commence deja par une etape `account` pour les invites.

Ce point d entree est donc le bon endroit pour faire evoluer le parcours:

- reduire la friction de creation de compte
- augmenter la conversion onboarding -> workspace cree
- rassurer les utilisateurs pros qui preferent un compte `Google Workspace` ou `Microsoft 365`
- garder un fallback simple pour les utilisateurs qui ne veulent pas utiliser un provider social

## 3. Story principale

En tant que nouveau visiteur,
je veux commencer l onboarding en choisissant rapidement un mode de connexion `Google`, `Microsoft`, `Facebook` ou `email`,
afin de creer ou reprendre mon compte proprietaire avant de continuer les autres etapes de configuration de mon espace.

## 4. Valeur business

- diminuer le taux d abandon au debut de l onboarding
- accelerer la creation du compte proprietaire
- mieux capter les utilisateurs B2B via `Google Workspace` et `Microsoft 365`
- garder une experience compatible PME avec un fallback `email + mot de passe`
- preparer plus tard le lien entre identite externe et securite interne du compte

## 5. Regle UX non negociable

Le parcours doit devenir `auth first`.

Concretement:

- pour un invite, l onboarding commence par un choix de methode d acces
- tant qu aucune authentification proprietaire n est terminee, les autres etapes `entreprise / type / secteur / equipe / plan / securite` ne doivent pas etre editables
- apres succes, l utilisateur revient automatiquement dans l onboarding et reprend au bloc suivant

## 6. Sous-stories prioritaires

### US-01 - L onboarding commence par le choix du mode d acces

En tant qu invite,
je veux voir des le debut les options `Google`, `Microsoft`, `Facebook` et `email`,
afin de commencer mon onboarding par la methode la plus simple pour moi.

Acceptance criteria:

1. la premiere etape visible de l onboarding est un bloc `Choisir comment continuer`
2. les options `Google`, `Microsoft`, `Facebook` et `email` sont presentees clairement
3. si je choisis `email`, je peux toujours creer mon compte proprietaire comme aujourd hui
4. si je suis deja connecte et proprietaire non onboarde, je reprends directement l onboarding sans repasser par ce choix
5. les autres etapes restent bloquees tant que le compte proprietaire n existe pas

### US-02 - Connexion Google

En tant que nouveau prospect,
je veux continuer avec Google,
afin de creer ou lier mon compte proprietaire en quelques clics.

Acceptance criteria:

1. le flow utilise un scope minimal d authentification `openid / profile / email`
2. si l email Google verifie correspond deja a un utilisateur existant, le compte peut etre lie ou reconnecte en securite
3. si aucun utilisateur n existe, un owner est cree automatiquement
4. apres succes, l utilisateur revient sur `onboarding.index`
5. si l onboarding n est pas termine, il reprend a l etape entreprise

### US-03 - Connexion Microsoft

En tant que prospect avec une adresse pro,
je veux continuer avec Microsoft,
afin d utiliser mon identite `Microsoft 365` ou `Entra` pour commencer l onboarding.

Acceptance criteria:

1. le flow supporte l authentification Microsoft moderne via OIDC
2. si l identite Microsoft fournit un email verifie exploitable, l app peut creer ou lier le compte proprietaire
3. l utilisateur revient dans l onboarding au bon endroit apres succes
4. les erreurs de consentement ou de callback restent compréhensibles
5. l onboarding ne perd pas le contexte `plan / billing_period` deja selectionne

### US-04 - Connexion Facebook

En tant que prospect qui prefere Facebook,
je veux continuer avec Facebook,
afin de creer mon compte sans remplir un formulaire classique.

Acceptance criteria:

1. le flow repose sur `Facebook Login` uniquement pour l identification utilisateur
2. si Facebook ne renvoie pas un email exploitable, l app demande un complement avant de poursuivre
3. le compte social ne remplace jamais les roles ou permissions internes
4. apres succes, l utilisateur reprend l onboarding au bon endroit
5. l option peut etre feature-flaggee ou livree apres Google et Microsoft si le risque Meta est trop eleve

### US-05 - Liaison et reprise de compte

En tant qu utilisateur existant,
je veux que mes identities sociales puissent etre liees sans creer de doublons,
afin de retrouver mon compte et mon onboarding deja commence.

Acceptance criteria:

1. une identite sociale deja liee reconnecte toujours le bon utilisateur
2. un email verifie deja connu ne cree pas de doublon silencieux
3. si un conflit existe, l utilisateur voit un message clair et une action de resolution
4. un meme compte social ne peut pas etre lie a deux utilisateurs differents
5. le systeme journalise les liaisons et re-liaisons sensibles

### US-06 - Resume onboarding apres auth

En tant que nouveau proprietaire authentifie,
je veux tomber directement sur l etape entreprise apres la connexion,
afin de continuer mon onboarding sans refaire le debut du parcours.

Acceptance criteria:

1. apres succes, l utilisateur est authentifie en session web
2. l app redirige vers `onboarding.index`
3. si l owner n est pas onboarde, le wizard reprend apres l etape compte
4. si l owner est deja onboarde, la redirection normale continue vers `dashboard`
5. les valeurs de contexte comme `plan`, `billing_period` et eventuels presets onboarding sont conservees

### US-07 - Fallback email + mot de passe

En tant qu utilisateur qui ne veut pas utiliser de provider tiers,
je veux garder une creation de compte classique,
afin de ne pas bloquer mon acces a la plateforme.

Acceptance criteria:

1. le formulaire `email + mot de passe` reste disponible
2. il reste possible de commencer et finir l onboarding sans provider social
3. la connexion email existante continue a rediriger les owners non onboardes vers `onboarding.index`
4. aucun provider social ne devient obligatoire
5. le support peut guider un utilisateur vers le fallback email en cas de blocage provider

## 7. Scope recommande

### V1

- point d entree onboarding `auth first`
- `Google` login
- `Microsoft` login
- fallback `email + mot de passe`
- table de liaison des identities sociales
- reprise automatique de l onboarding apres auth

### V1.1

- `Facebook` login
- ecran de gestion des comptes sociaux dans le profil utilisateur
- unlink / relink self-service

### Hors scope immediat

- utiliser les connexions sociales de `Malikia Pulse` pour authentifier les utilisateurs
- deleguer les roles/permissions internes aux providers
- supprimer l auth locale `email + mot de passe`
- connecter `LinkedIn` en login dans cette premiere phase

## 8. Cadrage technique recommande

Principe cle:

- ne pas melanger `social auth utilisateur` et `social connections de publication`

Le socle `Malikia Pulse` actuel gere des comptes sociaux a connecter pour publier.
Ce besoin doit vivre dans une couche differente.

Entites recommandees:

- `user_social_accounts`

Champs recommandes:

- `user_id`
- `provider`
- `provider_user_id`
- `provider_email`
- `provider_email_verified_at`
- `provider_name`
- `provider_avatar_url`
- `access_token`
- `refresh_token`
- `token_expires_at`
- `last_login_at`
- `metadata`

Regles:

1. chiffrer les tokens si on les garde
2. minimiser les scopes d authentification
3. separer les routes `auth.social.*` des routes `social.accounts.*`
4. garder la logique provider dans une couche dediee
5. ne jamais lier un provider a un autre utilisateur sans verification claire

## 9. UX cible

### Ecran 1 - Account access

Le premier ecran d onboarding invite montre:

- un titre `Commencez par votre acces`
- trois CTA principaux:
  - `Continuer avec Google`
  - `Continuer avec Microsoft`
  - `Continuer avec Facebook`
- un fallback secondaire:
  - `Continuer avec email`

### Reprise apres auth

Apres succes:

- session active
- owner cree ou relie
- wizard positionne directement sur `Entreprise`

### Cas retour utilisateur existant

- si owner + onboarding incomplet -> retour direct onboarding
- si owner + onboarding complete -> `dashboard`
- si non-owner -> `PendingOwner` ou route metier appropriee

## 10. Risques a cadrer tot

- `Facebook` peut etre plus fragile a configurer et a maintenir que Google/Microsoft
- certains providers ne renvoient pas toujours un email exploitable
- il faut definir une regle claire de lien automatique vs confirmation utilisateur
- il ne faut pas casser le redirect onboarding actuel deja en place pour les owners non onboardes
- la table de liaison doit etre testee contre les doublons et conflits

## 11. Recommendation finale

La bonne direction est:

- faire de l onboarding un parcours `auth first`
- livrer d abord `Google` et `Microsoft`
- garder `Facebook` dans la cible visible, mais accepter de le decaler en `V1.1` si la friction Meta ralentit le chantier
- conserver `email + mot de passe` comme voie de secours durable

La story a retenir pour lancer le chantier est donc:

> En tant que nouveau visiteur, je veux commencer l onboarding en choisissant un mode de connexion `Google`, `Microsoft`, `Facebook` ou `email`, afin de creer ou lier mon compte proprietaire avant de continuer les autres etapes de configuration de mon espace.

## 12. Plan de livraison par phases

### Phase 1 - Socle social auth

Etat:

- livre le 2026-04-23

Objectif:

- creer la table `user_social_accounts`
- ajouter le modele Eloquent et la relation `User`
- chiffrer les tokens conserves
- poser les contraintes anti-doublons de liaison
- couvrir le schema, les casts et les relations par des tests

Resultat attendu:

- le module a un socle data propre et testable
- aucune UX provider n est encore exposee tant que la couche OAuth n est pas prete

Backlog associe:

- `AUTH-001` schema `user_social_accounts`

### Phase 2 - Registry providers et configuration

Etat:

- livre le 2026-04-23

Objectif:

- centraliser la configuration des providers supportes
- preparer les routes `auth.social.*`
- definir les scopes minimums et les redirect URIs dedies
- permettre l activation provider par feature flag ou config

Resultat attendu:

- `Google`, `Microsoft` et `Facebook` ont une couche de configuration claire
- le projet peut activer les providers progressivement sans dette de routing

Backlog associe:

- `AUTH-002` registry providers social auth

### Phase 3 - Google login V1

Etat:

- livre le 2026-04-23

Objectif:

- implementer le redirect Google et le callback
- creer ou lier le compte proprietaire avec email verifie
- reconnecter un compte deja lie sans doublon

Resultat attendu:

- un prospect peut entrer dans l onboarding via `Google`
- la reprise vers `onboarding.index` fonctionne apres succes

Backlog associe:

- `AUTH-003` Google login web + callback + liaison compte

### Phase 4 - Microsoft login V1

Objectif:

- ajouter le flow `Microsoft 365 / Entra`
- reutiliser les memes regles de liaison, de reprise et de securite que Google

Resultat attendu:

- les prospects avec adresse pro peuvent commencer par `Microsoft`
- le contexte onboarding conserve `plan` et `billing_period`

Backlog associe:

- `AUTH-004` Microsoft login web + callback + liaison compte

### Phase 5 - Onboarding auth first visible

Objectif:

- transformer l etape compte en vrai choix de methode d acces
- garder `email + mot de passe` comme fallback
- reprendre le wizard directement apres l etape compte en cas de succes

Resultat attendu:

- l onboarding invite commence explicitement par `Google`, `Microsoft`, `Facebook` ou `email`
- les autres etapes restent bloquees tant que le proprietaire n est pas authentifie

Backlog associe:

- `AUTH-005` onboarding `auth first` + reprise a l etape entreprise
- `AUTH-006` fallback email garde et aligne

### Phase 6 - Facebook login

Objectif:

- ajouter `Facebook Login` sur la meme architecture
- gerer proprement les cas ou l email n est pas exploitable
- garder un feature flag si la validation Meta ralentit le chantier

Resultat attendu:

- `Facebook` rejoint la liste des entrees onboarding sans fragiliser la V1

Backlog associe:

- `AUTH-007` Facebook login web

### Phase 7 - Profil, operations et hardening

Objectif:

- permettre a l utilisateur de voir ses providers lies
- supporter `lier / delier / relier`
- journaliser les operations sensibles
- etendre les tests sur les conflits de liaison et les cas de support

Resultat attendu:

- le module est exploitable sur la duree, pas seulement au premier login

Backlog associe:

- `AUTH-008` ecran profil pour voir / lier / delier les providers
- `AUTH-009` logs securite + tests de conflits de liaison

## 13. Proposition de decoupage backlog

- `AUTH-001` schema `user_social_accounts`
- `AUTH-002` registry providers social auth
- `AUTH-003` Google login web + callback + liaison compte
- `AUTH-004` Microsoft login web + callback + liaison compte
- `AUTH-005` onboarding `auth first` + reprise a l etape entreprise
- `AUTH-006` fallback email garde et aligne
- `AUTH-007` Facebook login web
- `AUTH-008` ecran profil pour voir / lier / delier les providers
- `AUTH-009` logs securite + tests de conflits de liaison
