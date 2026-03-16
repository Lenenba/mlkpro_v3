# Campaigns Prospecting -> Lead Generation - User Story

Derniere mise a jour: 2026-03-15

## Goal
Etendre le module `campaigns` pour qu il puisse non seulement envoyer des campagnes a des audiences existantes, mais aussi generer de nouveaux leads a partir de campagnes de prospection structurees.

Cette evolution doit permettre de traiter des batches de prospects externes, de les scorer, de les suivre dans le temps, puis de les convertir en `leads` lorsqu un signal d interet reel apparait.

## Product Vision
Le module `campaigns` devient le point d entree pour deux moteurs complementaires:
- **inbound**: une campagne attire un prospect via landing page / formulaire / lien tracke et genere un lead attribue
- **outbound**: une campagne travaille une liste de prospects externes jusqu a ce qu une partie d entre eux devienne des leads

Le pipeline cible est:

`campaign -> prospect -> lead -> customer`

## Why This Matters
- Un `lead` ne doit pas etre pollue par des contacts froids non qualifies
- Un `customer` reste un contact deja converti ou actif
- La prospection externe a besoin d un cycle propre: sourcing, qualification, outreach, relances, qualification commerciale, conversion
- Le module `campaigns` est deja le meilleur endroit pour piloter l acquisition et l attribution business

## Non-goals
- injecter toutes les personnes trouvees sur le web directement dans `leads`
- remplacer le module `leads` par un pipeline de prospection
- autoriser en V1 un scraping non gouverne des moteurs de recherche
- contacter des personnes deja bloquees, desinscrites, ou non eligibles selon les regles de conformite du tenant
- casser les campagnes actuelles basees sur `customers` et `manual_contacts`

## Current Baseline In This Repo
- `campaigns` sait deja envoyer a des `customers` et a des `manual_contacts`
- `campaigns` sait deja suivre clicks / conversions / unsubscribe
- `leads` existe deja comme pipeline de qualification et de suivi commercial
- le tracking public sait deja remonter les UTM et attribuer une source marketing a un lead

## Core Product Decision
La capacite a ajouter doit vivre en priorite dans `campaigns`, sous la forme d une couche **prospecting**, et non pas dans `leads`.

Le role de chaque module devient:
- `campaigns`: acquisition, sourcing, batch analysis, outreach, relances, attribution
- `prospecting`: qualification de contacts froids ou semi-chauds avant creation d un lead
- `leads`: prise en charge commerciale d un contact ayant montre un interet reel
- `customers`: conversion finale et relation active

## Core Entities

### 1) Prospect
Un prospect est une personne ou entreprise cible qui n est pas encore consideree comme `lead`.

Champs metier minimum:
- `user_id`
- `campaign_id` ou `source_campaign_id`
- `source_type` (`csv`, `connector`, `landing_page`, `directory_api`, `manual`, `ads`, `import`)
- `source_reference`
- `company_name`
- `contact_name`
- `email`
- `phone`
- `website`
- `city`
- `country`
- `industry`
- `tags`
- `fit_score`
- `intent_score`
- `priority_score`
- `qualification_summary`
- `status`
- `owner_notes`
- `do_not_contact`
- `converted_to_lead_id`
- `converted_to_customer_id`

### 2) Prospect Activity
Historique par prospect:
- analyse batch
- score calcule
- envoi campagne
- ouverture
- clic
- reponse
- appel planifie
- relance planifiee
- qualification manuelle
- conversion en lead
- conversion en customer
- rejet / blocage

### 3) Prospect Batch
Objet de pilotage pour traiter les contacts par vagues.

Champs minimum:
- `campaign_id`
- `batch_number`
- `source_type`
- `input_count`
- `accepted_count`
- `rejected_count`
- `duplicate_count`
- `scored_count`
- `contacted_count`
- `lead_count`
- `customer_count`
- `status`

## Business Rules

