# Plan strategique CRM competitif - Malikia Pro

Derniere mise a jour: 2026-04-20

## 1. Reponse claire

Oui, on est capables de le faire.

Mais pas en essayant de "copier HubSpot" d'un coup.

On est capables de construire un CRM tres competitif si on suit une trajectoire disciplinee:

1. renforcer ce qu'on fait deja bien
2. combler les trous qui ont le plus gros impact revenu
3. ajouter la couche "sales daily workflow" seulement apres avoir solidifie le coeur

Le bon objectif n'est pas:

- devenir en 1 sprint un clone de HubSpot ou Pipedrive

Le bon objectif est:

- devenir une plateforme CRM + operations tres forte pour entreprises de services
- puis monter progressivement la couche commerciale avancee

## 2. Pourquoi c'est faisable

Le codebase a deja plusieurs briques tres utiles:

- journal d'activite: `app/Models/ActivityLog.php`
- systeme de bulk actions: `app/Support/BulkActions/BulkActionRegistry.php`
- feature flags / gating par plan: `app/Services/CompanyFeatureService.php`
- moteur campagnes: `app/Services/Campaigns/CampaignService.php`
- moteur prospecting / scoring: `app/Services/Campaigns/CampaignProspectingService.php`
- module leads/requests deja structure
- module customer detail deja riche
- pipeline transversal `request -> quote -> job -> invoice`
- campagnes, segments, mailing lists, automations, consentement, anti-fatigue

Donc:

- il n'y a pas de blocage architectural majeur visible
- il y a deja une base de patterns reutilisables
- le produit est assez mature pour ajouter une vraie couche CRM superieure sans repartir de zero

## 3. Positionnement a viser

Avant de planifier, il faut choisir la cible.

La cible recommande est:

- CRM operationnel pour entreprises de services
- pipeline commercial lie au devis, au terrain, et au revenu
- alternative plus concrete aux outils qui gerent le lead mais cassent la suite

La cible a ne pas viser tout de suite:

- CRM "sales-first" universel pour SDR, AE, RevOps, sequence engine, forecast enterprise, et stack sales complete

En clair:

- d'abord battre les solutions SMB service mal reliees entre elles
- ensuite monter la couche commerciale plus generaliste

## 4. Principe directeur du plan

Le plan doit suivre cette logique:

### 4.1 Faire monter la conversion avant la complexite

On priorise:

- vitesse de reponse lead
- suivi devis
- prochaines actions visibles
- routines de relance

avant:

- inbox email complete
- sync calendrier
- forecasts complexes

### 4.2 Reutiliser l'existant au maximum

Chaque phase doit reposer sur les briques deja la:

- ActivityLog
- BulkActionRegistry
- Request board
- customer detail
- campaigns
- prospecting
- jobs, tasks, invoices

### 4.3 Eviter la dispersion

On ne lance pas en parallele:

- nouvelle inbox commerciale complete
- telephonie
- sync email
- deal object enterprise
- forecast

Il faut monter par couches.

### 4.4 La stabilite de la base prime sur tout

Regle non negociable:

- aucune phase CRM ne doit fragiliser le coeur du produit
- le flux `lead -> devis -> execution -> revenu` doit rester exploitable en permanence
- toute nouveaute doit etre livree de facon additive, reversible, et testable

En clair:

- on ne casse pas la base pour accelerer une roadmap
- on ne remplace pas brutalement les workflows existants
- on ne deplace pas les equipes sur une nouvelle UX sans filet de securite

## 5. Vision cible en fin de trajectoire

Si on execute bien, la version cible doit permettre:

1. de recevoir un lead et le trier immediatement par urgence et potentiel
2. de visualiser les leads en retard et les devis qui refroidissent
3. de lancer des playbooks de relance sans refaire la selection a la main
4. de garder les activites commerciales dans un journal lisible
5. de faire avancer une opportunite jusqu'au devis, au job, puis a la facture sans rupture de contexte
6. de donner au manager une vision claire du pipeline, des blocages, et des relances dues

## 6. Plan en 6 phases

