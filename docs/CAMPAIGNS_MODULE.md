# Campaigns Module (Commercial Outreach / Sales Campaigns)

Derniere mise a jour: 2026-03-05

## 1) Scope metier
Le module campagnes est tenant-scoped et fonctionne pour:
- entreprises produits
- entreprises services
- entreprises hybrides

Un **Offer** est une abstraction unifiee:
- `offer_type=product` -> `products.item_type=product`
- `offer_type=service` -> `products.item_type=service`

Une campagne peut cibler 1..N offers via `campaign_offers`.

## 2) Valeurs stables (backend enums)
- `CampaignType`: `NEW_OFFER`, `BACK_AVAILABLE`, `PROMOTION`, `CROSS_SELL`, `WINBACK`, `ANNOUNCEMENT`
- `CampaignChannel`: `EMAIL`, `SMS`, `IN_APP` (`WHATSAPP` reserve/experimental)
- `OfferType`: `product`, `service`
- `CampaignOfferMode`: `PRODUCTS`, `SERVICES`, `MIXED`
- `CampaignLanguageMode`: `PREFERRED`, `FR`, `EN`, `BOTH`
- `CampaignAudienceSourceLogic`: `UNION`, `INTERSECT`

Expose via:
- `GET /api/v1/marketing/meta`

## 3) Segments (dynamic tenant data)
Entity: `audience_segments`
- `name`, `description`, `filters` JSON, `exclusions` JSON, `tags`
- `last_computed_at`, `cached_count`

Fonctions:
- sauvegarde d audience reusable
- support groupes AND/OR et exclusions
- comptage eligibilite par canal (consent + destination + fatigue)

Comment construire un segment utile:
1. definir l objectif marketing (reactivation, upsell, annonce, retention)
2. commencer avec 1 a 3 regles fortes (ex: `total_spend`, `last_activity_days`, `is_vip`)
3. ajouter les exclusions (blacklist, deja contactes, staff)
4. valider le volume avec preview count
5. sauvegarder avec un nom actionnable (`winback_90d_low_spend`, `vip_gold_q2`)

Patterns recommandes:
- Winback: `last_activity_days >= 90` AND `total_spend > 0`
- Cross-sell: `purchased_category_id = X` AND NOT `purchased_category_id = Y`
- VIP nurture: `is_vip = true` AND `vip_tier_code in [GOLD, PLATINUM]`
- Re-engagement service: `booking_frequency_per_month <= 1` AND `has_phone = true`

Optimisation segments (perf + qualite):
- preferer des regles indexables (`is_vip`, `vip_tier_id`, `created_at`, IDs)
- limiter les groupes imbriques OR profonds
- utiliser les segments comme base, puis combiner avec mailing lists pour micro-cibles
- monitorer `cached_count`: si variation brutale, verifier les regles
- garder une convention de nommage par intention + fenetre temporelle
- reutiliser les templates par `campaign_type` pour limiter les edits manuels

API:
- `GET/POST/PUT/DELETE /api/v1/marketing/segments*`
- `POST /api/v1/marketing/segments/preview-count`
- `GET /api/v1/marketing/segments/{segment}/count`

## 4) Mailing Lists (static tenant data)
Entity: `mailing_lists`
- `user_id`, `name`, `description`, `tags`, `created_by_user_id`, `updated_by_user_id`

Entity: `mailing_list_customers`
- `mailing_list_id`, `customer_id`, `added_by_user_id`, `added_at`

Concept:
- **Segment** = audience dynamique (filtres sauvegardes)
- **Mailing List** = audience statique (liste manuelle)

Fonctions:
- CRUD listes
- import bulk (IDs, emails, phones, paste)
- suppression selective des clients de liste
- comptage eligibilite par canal

API:
- `GET/POST/PUT/DELETE /marketing/mailing-lists*`
- `POST /marketing/mailing-lists/{mailingList}/import`
- `POST /marketing/mailing-lists/{mailingList}/sync-customers`
- `POST /marketing/mailing-lists/{mailingList}/remove-customers`
- `GET /marketing/mailing-lists/{mailingList}/count`

## 5) VIP Customers
Entity: `vip_tiers`
- `user_id`, `code`, `name`, `perks`, `is_active`

Customer fields:
- `customers.is_vip`
- `customers.vip_tier_id`
- `customers.vip_tier_code`
- `customers.vip_since_at`