### Rule 1 - Un prospect n est pas un lead
- Un contact externe froid reste un `prospect` tant qu il n a pas manifeste un signal d interet suffisant.
- Le `lead` est cree uniquement apres qualification ou evenement declencheur.

### Rule 2 - Une campagne peut generer des leads dans 2 modes
- **Inbound**: la campagne pousse vers une page ou un formulaire et cree un lead attribue a la campagne
- **Outbound**: la campagne contacte un prospect et le convertit en lead lorsqu il repond, clique avec interet fort, reserve un appel, ou est qualifie manuellement

### Rule 3 - Batch processing structure
- Le systeme doit permettre un traitement par batch de `100` prospects par defaut
- Le batch de 100 est la taille de travail recommandee en V1 pour:
  - enrichissement
  - scoring
  - validation operateur
  - lancement de relances
- Le batch doit rester parametrable par tenant ou campagne plus tard, sans casser le default `100`

### Rule 4 - Dedupe obligatoire
Avant activation d un prospect:
- verifier doublon sur `customers`
- verifier doublon sur `leads`
- verifier doublon sur `prospects` existants
- verifier blocage / opt-out / do_not_contact

Le dedupe doit au minimum s appuyer sur:
- email normalise
- telephone normalise
- nom entreprise + domaine web si disponible

### Rule 5 - Conversion to lead
Un prospect devient `lead` quand au moins une de ces conditions est vraie:
- il remplit un formulaire
- il demande un appel
- il repond positivement a une campagne
- il clique et effectue une action d engagement forte
- il est qualifie manuellement par un membre de l equipe

### Rule 6 - Attribution preserved
Quand un prospect devient `lead`, le systeme doit conserver:
- campagne source
- batch source
- canal source
- date du premier contact
- date de conversion en lead
- score au moment de la conversion

### Rule 7 - Compliance and contactability
- Aucun envoi ne doit contourner les regles de desinscription et de suppression
- Chaque prospect doit pouvoir etre marque `do_not_contact`
- Le tenant doit pouvoir distinguer:
  - `contactable`
  - `blocked`
  - `unknown consent`
  - `opted_out`
  - `manual review required`

### Rule 8 - Human review for cold outreach
- En V1, le premier envoi massif a des prospects froids doit pouvoir passer par une validation humaine
- Les campagnes existantes vers clients restent sur leur flow normal

## Primary User Story

### US-CMP-PROS-001 - Prospecting campaign that generates leads
As a sales or marketing owner, I can run a prospecting campaign on batches of external contacts so I can identify the best matches, contact them in a controlled way, and convert the interested ones into leads and then customers.

Acceptance criteria:
- A campaign can be marked as `prospecting_enabled`
- A campaign can own one or more prospect batches
- Each batch can process `100` prospects by default
- Each prospect receives a score and a follow-up status
- Only qualified or engaged prospects are converted into `leads`
- Campaign reporting exposes `prospects -> leads -> customers`

## Supporting User Stories

### US-CMP-PROS-002 - Prospect source intake
As a marketer, I can add prospects to a campaign from approved sources so that campaign generation starts from real target data.

Acceptance criteria:
- The campaign accepts prospect intake from:
  - CSV import
  - manual entry
  - approved connectors
  - landing pages / tracked forms
  - licensed directory or external data providers
- Each imported record stores source provenance
- Unsupported or non-compliant sources can be rejected at intake time

### US-CMP-PROS-003 - Batch analysis of 100 prospects
As an operator, I can analyze prospects in batches of 100 so that qualification remains readable, reviewable, and operationally safe.

Acceptance criteria:
- Default batch size is `100`
- A batch computes:
  - accepted prospects
  - duplicates
  - blocked contacts
  - scored contacts
  - high priority contacts
- Batch results can be reviewed before outreach
- Each prospect stores why it was accepted, rejected, or deprioritized

### US-CMP-PROS-004 - Prospect scoring
As a marketer, I want the system to score each prospect against what we sell so I can prioritize the best-fit contacts first.

