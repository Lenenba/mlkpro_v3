# Campaigns Prospect Provider Integrations - Delivery Blueprint

Derniere mise a jour: 2026-03-16

## Goal
Traduire la user story `Campaigns Prospect Providers Integrations` en plan de livraison concret, sequencable et efficace pour produit, design et developpement.

Ce document couvre:
- les epics a livrer
- le decoupage en tickets concrets
- l ordre de livraison recommande
- les choix d architecture a figer avant implementation
- les risques a eviter

## Summary
Le chemin recommande est:

`provider connection -> provider selection -> provider preview -> import selection -> prospect batch -> review -> approval -> outreach`

Le module `campaigns` reste l orchestrateur.
Le pipeline prospecting existant reste le coeur metier.
Les fournisseurs externes deviennent des sources d intake gouvernees, pas des voies rapides qui contournent la review.

## V1 Scope
La V1 doit livrer 5 capacites prioritaires:
- connexion tenant-scoped a des fournisseurs de prospects
- choix explicite du fournisseur dans le flux Audience
- previsualisation des prospects avant import
- import des prospects dans le pipeline prospecting existant
- support de `Apollo`, `Lusha` et `UpLead`

## Guiding Principles
- reutiliser le pipeline `campaign_prospects` existant
- ne pas court-circuiter `analyze -> review -> approve`
- garder une architecture extensible a un 4e provider
- separer clairement:
  - connexion fournisseur
  - fetch provider
  - preview provider
  - import confirme
- rendre les erreurs d integration lisibles pour l utilisateur

## Recommended Architecture Decisions

### Decision 1 - Introduce a shared provider connection model
Ne pas stocker les credentials directement dans la campagne.

Recommendation:
- creer une entite tenant-scoped de connexion fournisseur
- une campagne reference un fournisseur au moment de l import, pas comme source permanente obligatoire

Champs minimum recommandes:
- `user_id`
- `provider_key`
- `label`
- `status`
- `credentials_encrypted`
- `last_validated_at`
- `last_error`
- `metadata`

### Decision 2 - Use a provider adapter contract
Ne pas mettre de logique `if Apollo / if Lusha / if UpLead` partout dans les controllers.

Recommendation:
- definir un contrat unique pour les adapters provider
- chaque provider implemente:
  - `validateCredentials`
  - `fetchPreview`
  - `normalizePreviewRows`
  - `importSelectedRows`

### Decision 3 - Keep preview separate from import
Ne pas transformer directement les resultats provider en `campaign_prospects`.

Recommendation:
- un premier appel fetch les donnees provider
- la plateforme construit une preview
- l utilisateur selectionne
- un second appel confirme l import des lignes retenues

### Decision 4 - Keep provider provenance explicit
Chaque prospect importe doit rester explicable apres import.

Recommendation:
- renseigner `source_type`
- renseigner `source_reference`
- stocker `external_ref` quand possible
- conserver le contexte fournisseur dans `metadata`

### Decision 5 - Reuse existing prospect analysis engine
Ne pas creer une seconde logique d analyse pour les providers.

Recommendation:
- une fois les lignes confirmees, passer par le meme service d intake que CSV et manuel
- garder dedupe, scoring, review et batch summary au meme endroit

## Epic Breakdown

## Epic A - Shared Provider Foundation
Objectif:
Poser l architecture commune avant de brancher un provider reel.

### Ticket A1 - Provider connection entity
Description:
- creer le modele de connexion fournisseur par tenant
- stocker proprement le type de fournisseur et ses credentials

Done when:
- un tenant peut posseder plusieurs connexions fournisseurs
- chaque connexion a un etat et un historique de validation minimal

### Ticket A2 - Secure credential storage
Description:
- chiffrer les credentials
- definir les regles de lecture et d ecriture

Done when:
- aucun secret provider n est expose en clair dans l UI ou les payloads inutiles
- les credentials peuvent etre mis a jour sans casser l historique

### Ticket A3 - Provider adapter contract
Description:
- definir une interface partagee pour les providers
- definir un resolver ou registry des adapters

Done when:
- Apollo, Lusha et UpLead peuvent s enregistrer via le meme point d entree
- le controller n a pas besoin de logique provider-specific lourde

### Ticket A4 - Provider connection validation flow
Description:
- permettre de tester une connexion provider
- remonter un etat exploitable dans l UI

Done when:
- la plateforme sait marquer une connexion:
  - valide
  - invalide
  - expiree
  - rate-limited