Fonctions:
- CRUD tiers VIP
- assignation VIP dans fiche client
- automation VIP basee sur achats payes (seuil montant + nb commandes + fenetre jours)
- filtres audience: `is_vip`, `vip_tier_id`, `vip_tier_code`
- anti-fatigue VIP optionnel via `marketing_settings.channels.anti_fatigue`

Configuration automation (tenant):
- `marketing_settings.vip.automation.enabled`
- `evaluation_window_days`
- `minimum_total_spend`
- `minimum_paid_orders`
- `default_tier_code` (optionnel)
- `preserve_existing_tier`
- `downgrade_when_not_eligible`
- `excluded_customer_ids`
- `tier_rules[]` (V2):
  - `tier_code`
  - `minimum_total_spend`
  - `minimum_paid_orders`
  - `evaluation_window_days`
  - `priority`

Mode V2 tiers:
- si `tier_rules` actifs, le moteur assigne le meilleur tier selon priorite
- si plusieurs regles matchent, la plus prioritaire gagne
- si aucune regle active n est definie, fallback sur les seuils globaux (`minimum_total_spend`, `minimum_paid_orders`)

Execution automation:
- manuel: `php artisan campaigns:vip-auto-sync --account_id={tenantId}`
- dry-run: `php artisan campaigns:vip-auto-sync --account_id={tenantId} --dry-run`
- planifie: tous les jours a `02:35` (scheduler)

API:
- `GET/POST/PUT/DELETE /marketing/vip-tiers*`
- `PATCH /marketing/customers/{customer}/vip`

## 6) Templates multicanal
Entity: `message_templates`
- `name`, `channel`, `campaign_type?`, `language?`, `content` JSON, `is_default`, `tags`
- audit par `created_by_user_id` / `updated_by_user_id`

Comportement:
- defaults resolus par priorite:
  1. `(campaign_type + channel + language)`
  2. `(campaign_type + channel + null language)`
  3. `(null campaign_type + channel + language)`
  4. `(null campaign_type + channel + null language)`
- une campagne peut lier un template par canal (`campaign_channels.message_template_id`)
- snapshot rendu envoi dans `campaign_messages.payload.template_snapshot`

API:
- `GET/POST/PUT/DELETE /api/v1/marketing/templates*`
- `POST /api/v1/marketing/templates/preview`
- `POST /api/v1/marketing/templates/{template}/preview`

## 7) Templates par defaut dans LauncherSeeder
Strategie implementee:
- **Approach A**: copie de templates par tenant a la creation (editable localement)

Implementation:
- `TemplateSeederService::seedDefaultsForTenant()`
- integration dans:
  - `LaunchSeeder` (boot local multi-tenants)
  - `AppServiceProvider` sur event `Registered` pour nouveaux owners

Coverage seeded:
- Channels: `EMAIL`, `SMS`, `IN_APP`
- Campaign types: `NEW_OFFER`, `BACK_AVAILABLE`, `PROMOTION`, `CROSS_SELL`, `WINBACK`, `ANNOUNCEMENT`
- Languages: `FR`, `EN`
- `is_default=true` par combinaison `(campaign_type + channel + language)`

Idempotency:
- `updateOrCreate` + normalisation `is_default` par combinaison
- rerun safe sans duplication

## 8) Offer selector scalable (1k+ offers)
API recherche:
- `GET /api/v1/offers/search`
- params: `q,type,sort,cursor,limit,category/category_id,status,availability,price_min,price_max,tags[]`
- tri: `relevance`, `newest`, `best_sellers`, `alphabetical`
- pagination: cursor-based

Reponse:
- `items[{id,type,name,price,status,availability,thumbnailUrl,categoryName,sku,serviceCode,tags}]`
- `nextCursor`

## 9) Marketing configuration (tenant)
Entity: `marketing_settings`
- `channels` (enablement, provider, quiet hours, anti-fatigue, anti-fatigue VIP)
- `consent` (require_explicit, default_behavior, STOP keywords)
- `audience` (default exclusions, source logic default)
- `templates`
- `tracking` (click tracking + conversion mapping)
- `offers` (allowed modes + search defaults + strategy)

UI/Routes:
- `GET/PUT /settings/marketing`
- `GET/PUT /api/v1/settings/marketing`

## 10) Campaign wizard (5 etapes)
1. Setup:
   - name, campaign_type, offer_mode, language_mode
   - offer selection via OfferSelector