## Phase 0 - Cadrage, schema, garde-fous

### Objectif

Aligner produit, data model, et ordre d'execution avant de coder les grosses couches.

### Livrables

1. schema cible des objets CRM
2. nomenclature claire:
   - `Lead`
   - `Request`
   - `Quote`
   - `Opportunity` ou non
   - `Next action`
   - `Follow-up`
3. choix de ce qu'on garde dans `Request` et de ce qu'on fera plus tard dans un objet `Opportunity`
4. definition des KPIs de succes
5. ordre officiel des releases

### Decision cle

Decision recommandee:

- ne pas introduire un objet `Opportunity` complexe au debut
- d'abord rendre `Request` beaucoup plus fort
- introduire `Opportunity` seulement quand la couche sales activity est stable

### Effort

- faible
- 2 a 5 jours de cadrage reel

### Document de reference

- `docs/PHASE_0_CRM_REQUEST_FIRST_CADRAGE_2026-04-20.md`

### Condition de sortie

- backlog priorise
- data model cible
- critere de succes par phase

## Phase 1 - Lead SLA Inbox and Smart Triage

### Objectif

Faire du module `Request` une vraie inbox commerciale exploitable tous les jours.

### Pourquoi cette phase passe en premier

Parce que c'est le meilleur ratio:

- valeur visible
- impact conversion
- reutilisation de l'existant

### Ce qu'on construit

1. vue `new / due soon / stale / breached`
2. priorisation automatique des leads
3. score de risque simple
4. assignation et reassignation plus visibles
5. widgets manager:
   - temps moyen avant premiere reponse
   - leads sans action
   - relances dues aujourd'hui
   - leads sans assignee
6. actions rapides:
   - assigner
   - appeler
   - planifier relance
   - convertir en devis
   - marquer perdu

### Evolution data recommandee

Ajouter ou stabiliser:

- `first_response_at`
- `last_activity_at`
- `sla_due_at`
- `triage_priority`
- `risk_level`
- `stale_since_at`

### UI recommandee

1. inbox priorisee
2. board plus orientee action
3. panneau lateral detail rapide

### Reutilisation de l'existant

- `RequestController`
- `RequestBoard.vue`
- `ActivityLog`

### KPIs de succes

- baisse du volume de leads sans action
- baisse du delai moyen de premiere reponse
- hausse du taux de conversion lead -> quote

### Effort

- moyen
- environ 2 a 4 semaines selon profondeur

### Document de reference

- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`

## Phase 2 - Quote Recovery and Conversion Cockpit

### Objectif

Transformer les devis envoyes en vrai moteur de recuperation revenu.

### Pourquoi cette phase est critique

Une grosse partie du revenu se perd entre:

- devis envoye
- devis vu
- devis relance
- devis accepte

### Ce qu'on construit

1. vue des devis en attente par anciennete
2. segmentation:
   - jamais relances
   - relance due
   - vus mais non acceptes
   - expires
   - a haut montant
3. actions rapides:
   - envoyer relance email
   - envoyer relance SMS
   - creer tache de suivi
   - planifier rappel
   - archiver
4. timeline de suivi du devis
5. score simple de probabilite ou de priorite

### Si possible en V1

Ajouter des signaux comme:

- `last_sent_at`
- `last_viewed_at`
- `follow_up_state`
- `follow_up_count`
- `quote_age_days`

### Reutilisation de l'existant

- module quotes
- email existant
- campaigns / bulk contact
- tasks

### KPIs de succes

- hausse du taux quote -> accepted
- baisse du volume de devis sans relance
- reduction du temps moyen entre envoi et prochaine action

### Effort

- moyen
- 2 a 4 semaines

## Phase 3 - Saved Segments and Scheduled Playbooks

### Objectif

Transformer les actions manuelles repetitives en routines.

### Pourquoi maintenant

Une fois le triage leads et la recuperation devis rendus visibles, il faut automatiser les routines les plus frequentes.

### Ce qu'on construit

1. segments sauvegardes sur:
   - `Request`
   - `Customer`
   - `Quote`
2. playbooks enregistrables:
   - relancer les devis > X jours
   - assigner les leads entrants d'une source
   - contacter clients inactifs
   - pousser les relances dues
3. execution:
   - manuelle
   - planifiee
4. audit:
   - selected
   - processed
   - success
   - failed
   - skipped

### Reutilisation de l'existant

- `BulkActionRegistry`
- segments campagnes
- moteur campagnes
- ActivityLog

### KPIs de succes

- baisse du temps manuel de pilotage
- hausse du nombre d'actions executees a temps
- adoption des routines recurrentes

### Effort

- moyen a fort
- 3 a 5 semaines

## Phase 4 - Sales Activity Layer

### Objectif

Ajouter une vraie couche quotidienne de suivi commercial sans encore construire une inbox email complete.

### Ce qu'on construit

1. objets ou structures d'activite:
   - note commerciale
   - appel
   - resultat d'appel
   - prochaine action
   - rendez-vous
2. timeline commerciale visible depuis:
   - lead
   - customer
   - quote
3. quick actions:
   - appel effectue
   - pas de reponse
   - rappel demain
   - devis discute
   - a recontacter
4. file "mes prochaines actions"

### Pourquoi cette phase est importante

Parce qu'elle donne une sensation de vrai CRM de vente avant meme d'avoir la couche email sync complete.

### Reutilisation de l'existant

- ActivityLog
- Customer detail activity
- Request detail
- pipeline transversal

### KPIs de succes

- hausse de l'activite loggee
- hausse des suivis dans les temps
- baisse des leads "silencieusement perdus"

### Effort

- moyen
- 3 a 5 semaines

## Phase 5 - Email and Calendar Foundations

### Objectif

Poser les fondations de la communication commerciale moderne.

### Important

Cette phase ne doit pas arriver trop tot.

Sinon on construit une couche technique lourde avant d'avoir clarifie le workflow produit.

### Ce qu'on construit

1. architecture integration email
2. liaison messages -> lead/customer/quote
3. event model pour:
   - email sent
   - email received
   - meeting scheduled
   - meeting completed
4. sync minimale calendrier
5. preparation future d'un sales inbox

### V1 recommandee

Commencer petit:

- journaliser les emails envoyes depuis le produit
- lier les rendez-vous internes aux fiches
- preparer les points d'accroche pour Gmail / Outlook plus tard

### Ce qu'il ne faut pas faire en V1

- essayer de livrer tout de suite une inbox enterprise complete
- promettre un clone de Gmail dans le CRM

### KPIs de succes

- activites de contact plus visibles
- moins de perte de contexte
- base stable pour phase 6

### Effort

- fort
- 4 a 8 semaines selon profondeur

## Phase 6 - Opportunity Layer, Sales Inbox, Forecast

### Objectif

Faire monter Malikia Pro d'un cran face aux CRM de vente plus purs.

### Cette phase ne doit commencer que si

1. le triage leads marche vraiment
2. le cockpit devis est utile
3. les playbooks sont adoptes
4. la sales activity layer est stable

### Ce qu'on peut construire ici

1. objet `Opportunity` si toujours pertinent
2. board de pipeline commercial plus generaliste
3. sales inbox
4. forecast simple
5. vues manager:
   - stage aging
   - weighted pipeline
   - next actions overdue
   - quote pull-through

### Attention

Cette phase est celle qui rapproche le plus du terrain HubSpot / Pipedrive.

Elle est faisable.

Mais elle est aussi la plus couteuse et la plus risquee si on la commence trop tot.

### Effort

- fort
- 6 a 10 semaines

## 7. Roadmap recommandee

## 7.1 Version 90 jours

Objectif:

- devenir nettement plus fort face aux SMB services

Scope recommande:

1. Phase 0
2. Phase 1
3. Phase 2
4. debut de Phase 3

Resultat attendu:

- meilleur triage lead
- meilleur suivi devis
- meilleur pilotage quotidien
- valeur commerciale beaucoup plus lisible

## 7.2 Version 6 mois

Scope recommande:

1. Phase 0
2. Phase 1
3. Phase 2
4. Phase 3
5. Phase 4

Resultat attendu:

- vrai CRM operationnel fort
- routines commerciales
- prochaines actions visibles
- meilleur pont entre leads, devis, clients et campagnes

## 7.3 Version 9 a 12 mois

Scope recommande:

- Phase 5
- Phase 6

Resultat attendu:

- couche commerciale plus mature
- positionnement plus credible contre certains CRM sales-first

## 8. Cadre de stabilite et anti-regression

Ce plan ne vaut que si la base reste stable.

La stabilite n'est pas une contrainte secondaire.

Elle est une condition de livraison.

### 8.1 Regle absolue

Aucune phase ne sort si elle degrade un des points suivants:

- creation et suivi des leads
- transformation en devis
- execution terrain / jobs / taches
- facturation et suivi revenu
- navigation quotidienne des equipes existantes

### 8.2 Regles d'implementation obligatoires

Chaque phase doit respecter ces regles:

1. livrer derriere feature flag quand le risque est non trivial
2. preferer des evolutions additives plutot qu'un remplacement brutal
3. ne jamais coupler une innovation CRM a une refonte massive de modules coeur
4. conserver un chemin utilisateur stable vers les actions critiques existantes
5. limiter les migrations destructives a une phase ulterieure, apres periode de stabilisation
6. prevoir un rollback simple: desactivation du flag, retour ancien read path, ou fallback UI

### 8.3 Regles data et migrations

Pour proteger la base:

- ajouter d'abord les nouvelles colonnes ou tables sans casser les lectures existantes
- backfiller avant de rendre les nouveaux champs obligatoires
- utiliser dual write ou dual read temporaire si un objet ou une source change
- ne supprimer un champ, une route, ou une branche de lecture qu'apres validation en production
- documenter les impacts schema avant chaque release sensible

### 8.4 Regles UX et workflow

Pour proteger les usages quotidiens:

- ne pas retirer un bouton critique sans alternative visible
- ne pas deplacer plusieurs actions coeur dans la meme release
- garder board, liste, details et actions rapides utilisables meme si la nouveaute est desactivee
- introduire les nouvelles vues comme couche complementaire avant d'en faire la vue par defaut

### 8.5 Regles de test minimales par phase

Le repo a deja une bonne base de verification continue:

- workflow `quality`
- suites `phpunit`
- smoke browser `Playwright`

Avant chaque sortie de phase, il faut au minimum:

1. garder `composer qa:test` au vert
2. garder `php artisan test` au vert sur le run MySQL
3. garder `npm run qa:build` au vert
4. garder `npm run qa:e2e` ou au minimum le smoke browser critique au vert
5. ajouter au moins un test de non-regression pour chaque comportement nouveau a risque
6. ajouter ou etendre un smoke test sur le parcours utilisateur touche

### 8.6 Gate de sortie commune a toutes les phases

Une phase n'est pas consideree comme "terminee" si un des points suivants manque:

1. tests applicatifs verts
2. smoke browser vert sur les parcours touches
3. migration reversible ou risque clairement borne
4. feature flag ou strategie de retour arriere documentee
5. old workflow encore fonctionnel
6. monitoring post-release defini

### 8.7 Monitoring post-release

Chaque release CRM doit etre observee sur:

- erreurs applicatives
- jobs et queues en echec
- temps de reponse des pages critiques
- taux de conversion lead -> quote
- taux quote -> accepted
- volume d'elements stale ou sans action
- signaux support ou tickets internes

### 8.8 Strategie de rollout recommandee

Le bon schema est:

1. activer en interne
2. activer sur un petit groupe ou une company pilote
3. verifier logs, conversions, et feedback terrain
4. elargir progressivement
5. seulement ensuite rendre plus visible dans l'UX par defaut

### 8.9 Application concrete a notre roadmap

Pour ce plan, cela veut dire:

- Phase 1 doit renforcer `Request`, pas le destabiliser
- Phase 2 doit reutiliser les devis existants avant de creer des objets supplementaires
- Phase 3 doit s'appuyer sur `BulkActionRegistry` et les campagnes plutot que multiplier les moteurs
- Phase 4 doit etendre `ActivityLog` avant de creer une couche d'activite trop separee
- Phases 5 et 6 ne doivent commencer que si les 4 premieres sont stables en usage reel

## 9. Ce qu'il faut faire tout de suite

Les 6 actions immediates recommandees sont:

1. verrouiller le cadrage Phase 0
2. choisir officiellement `Request-first` comme strategie de depart
3. lancer `Lead SLA Inbox and Smart Triage`
4. preparer en parallele le schema du `Quote Recovery Cockpit`
5. definir les KPIs de reference avant implementation
6. definir la checklist anti-regression obligatoire pour chaque release CRM

## 10. KPIs a suivre des le debut

### Leads

- temps moyen premiere reponse
- volume de leads stale
- taux lead -> quote
- taux lead -> won

### Quotes

- taux quote -> accepted
- devis sans relance
- temps moyen entre envoi et prochaine action

### Activite commerciale

- nombre d'actions loggees
- prochaines actions en retard
- taux de completion des follow-ups

### Revenue operations

- quote pull-through
- lead aging
- cash relance influence

## 11. Risques principaux

## 11.1 Risque de sur-ambition

Vouloir lancer:

- sales inbox
- sync email
- telephonie
- opportunity object
- forecast

avant d'avoir solidifie:

- lead triage
- quote recovery
- next actions

serait une erreur.

## 11.2 Risque de mauvais positionnement

Si on vend le produit comme "un HubSpot equivalent", on cree un ecart entre la promesse et la realite.

## 11.3 Risque de fragmentation

Si on ajoute trop d'objets trop vite, on dilue la lisibilite du workflow.

## 11.4 Risque de dette UX

Le produit a deja beaucoup de modules.

Chaque phase doit donc simplifier la lecture quotidienne, pas ajouter des ecrans pour ajouter des ecrans.

## 11.5 Risque de regression silencieuse

Le risque le plus dangereux n'est pas seulement un bug visible.

Le vrai danger est:

- une baisse de conversion
- une action critique devenue plus lente
- un workflow metier devenu plus confus
- un module stable fragilise par une couche CRM ajoutee trop vite

C'est pour cela que:

- chaque phase doit avoir un avant/apres mesure
- chaque release doit avoir une capacite de rollback simple
- aucun "gros merge CRM" ne doit partir sans garde-fous de validation

## 12. Recommendation finale

Oui, on peut y arriver.

Et oui, on peut construire un CRM franchement competitif.

La meilleure strategie n'est pas de courir apres tous les standards des CRM sales-first des maintenant.

La meilleure strategie est:

1. dominer le flux `lead -> devis -> execution -> revenu`
2. rendre les relances et prochaines actions irreprochables
3. transformer les routines en playbooks
4. seulement ensuite monter la couche inbox / sync / pipeline avancé

Si on suit ce plan, Malikia Pro peut devenir:

- tres competitif contre Jobber / Housecall Pro sur plusieurs segments
- plus credible commercialement en quelques mois
- puis progressivement assez mature pour se rapprocher de certains usages HubSpot / Pipedrive

## 13. Priorite recommandee

Si on doit choisir un seul point de depart:

### START-001 - Lead SLA Inbox and Smart Triage

Pourquoi:

- meilleur effet business immediat
- meilleure reutilisation du code existant
- meilleure base pour tout le reste

## 14. Documents lies

- `docs/CRM_ANALYSE_CONCURRENTIELLE_2026-04-20.md`
- `docs/CRM_DEV_EXECUTION_PHASES_2026-04-20.md`
- `docs/PHASE_0_CRM_REQUEST_FIRST_CADRAGE_2026-04-20.md`
- `docs/PHASE_1_LEAD_SLA_INBOX_SMART_TRIAGE_2026-04-20.md`
- `docs/PHASE_1_REQUEST_INBOX_DEV_BACKLOG_2026-04-20.md`
- `docs/NEXT_HIGH_VALUE_MODULES_USER_STORY.md`
- `docs/CAMPAIGNS_MODULE.md`