Acceptance criteria:
- The score can use criteria such as:
  - geography
  - industry
  - company size if known
  - offer fit
  - service need indicators
  - website / form / source signals
- The scoring result includes:
  - `fit_score`
  - `intent_score`
  - `priority_score`
  - short explanation summary
- Prospects can be filtered by score before launch

### US-CMP-PROS-005 - Dedupe and suppression
As the system, I must block obvious duplicates and protected contacts so the tenant does not waste sends or damage data quality.

Acceptance criteria:
- A prospect already present in `customers` is flagged before outreach
- A prospect already present in `leads` is flagged before outreach
- A prospect already present in `prospects` is merged or blocked according to tenant rules
- A blocked or opted-out destination cannot enter a sendable batch

### US-CMP-PROS-006 - Outreach sequencing
As a marketer, I can send and follow up with qualified prospects through campaign sequences so that lead generation continues beyond the first touch.

Acceptance criteria:
- A prospecting campaign can support:
  - first contact
  - follow-up 1
  - follow-up 2
  - stop conditions
- Follow-up is skipped if the prospect is:
  - converted to lead
  - opted out
  - marked do_not_contact
  - manually closed
- Timeline stores all outreach attempts per prospect

### US-CMP-PROS-007 - Prospect to lead conversion
As a sales team member, I want an engaged prospect to become a lead without losing campaign context.

Acceptance criteria:
- Conversion creates a new `lead` or links to an existing lead if dedupe matches
- The new lead stores:
  - source campaign
  - source batch
  - source channel
  - original prospect id
  - attribution metadata
- Prospect status becomes `converted_to_lead`
- Lead timeline shows campaign origin

### US-CMP-PROS-008 - Inbound attribution from campaign to lead
As an owner, I want a lead created by a campaign landing page or tracked CTA to be automatically attributed to the campaign that generated it.

Acceptance criteria:
- Public forms and landing flows can attach `campaign_id` and recipient attribution when available
- Lead creation preserves `utm_source`, `utm_medium`, `utm_campaign`, and campaign context
- Reporting can distinguish inbound generated leads from outbound generated leads

### US-CMP-PROS-009 - Funnel reporting
As an owner, I want to see the real business funnel per campaign so I can know which campaigns create pipeline and customers.

Acceptance criteria:
- Campaign results show:
  - prospects total
  - prospects contacted
  - replies
  - qualified prospects
  - leads generated
  - customers generated
  - conversion rates between each stage
- Reporting can be filtered by:
  - source type
  - batch
  - channel
  - score range

### US-CMP-PROS-010 - Manual qualification workspace
As a sales operator, I want to review prospects before or after outreach so I can decide who should be worked, paused, or promoted to lead.

Acceptance criteria:
- A user can:
  - approve prospect
  - reject prospect
  - mark do_not_contact
  - add qualification notes
  - promote to lead
- The action is auditable
- Bulk actions are available at least for review and rejection

## Prospect Status Model
- `new`
- `enriched`
- `scored`
- `approved`
- `contacted`
- `follow_up_due`
- `replied`
- `qualified`
- `converted_to_lead`
- `converted_to_customer`
- `duplicate`
- `blocked`
- `disqualified`
- `do_not_contact`

## Lead Creation Triggers
A `prospect` can be promoted to `lead` from:
- positive reply
- call request
- demo request
- qualified click with form completion
- manual operator validation
- inbound form submission directly tied to campaign attribution

## Suggested Product Flow

### Flow A - Outbound prospecting
1. User creates a campaign and enables `prospecting`
2. User imports or syncs a batch of 100 prospects
3. System dedupes and scores the batch
4. User reviews the batch and approves outreach
5. Campaign sends first touch to eligible prospects
6. System tracks opens, clicks, replies, unsubscribe, and failures
7. Follow-up engine schedules next actions
8. Engaged prospects are converted to leads
9. Sales team works the leads until customer conversion

### Flow B - Inbound lead generation
1. User launches a campaign with CTA to landing page or form
2. Visitor arrives with tracked campaign attribution
3. Visitor submits a request
4. System creates a lead attributed to the source campaign
5. Reporting updates campaign generated leads metrics