2. Audience:
   - segment load + mailing lists + manual ids/contacts
   - logique source: `UNION` ou `INTERSECT`
   - estimate per channel
3. Message:
   - templates par canal + override
   - A/B testing par canal (`channels[*].metadata.ab_testing`) avec split deterministic au dispatch
   - unified tokens `{offerName}`, `{offerPrice}`, `{offerUrl}`, `{offerImageUrl}`, `{offerAvailability}`
4. Review & Send:
   - compliance summary
   - holdout group (`settings.holdout.enabled/percent`)
   - channel fallback (`settings.channel_fallback.enabled/max_depth/map`)
   - live preview / test send / send now
5. Results:
   - lien details campagne/run export

## 11) Static vs Dynamic targeting
Sets:
- `A`: audience dynamique (builder/segment)
- `B`: clients issus des mailing lists incluses
- `C`: clients manuels
- `E`: clients des mailing lists exclues

Resolution:
- mode `UNION`: `(A union B union C) - E`
- mode `INTERSECT`: `((A intersect B) union C) - E`

Toujours:
- application consent + fatigue + dedupe destination
- snapshot recipients persiste dans `campaign_recipients`

## 12) Dashboard KPI marketing
Backend:
- `DashboardKpiService` + cache court tenant/range
- endpoint: `GET /marketing/dashboard/kpis` (+ `/api/v1/marketing/dashboard/kpis`)

KPIs exposes:
- campaigns sent
- delivery success rate
- click rate (si tracking actif)
- conversions attributed
- top performing campaign
- audience growth
- VIP count
- mailing lists count/size
- cross-module: reservations created, invoices paid, quotes accepted

Frontend:
- widgets integres dans `Dashboard.vue` et `DashboardProductsOwner.vue`
- affichage conditionnel si data dispo

## 13) Data model changes
Nouvelles tables:
- `message_templates`
- `marketing_settings`
- `campaign_offers`
- `mailing_lists`
- `mailing_list_customers`
- `vip_tiers`

Colonnes ajoutees:
- `campaigns.campaign_type`, `campaigns.offer_mode`, `campaigns.language_mode`
- `campaign_channels.message_template_id`, `campaign_channels.content_override`
- `audience_segments.description`, `tags`, `last_computed_at`, `cached_count`
- `campaign_audiences.include_mailing_list_ids`
- `campaign_audiences.exclude_mailing_list_ids`
- `campaign_audiences.source_logic`
- `campaign_audiences.source_summary`
- `customers.is_vip`, `customers.vip_tier_id`, `customers.vip_tier_code`, `customers.vip_since_at`
- `products.tags` + indexes offer-search

Migrations:
- `2026_03_05_000001_upgrade_campaigns_marketing_foundations.php`
- `2026_03_05_000002_add_mailing_lists_vip_and_audience_source_logic.php`

## 14) Sending pipeline / jobs
- `CampaignService::queueRun` -> `DispatchCampaignRunJob`
- `DispatchCampaignRunJob` resolve audience + queue recipients
  - assigne variant A/B par recipient (bucket deterministic)
  - applique holdout group avant queue send
- `SendCampaignRecipientJob` render + provider send + tracking
  - utilise le template variant A/B si actif
  - en echec provider, peut lancer un fallback de canal (consent/fatigue/depth/loop-safe)
- idempotency: `campaign_runs.idempotency_key` + dedupe recipient hashes
- throttling/compliance: `ConsentService` + `FatigueLimiter` + quiet hours

## 15) Snapshot strategy (category/tag selection MVP)
Decision MVP:
- `selection_strategy = snapshot_on_save`
- category/tag selectors sont resolus en IDs offers au moment `saveCampaign`
- tradeoff:
  - avantage: audit reproductible, run stable
  - limite: nouveaux offers ajoutes apres save non inclus automatiquement

## 16) Tests cibles
Tests couverts:
- offer search pagination cursor
- segment save/load/count
- template default resolution
- consent + fatigue enforcement
- queued sending workflow
- AB assignment metadata + run summary preservation
- channel fallback queueing on provider failure
- mailing list CRUD/import/remove
- VIP tier assignment + audience filtering
- dashboard KPI endpoint
- template seeder idempotency

Fichier tests:
- `tests/Feature/CampaignsMarketingModuleTest.php`