## Epic B - Provider Management UX
Objectif:
Permettre aux tenants de connecter et gerer les fournisseurs sans complexifier le module campagnes.

### Ticket B1 - Provider settings screen
Description:
- creer un ecran ou une section dediee pour connecter:
  - Apollo
  - Lusha
  - UpLead

Done when:
- un tenant peut ajouter, tester, renommer et desactiver une connexion provider

### Ticket B2 - Provider status visibility
Description:
- afficher l etat des connexions dans l interface

Done when:
- l utilisateur sait quel fournisseur est disponible avant d aller dans Audience

### Ticket B3 - Permission model
Description:
- decider qui peut connecter, tester ou utiliser un provider

Done when:
- seuls les profils autorises peuvent modifier les credentials
- les autres profils peuvent utiliser uniquement les connexions autorisees

## Epic C - Provider Selection In Campaign Audience
Objectif:
Ajouter un mode provider dans l etape Audience sans casser les modes manuel et CSV.

### Ticket C1 - New import mode in Audience
Description:
- ajouter un mode `provider` a cote de `manual` et `csv`

Done when:
- l utilisateur peut basculer entre:
  - manuel
  - CSV
  - provider

### Ticket C2 - Provider chooser
Description:
- ajouter un select de fournisseur branche aux connexions actives

Done when:
- l utilisateur peut choisir `Apollo`, `Lusha` ou `UpLead`
- seuls les fournisseurs connectes et exploitables sont selectionnables

### Ticket C3 - Provider query input
Description:
- definir un bloc d entree pour les parametres de recherche provider

Done when:
- le flux supporte au minimum un contexte de requete ou de fetch lisible
- le systeme peut garder une trace de la requete ayant produit la preview

## Epic D - Provider Prospect Preview
Objectif:
Permettre a l utilisateur de voir les prospects du fournisseur avant tout import.

### Ticket D1 - Provider preview endpoint
Description:
- creer un endpoint de preview qui fetch le provider sans creer encore de batch

Done when:
- la plateforme peut recuperer un jeu de lignes previewables depuis un fournisseur

### Ticket D2 - Preview normalization
Description:
- transformer les resultats provider vers un schema de preview commun

Done when:
- chaque ligne preview expose au minimum:
  - company name
  - contact name
  - email et ou phone
  - website ou domain
  - location
  - provider origin

### Ticket D3 - Preview table UI
Description:
- afficher une table ou liste de preview lisible

Done when:
- l utilisateur peut visualiser les prospects trouves avant import
- les manques evidents sont visibles
- la provenance provider est claire

### Ticket D4 - Selection before import
Description:
- permettre select all, unselect all et selection ligne par ligne

Done when:
- l import ne prend que les lignes explicitement retenues
- aucune creation de batch ne se fait sur l ensemble brut sans confirmation

## Epic E - Import Orchestration And Batch Creation
Objectif:
Faire entrer les lignes selectionnees dans le pipeline prospecting existant.

### Ticket E1 - Confirm import flow
Description:
- creer le flux de confirmation apres preview

Done when:
- l utilisateur peut confirmer l import de la selection courante
- le systeme cree un ou plusieurs `campaign_prospect_batches`

### Ticket E2 - Provider-to-prospect normalization
Description:
- mapper les donnees provider vers le contrat interne prospect

Done when:
- les champs de base sont normalises de facon stable
- `source_type`, `source_reference` et `metadata` sont correctement renseignes

### Ticket E3 - Reuse analysis engine
Description:
- brancher l import provider sur le service de prospection existant

Done when:
- dedupe, scoring, blocked reasons et accepted count fonctionnent comme pour CSV et manuel

### Ticket E4 - Provider import summary
Description:
- afficher un recap clair apres import

Done when:
- l utilisateur voit:
  - imported
  - analyzed
  - duplicates
  - blocked
  - accepted

## Epic F - Apollo Integration
Objectif:
Livrer le premier provider de reference avec un flux complet de bout en bout.

### Ticket F1 - Apollo adapter
Description:
- implementer l adapter Apollo conforme au contrat commun

Done when:
- Apollo supporte validation credentials, fetch preview, normalization et import

### Ticket F2 - Apollo metadata preservation
Description:
- conserver le contexte Apollo utile

Done when:
- les prospects importes gardent les identifiants et references Apollo utiles

### Ticket F3 - Apollo QA and rollout
Description:
- tester le flux complet avec vrais cas limites

Done when:
- Apollo est utilisable de bout en bout dans une campagne prospecting

## Epic G - Lusha Integration
Objectif:
Livrer le deuxieme provider en reutilisant la base commune.