## Given / When / Then

### A) Batch qualification
- Given a campaign has prospecting enabled
- When the user imports 100 external contacts
- Then the system creates one prospect batch, dedupes each contact, scores each one, and returns a reviewable qualification summary

- Given a contact already exists in customers or leads
- When the batch analysis runs
- Then the contact is flagged as duplicate or linked instead of becoming a new active prospect

### B) Outreach and follow-up
- Given a reviewed batch contains approved prospects
- When the campaign outreach starts
- Then only eligible, non-blocked, non-opted-out prospects are contacted

- Given a prospect has already replied or is marked do_not_contact
- When a scheduled follow-up is due
- Then no new outreach is sent

### C) Prospect to lead conversion
- Given a prospect replies positively or is manually qualified
- When the operator confirms qualification or the defined signal threshold is met
- Then the system creates or links a lead, preserves campaign attribution, and updates the prospect status to `converted_to_lead`

### D) Campaign generated inbound lead
- Given a visitor enters from a tracked campaign CTA
- When the visitor submits the public form
- Then the lead is created with campaign attribution and appears in campaign generated lead reporting

## Product Decisions

### Decision 1 - Prospecting belongs to campaigns
- This capability extends the acquisition role of `campaigns`
- The `leads` module remains the qualification and sales execution workspace

### Decision 2 - Keep the funnel explicit
- `prospect`, `lead`, and `customer` are 3 distinct lifecycle states
- Reporting must show conversions between them explicitly

### Decision 3 - Use safe source governance
- V1 should prefer approved connectors, imports, and attributed inbound flows
- Search-engine scraping or opaque source ingestion should not be the default operating model

### Decision 4 - Preserve tenant isolation and compliance
- suppression, do-not-contact, opt-out, and source provenance are mandatory
- all actions remain tenant-scoped and auditable

## Suggested Technical Scope

### Phase 1 - Prospect foundation
- add `prospects`
- add `prospect_batches`
- add `prospect_activities`
- link campaign to prospecting mode
- dedupe against customers and leads

Definition of done:
- a tenant can create prospect batches under a campaign
- the batch stores 100-contact reviewable results
- duplicates and blocked contacts are excluded safely

### Phase 2 - Scoring and review
- implement score model and qualification summary
- add manual review actions
- add batch and prospect filters

Definition of done:
- every prospect has reviewable score output
- operators can approve, reject, or pause prospects

### Phase 3 - Outreach and timeline
- connect approved prospects to campaign send flow
- store outreach attempts and responses
- stop follow-up on opt-out, reply, or manual close

Definition of done:
- prospecting campaign sends are traceable
- follow-up rules do not ignore suppression state

### Phase 4 - Conversion and attribution
- convert prospect to lead
- connect inbound campaign attribution to lead creation
- add funnel reporting

Definition of done:
- campaign to lead attribution is visible end-to-end
- prospect to lead conversion preserves source context

## Risks
- mixing cold prospects directly into leads will degrade CRM data quality
- weak dedupe rules will create duplicate leads and duplicate outreach
- non-governed source ingestion can create legal and deliverability exposure
- aggressive automation without review can damage sender reputation and tenant trust

## Success Metrics
- number of prospect batches processed
- percentage of accepted vs rejected prospects per batch
- outreach to reply rate
- prospect to lead conversion rate
- lead to customer conversion rate
- cost per generated lead by campaign
- attributed revenue or pipeline created by campaign

## Definition of Done
- campaigns can operate in prospecting mode without breaking existing customer campaigns
- prospects, batches, and prospect activities are available and tenant-scoped
- batch analysis of 100 works end-to-end
- dedupe with leads and customers is enforced
- prospect to lead conversion is implemented with attribution preserved
- inbound campaign attribution to lead creation is visible
- reporting exposes `prospects -> leads -> customers`
- suppression and compliance guards are present in the workflow
