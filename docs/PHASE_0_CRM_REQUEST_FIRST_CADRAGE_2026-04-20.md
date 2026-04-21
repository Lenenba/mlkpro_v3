# Phase 0 CRM - Request-first cadrage detaille

Derniere mise a jour: 2026-04-20

## 1. But de la phase 0

La phase 0 sert a verrouiller le cadrage avant d'ouvrir les phases de livraison CRM.

Le but n'est pas de construire une nouvelle couche produit.

Le but est de decider clairement:

1. quels objets portent le CRM dans la base actuelle
2. quels objets on n'introduit pas tout de suite
3. quelles extensions de schema sont acceptables sans regression
4. quel ordre d'execution est le plus sur
5. quels tests et garde-fous doivent etre obligatoires avant chaque phase

## 2. Reponse de cadrage

Decision recommande et retenue pour la suite:

- strategie `Request-first`
- pas d'objet `Opportunity` autonome en debut de trajectoire
- `Customer` reste le referentiel client
- `Quote` reste l'objet commercial principal apres qualification
- `ActivityLog` reste la timeline commune V1
- `next_follow_up_at` et `Task` portent les prochaines actions tant qu'on n'a pas besoin d'un objet dedie

En clair:

- le CRM monte d'abord en puissance en renforcant `Request`
- on n'ajoute pas une nouvelle couche conceptuelle si l'existant peut porter la valeur

## 3. Baseline observee dans le code

La base actuelle montre deja un noyau CRM exploitable:

- `app/Models/Request.php`
  - statuts de lead
  - assignee
  - `next_follow_up_at`
  - relation `quote`
  - notes, media, tasks
- `app/Models/Quote.php`
  - lien `request_id`
  - lien `customer_id`
  - pont vers `work`
  - synchronisation du statut `Quote -> Request`
- `app/Models/Customer.php`
  - fiche client 360
  - relations `requests`, `quotes`, `works`, `invoices`
- `app/Models/ActivityLog.php`
  - journal polymorphique deja reutilisable
- `app/Http/Controllers/RequestController.php`
  - vues table et board
  - analytics leads
  - risques leads
  - assignation, import, merge, conversion en devis
- `app/Http/Controllers/PipelineController.php`
  - lecture canonique du flux `request -> quote -> job -> task -> invoice`
- `app/Services/CompanyFeatureService.php`
  - feature flags et gating par plan

Conclusion:

- on ne part pas d'une feuille blanche
- la base actuelle est suffisante pour lancer les phases 1 et 2 sans refonte majeure

## 4. Cartographie cible des objets

| Concept produit | Objet / support actuel | Decision phase 0 | Commentaire |
| --- | --- | --- | --- |
| Lead | `Request` | Garder | En V1 CRM, un lead entrant reste un `Request` ouvert. |
| Request | `Request` | Renforcer | Devient le cockpit principal de triage et de suivi initial. |
| Customer | `Customer` | Garder | Referentiel client, relation 360, historique et contexte. |
| Quote | `Quote` | Garder | Objet commercial principal apres qualification. |
| Opportunity | Aucun modele dedie | Reporter | A reconsiderer seulement quand la couche sales activity sera stable. |
| Next action | `next_follow_up_at` + `Task` | Garder en V1 | Suffisant pour les phases 1 et 2 sans nouveau modele. |
| Follow-up | `ActivityLog` + `Task` + events UI | Garder en V1 | Le follow-up est un comportement, pas encore un objet autonome. |
| Activity timeline | `ActivityLog` | Etendre | Support de base pour la future couche sales activity. |

## 5. Decisions structurelles actees

### 5.1 `Lead` reste un concept metier, pas un nouveau modele

Dans la base actuelle, le meilleur support d'un lead est deja `Request`.

Donc:

- on peut parler de `lead` dans l'UX et la documentation
- mais on ne cree pas un second modele pour representer la meme chose

### 5.2 `Request` devient le centre du CRM amont

`Request` porte deja:

- l'entree du besoin
- le canal
- le statut
- l'assignee
- la relance datee
- la conversion en devis

Donc:

- `Request` est l'objet CRM prioritaire a renforcer
- toutes les phases initiales doivent d'abord etendre cet objet avant d'introduire une abstraction supplementaire

### 5.3 `Customer` reste le referentiel, pas le pipeline

`Customer` doit rester:

- la fiche de reference
- le contexte 360
- l'historique relationnel

`Customer` ne doit pas devenir:

- la file de travail quotidienne des leads
- le substitut de `Request`

### 5.4 `Quote` reste l'etape commerciale majeure

`Quote` est deja le meilleur pivot entre:

- qualification commerciale
- proposition de valeur
- revenu
- execution

Il faut donc:

- renforcer autour de `Quote`
- eviter de creer un objet `Opportunity` qui duplique ce role trop tot

### 5.5 Pas d'objet `Opportunity` avant preuve de besoin

Decision de phase 0:

- ne pas creer `Opportunity` en phases 1 a 4

Un objet `Opportunity` ne devient pertinent que si:

1. un meme compte porte plusieurs opportunites commerciales actives hors `Request`
2. le pipeline a besoin d'exister avant ou au-dela du devis
3. `next actions`, activites, forecast et ownership ne tiennent plus proprement dans `Request + Quote + ActivityLog`

