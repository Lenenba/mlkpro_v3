# CRM phase 6 opportunity layer guide

Derniere mise a jour: 2026-04-21

## 1. Resume

La story CRM phase 6 ajoute une couche commerciale lisible sans introduire de table `Opportunity` persistante.

Le principe retenu:

- projeter une `Opportunity` non persistante a partir de `Request / Quote / Work / Invoice`
- reutiliser cette projection partout
- brancher dessus le pipeline commercial, la sales inbox, le forecast et le dashboard manager
- garder la navigation cross-object coherente via `crm_links`
- rattacher les ecrans revenue au module forfaitaire `sales`

## 2. Ce qui a ete ajoute

### Backend

- contrat `opportunity_validation`
  - formalise la decision `request_quote_first`
  - confirme `requires_opportunity = false`
  - expose les ancres `current / forecast / next_action`

- projection `Opportunity`
  - stage canonique `intake / contacted / qualified / quoted / won / lost`
  - forecast `pipeline / best_case / closed_won / closed_lost`
  - timestamps `opened_at / quoted_at / won_at`
  - linking cross-object `request / quote / customer / job / invoice`

- query `sales pipeline`
  - support des opportunites `request-backed`
  - support des opportunites `quote-only`
  - board et stats consommes par les vues CRM phase 6

- `sales inbox`
  - queues `overdue / no_next_action / quoted / needs_quote / active`
  - tri commercial priorise pour les opportunites ouvertes

- `sales forecast service`
  - `open_amount`
  - `weighted_open_amount`
  - mix forecast
  - aging par stage
  - couverture des next actions
  - wins `month / quarter / year`

- `manager dashboard`
  - cartes de synthese revenue
  - pipeline pondere
  - couverture des next actions
  - wins recentes
  - pression des queues
  - top `attention_items`

### UI / UX

- ouverture des cartes `sales inbox` et `manager dashboard` via le contrat `crm_links`
- compactage desktop de `sales inbox` et `manager dashboard` pour reduire la hauteur de page
- realignement des filtres `My next actions`
- suppression des badges redondants dans `sales inbox`

### Modules / forfaits

- `my next actions`
- `sales inbox`
- `manager dashboard`

Ces ecrans sont maintenant explicitement lies au module `sales`:

- routes protegees
- visibilite workspace hub alignee
- activation/desactivation super-admin alignee

### Limites

Aucune nouvelle limite specifique phase 6 n a ete ajoutee.

La couche revenue reutilise les limites existantes:

- `requests`
- `quotes`
- `jobs`
- `tasks`

## 3. Avantages

- pas de migration prematuree
  - on gagne une vraie lecture commerciale sans figer trop tot un schema `Opportunity`

- un seul contrat canonique
  - pipeline, inbox et dashboard lisent le meme objet projete

- navigation plus sure
  - `crm_links` evite les heuristiques UI differentes selon les pages

- meilleure priorisation manager
  - les dossiers en retard, sans prochaine action ou deja chiffrables ressortent immediatement

- rollout plus simple par forfait
  - les nouveaux ecrans suivent deja la logique `enable / disable` des autres modules

## 4. Launch seeder local

`LaunchSeeder` a ete enrichi pour fournir un dataset phase 6 deterministic en local.

### Date de reference recommandee

Utiliser cette date exacte pour relire les scenarios seedes:

- `2026-04-25T09:00:00-04:00`

Cette heure correspond au fuseau `America/Toronto`.

### Commande

```bash
php artisan db:seed --class=Database\\Seeders\\LaunchSeeder
```

### Comptes utiles

- `owner.services@example.com` / `password`
  - proprietaire du tenant service avec acces complet

- `sales.manager.services@example.com` / `password`
  - manager commercial seed avec `sales.manage`

- `member.services@example.com` / `password`
  - membre standard sans acces manager commercial

## 5. URLs de test

Pour isoler le dataset phase 6 seed, utiliser la recherche `CRM Phase 6`.

### My next actions

`/crm/my-next-actions?reference_time=2026-04-25T09:00:00-04:00&search=CRM%20Phase%206`

Attendu:

- mix `request_follow_up`
- mix `quote_follow_up`
- tasks due / overdue
- au moins une `sales_activity`

### Sales inbox

`/crm/sales-inbox?reference_time=2026-04-25T09:00:00-04:00&search=CRM%20Phase%206`

Attendu:

- `overdue`: 1
- `no_next_action`: 1
- `quoted`: 2
- `needs_quote`: 1
- `active`: 1

### Manager dashboard

`/crm/manager-dashboard?reference_time=2026-04-25T09:00:00-04:00&search=CRM%20Phase%206`

Attendu:

- opportunites ouvertes phase 6 visibles
- `weighted pipeline` avec mix `pipeline / best_case`
- `wins` avec 1 dossier gagne sur la periode
- `quote pull-through` lisible sur les dossiers avec devis
- `attention_items` aligne sur l ordre de la sales inbox

## 6. Scenarios seedes

Le seed phase 6 ajoute les cas suivants:

- `CRM Phase 6 - Overdue Quote Co`
  - opportunite quotee ouverte avec relance en retard

- `CRM Phase 6 - No Next Action Co`
  - opportunite contactee sans prochaine action

- `CRM Phase 6 - Quoted Queue Co`
  - opportunite request-backed quotee avec prochaine action planifiee

- `CRM Phase 6 - Needs Quote Co`
  - opportunite qualifiee sans devis

- `CRM Phase 6 - Active Follow-up Co`
  - opportunite contactee avec prochaine action active

- `CRM Phase 6 - Quote Only Co`
  - opportunite `quote-only` sans `Request`

- `CRM Phase 6 - Closed Won Co`
  - opportunite gagnee avec `Work` et `Invoice` en aval

- `CRM Phase 6 - Closed Lost Co`
  - opportunite perdue avec devis decline

- `CRM Phase 6 - Activity Co`
  - activite commerciale explicite pour `My next actions`

## 7. Notes d usage

- ce dataset sert au test local et a la regression fonctionnelle
- il ne remplace pas le provisioning `Demo Workspaces`
- pour un reset plateforme minimal, garder `php artisan app:launch-reset --force`
- pour relire le comportement phase 6, preferer `LaunchSeeder` avec la date de reference fixe du 25 avril 2026
