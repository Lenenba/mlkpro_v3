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

API:
- `GET/POST/PUT/DELETE /api/v1/marketing/segments*`
- `POST /api/v1/marketing/segments/preview-count`
- `GET /api/v1/marketing/segments/{segment}/count`

## 4) Templates multicanal
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

## 5) Offer selector scalable (1k+ offers)
API recherche:
- `GET /api/v1/offers/search`
- params: `q,type,sort,cursor,limit,category/category_id,status,availability,price_min,price_max,tags[]`
- tri: `relevance`, `newest`, `best_sellers`, `alphabetical`
- pagination: cursor-based

Reponse:
- `items[{id,type,name,price,status,availability,thumbnailUrl,categoryName,sku,serviceCode,tags}]`
- `nextCursor`

## 6) Marketing configuration (tenant)
Entity: `marketing_settings`
- `channels` (enablement, provider, quiet hours, anti-fatigue)
- `consent` (require_explicit, default_behavior, STOP keywords)
- `audience` (default exclusions)
- `templates`
- `tracking` (click tracking + conversion mapping)
- `offers` (allowed modes + search defaults + strategy)

UI/Routes:
- `GET/PUT /settings/marketing`
- `GET/PUT /api/v1/settings/marketing`

## 7) Campaign wizard (5 etapes)
1. Setup:
   - name, campaign_type, offer_mode, language_mode
   - offer selection via OfferSelector
2. Audience:
   - segment load + manual ids/contacts
   - estimate per channel
3. Message:
   - templates par canal + override
   - unified tokens `{offerName}`, `{offerPrice}`, `{offerUrl}`, `{offerImageUrl}`, `{offerAvailability}`
4. Review & Send:
   - compliance summary
   - live preview / test send / send now
5. Results:
   - lien details campagne/run export

## 8) Data model changes
Nouvelles tables:
- `message_templates`
- `marketing_settings`
- `campaign_offers`

Colonnes ajoutees:
- `campaigns.campaign_type`, `campaigns.offer_mode`, `campaigns.language_mode`
- `campaign_channels.message_template_id`, `campaign_channels.content_override`
- `audience_segments.description`, `tags`, `last_computed_at`, `cached_count`
- `products.tags` + indexes offer-search

Migration:
- `2026_03_05_000001_upgrade_campaigns_marketing_foundations.php`

## 9) Sending pipeline / jobs
- `CampaignService::queueRun` -> `DispatchCampaignRunJob`
- `DispatchCampaignRunJob` resolve audience + queue recipients
- `SendCampaignRecipientJob` render + provider send + tracking
- idempotency: `campaign_runs.idempotency_key` + dedupe recipient hashes
- throttling/compliance: `ConsentService` + `FatigueLimiter` + quiet hours

## 10) Snapshot strategy (category/tag selection MVP)
Decision MVP:
- `selection_strategy = snapshot_on_save`
- category/tag selectors sont resolus en IDs offers au moment `saveCampaign`
- tradeoff:
  - avantage: audit reproductible, run stable
  - limite: nouveaux offers ajoutes apres save non inclus automatiquement

## 11) QA minimum
Tests couverts:
- offer search pagination cursor
- segment save/load/count
- template default resolution
- consent + fatigue enforcement
- queued sending workflow (dispatch + recipient jobs)

Fichier tests:
- `tests/Feature/CampaignsMarketingModuleTest.php`