### Ticket G1 - Lusha adapter
Description:
- implementer l adapter Lusha conforme au contrat commun

Done when:
- Lusha supporte validation credentials, fetch preview, normalization et import

### Ticket G2 - Lusha enrichment mapping
Description:
- conserver les enrichissements utiles sans casser le schema commun

Done when:
- l import Lusha garde les donnees enrichies utiles dans `metadata`

### Ticket G3 - Lusha QA and rollout
Description:
- tester le flux complet en conditions reelles

Done when:
- Lusha est utilisable de bout en bout dans une campagne prospecting

## Epic H - UpLead Integration
Objectif:
Livrer le troisieme provider sans divergence architecture.

### Ticket H1 - UpLead adapter
Description:
- implementer l adapter UpLead conforme au contrat commun

Done when:
- UpLead supporte validation credentials, fetch preview, normalization et import

### Ticket H2 - UpLead source semantics
Description:
- figer le choix produit sur `source_type=directory_api` ou `connector`

Done when:
- le comportement est documente et coherent dans l UI, les batches et le reporting

### Ticket H3 - UpLead QA and rollout
Description:
- tester le flux complet en conditions reelles

Done when:
- UpLead est utilisable de bout en bout dans une campagne prospecting

## Epic I - Observability, Safety, And Compliance
Objectif:
Rendre le systeme robuste et explicable avant generalisation.

### Ticket I1 - Import logs and audit trail
Description:
- journaliser les imports provider
- garder les erreurs metier et techniques utiles

Done when:
- un import provider peut etre audite apres coup

### Ticket I2 - Retry and partial failure strategy
Description:
- definir comment reagir aux timeouts, limites et erreurs de pagination

Done when:
- les erreurs provider n entrainent pas de batches corrompus

### Ticket I3 - Duplicate import protection
Description:
- eviter les reimports sauvages d une meme source sans signalement

Done when:
- le systeme limite les doublons silencieux
- l utilisateur comprend ce qui a deja ete importe

### Ticket I4 - Compliance and provider usage guardrails
Description:
- encadrer l usage des providers dans le produit

Done when:
- les messages UI rappellent que l import provider ne remplace pas les regles de consentement et de contactabilite

## Recommended Delivery Order

### Phase 1 - Foundation
- Epic A
- Epic B

Objectif:
poser le socle sans encore promettre une experience provider complete dans Audience

### Phase 2 - Campaign UX and preview
- Epic C
- Epic D
- Epic E

Objectif:
rendre le flux provider exploitable de facon generique dans le wizard de campagne

### Phase 3 - First provider reference
- Epic F

Objectif:
valider l architecture avec un premier provider reel

### Phase 4 - Provider expansion
- Epic G
- Epic H

Objectif:
prouver que la base est reusable sans dette d architecture

### Phase 5 - Hardening
- Epic I

Objectif:
securiser, observer et fiabiliser avant adoption large

## Dependencies

### Product dependencies
- choix final du perimetre V1 des providers
- validation produit du comportement preview -> selection -> import
- choix produit du `source_type` final pour UpLead

### Technical dependencies
- mecanisme de stockage chiffre des credentials
- convention commune pour les metadata provider
- points d integration UI dans l etape Audience

### External dependencies
- comptes de test pour Apollo, Lusha et UpLead
- documentation API de chaque provider
- clarification des limites de quota et des conditions d usage

## Risks To Avoid
- brancher les providers directement dans l audience envoyable sans review
- multiplier les branches conditionnelles provider-specific dans le wizard
- melanger preview provider et import definitif dans un seul endpoint
- stocker trop de donnees provider brutes sans schema
- rendre les erreurs d API provider invisibles pour l utilisateur

## Concrete Delivery Checklist
- l utilisateur peut connecter un provider
- l utilisateur voit si la connexion fonctionne
- l utilisateur peut choisir un provider dans Audience
- l utilisateur peut lancer une preview provider
- l utilisateur peut selectionner les lignes a importer
- l import cree un batch prospecting normal
- le batch passe par scoring et review
- le reporting garde la provenance provider
- Apollo fonctionne
- Lusha fonctionne
- UpLead fonctionne

## Definition Of Done
- les 3 fournisseurs peuvent etre choisis dans le flux Audience
- les prospects peuvent etre previsualises avant import
- seuls les prospects confirmes entrent dans les batches
- le pipeline de prospection existant reste la voie unique vers l outreach
- les imports sont tracables, audites et robustes
- l architecture permet l ajout d un nouveau provider sans refonte du wizard
