# Campaigns Admin Guide

Derniere mise a jour: 2026-03-05

## 1) Activer le module
Pre-requis tenant:
- feature `campaigns` active
- configuration disponible dans `Settings > Marketing`

## 2) Templates par defaut
Les templates starter (EMAIL/SMS/IN_APP, FR/EN, tous campaign types) sont auto-crees:
- a l inscription owner
- au bootstrap local minimal (`LaunchResetSeeder`) pour le socle plateforme
- au provisioning d un tenant demo via `Demo Workspaces`

Admin peut ensuite:
- editer les templates
- changer les defaults par type/canal/langue

## 3) Mailing Lists
Acces:
- `Settings > Marketing > Mailing Lists`

Operations:
- creer une liste statique
- ajouter des clients (selection/import/paste)
- retirer des clients
- reutiliser la liste dans l audience d une campagne

Bonnes pratiques:
- garder une liste "blacklist" pour exclusions frequentes
- nommer les listes avec convention claire (`vip_local`, `staff_exclude`, `newsletter_q2`)

## 4) VIP Customers
Acces:
- `Settings > Marketing > VIP`
- fiche client pour marquer VIP et choisir le tier

Operations:
- creer des tiers (`SILVER`, `GOLD`, `PLATINUM`)
- definir perks textuels
- activer/desactiver un tier

Utilisation marketing:
- cibler `is_vip = true`
- filtrer par `vip_tier_id` ou `vip_tier_code`

Auto-VIP par achats:
- configurer dans `Settings > Marketing > VIP automation`
- definir:
  - fenetre d evaluation (jours)
  - depense minimale
  - nombre minimal de commandes payees
  - tier par defaut optionnel (code)
  - downgrade auto optionnel
  - regles par tier (V2):
    - `tier_code` (SILVER/GOLD/PLATINUM...)
    - seuils montant/commandes
    - fenetre (jours)
    - priorite (la plus haute gagne)
- execution:
  - automatique via scheduler (daily)
  - manuel via `php artisan campaigns:vip-auto-sync --account_id={tenantId}`
  - simulation sans ecriture: `--dry-run`

Comportement:
- client atteint les seuils -> devient VIP automatiquement
- si tier par defaut renseigne -> assignation du tier (si client n en a pas deja, ou selon parametre preserve)
- si downgrade actif -> retrait VIP quand seuils non atteints
- en V2, si des regles tier existent, elles priment sur le mode global

## 5) Audience logic
Par defaut:
- `UNION` des sources (segment/builder + mailing lists + manuel)
- exclusions appliquees ensuite

Mode avance:
- `INTERSECT` entre dynamique et mailing lists, puis union manuel

## 6) Dashboard KPI
Widgets marketing affiches si donnees disponibles:
- campaigns sent
- delivery success rate
- click rate (si tracking actif)
- conversions attributed
- top campaign
- audience growth
- VIP count
- mailing lists count/size

## 7) Delivery controls (A/B, holdout, fallback)
Dans le wizard campagne:
- Step Message:
  - activer A/B par canal
  - definir split `% variant A` (variant B = complement)
  - surcharger templates A/B (sinon fallback sur template canal de base)
- Step Review:
  - holdout group (% des recipients exclus volontairement)
  - fallback de canal:
    - map par canal source (ex: `SMS -> EMAIL`)
    - profondeur max (anti-boucle)

Comportement runtime:
- allocation A/B deterministic par recipient (stable pour un run donne)
- holdout applique avant envoi
- fallback uniquement apres echec provider, avec re-check consent/fatigue

## 8) Provider and compliance notes
Channels:
- EMAIL/SMS/IN_APP par tenant

Respect compliance:
- verifier consent explicite avant envoi
- configurer STOP keywords SMS
- activer quiet hours par timezone tenant
- maintenir des regles anti-fatigue (globales + VIP si necessaire)
- utiliser test send avant envoi massif

## 9) Permissions minimales
Owner:
- acces complet configuration + envoi

Team member:
- lecture: `campaigns.view`
- execution: `campaigns.send`
- gestion: `campaigns.manage`

## 10) Segments: a quoi ca sert et comment optimiser
Definition:
- un segment est une audience dynamique sauvegardee
- il stocke une logique de filtres, pas une liste figee de personnes

Quand utiliser un segment:
- campagnes recurrentes (newsletter, winback, relance quote)
- ciblage comportemental (depense, frequence, inactivite)
- ciblage VIP ou tiers VIP

Creation recommandee:
1. partir de l intention business (ex: recuperer clients inactifs 90j)
2. ajouter 1-3 filtres principaux
3. ajouter exclusions necessaires
4. lancer preview count
5. sauvegarder avec nom explicite + tags

Exemples:
- `winback_90d`: `last_activity_days >= 90` AND `total_spend > 0`
- `vip_gold_platinum`: `is_vip = true` AND `vip_tier_code in [GOLD, PLATINUM]`
- `cross_sell_accessories`: achat categorie A AND NOT achat categorie B

Optimisation:
- eviter les regles inutiles et OR trop imbriques
- preferer des filtres stables et indexables (ids, status, flags)
- combiner segment (dynamique) + mailing list (statique) pour un ciblage fin
- surveiller l evolution des comptes (`cached_count`) apres chaque changement