Tant que ce seuil n'est pas atteint:

- ajouter `Opportunity` augmenterait le risque de regression et de confusion

### 5.6 `Next action` reste leger en V1

Pour les phases 1 et 2, la bonne strategie est:

- `next_follow_up_at` sur `Request`
- `Task` pour les actions structurees
- `ActivityLog` pour la trace

Ce trio couvre deja:

- relance a faire
- action assignee
- historique de ce qui a ete fait

### 5.7 La timeline commune doit rester polymorphique

`ActivityLog` est deja en place.

Il faut l'utiliser comme socle plutot que creer plusieurs timelines concurrentes.

Decision:

- toute nouvelle activite CRM doit d'abord se brancher sur `ActivityLog`
- une specialisation d'activite n'est acceptable que si elle enrichit le modele sans casser la timeline commune

## 6. Invariants a proteger

Ces invariants ne doivent pas etre casses pendant les phases 1 et 2:

1. le flux `request -> quote -> job -> task -> invoice` reste le flux canonique
2. la conversion `Request -> Quote` doit rester simple et visible
3. la synchronisation de statut `Quote -> Request` ne doit pas etre contournee sans remplacement clair
4. les analytics existantes de `Request` doivent rester calculables
5. la fiche `Customer` doit continuer a lire les relations actuelles sans couche intermediaire obligatoire
6. les vues table et board de `Request` doivent rester utilisables pendant toute la transition

## 7. Extensions de schema recommandees pour les phases suivantes

Ces champs sont compatibles avec une evolution additive de `Request`:

- `first_response_at`
- `last_activity_at`
- `sla_due_at`
- `triage_priority`
- `risk_level`
- `stale_since_at`

Ces champs sont compatibles avec une evolution additive de `Quote`:

- `last_sent_at`
- `last_viewed_at`
- `follow_up_state`
- `follow_up_count`
- `quote_age_days`

Regle:

- tant qu'une extension peut rester sur `Request` ou `Quote`, on prefere cette voie a l'ajout d'un nouvel objet

## 8. Ce qu'on ne fait pas en phase 0

Pour proteger la base, la phase 0 exclut explicitement:

- creation d'un modele `Opportunity`
- refonte complete du board `Request`
- inbox email native
- sync Gmail / Outlook complete
- telephonie / call logging natif
- nouveau pipeline generique qui remplace le pipeline metier existant

## 9. Backlog concret de phase 0

### P0-001 - Glossaire officiel CRM

Produire un glossaire simple et stable pour:

- `Lead`
- `Request`
- `Customer`
- `Quote`
- `Next action`
- `Follow-up`

Sortie attendue:

- definitions courtes
- equivalence produit / code / UX

### P0-002 - Contrat de donnees `Request-first`

Figer les champs de travail minimaux du CRM amont.

Sortie attendue:

- champs obligatoires
- champs derives
- champs futurs acceptes
- regles de compatibilite

### P0-003 - Cartographie des points de lecture

Lister ou `Request`, `Quote`, `Customer`, `ActivityLog` sont lus par:

- controllers
- pages Inertia
- analytics
- pipeline transversal

Sortie attendue:

- inventaire des points a proteger par test

### P0-004 - Strategie feature flags CRM

Definir les flags ou gates de release pour:

- triage request
- cockpit devis
- playbooks
- sales activity

Sortie attendue:

- matrice simple de flags / modules / dependances

### P0-005 - Baseline KPI

Figer les KPIs de reference avant implementation:

- temps moyen premiere reponse
- volume de leads stale
- taux lead -> quote
- taux quote -> accepted
- relances dues non traitees

Sortie attendue:

- definition de calcul
- source de verite
- cadence de suivi

### P0-006 - Checklist anti-regression CRM

Transformer les garde-fous du plan en checklist de release.

Sortie attendue:

- checks backend
- checks frontend
- smoke critiques
- rollback attendu

## 10. Definition de done de la phase 0

La phase 0 est terminee si et seulement si:

1. le choix `Request-first` est explicitement valide
2. le non-besoin d'un objet `Opportunity` immediate est acte
3. les invariants du flux coeur sont listes et acceptes
4. les champs cibles de `Request` et `Quote` sont cadres
5. les KPIs de reference sont definis
6. les points de regression a proteger par test sont identifies
7. l'ordre officiel des phases 1 a 3 est fige

## 11. Recommandation finale

La bonne decision pour Malikia Pro aujourd'hui est tres claire:

- garder un CRM `Request-first`
- faire monter `Request` et `Quote` en puissance
- utiliser `ActivityLog` comme colonne vertebrale de l'activite
- retarder `Opportunity` jusqu'au moment ou l'existant devient reellement trop court

Cette approche donne:

- moins de regression
- moins de dette structurelle
- une roadmap plus lisible
- une meilleure chance de livrer vite sans fragiliser la base

## 12. Sources internes

- `app/Models/Request.php`
- `app/Models/Quote.php`
- `app/Models/Customer.php`
- `app/Models/ActivityLog.php`
- `app/Http/Controllers/RequestController.php`
- `app/Http/Controllers/PipelineController.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Services/CompanyFeatureService.php`
- `docs/PLAN_STRATEGIQUE_CRM_COMPETITIF_2026-04-20.md`
