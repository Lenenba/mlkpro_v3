# Malikia Pulse Autopilot - specification technique detaillee

Derniere mise a jour: 2026-04-25

## 1. Objet du document

Ce document decrit comment faire evoluer `Malikia Pulse` vers un vrai systeme de publication recurrente assistee.

L objectif n est pas de remplacer le module Pulse existant.
L objectif est d ajouter une couche `Autopilot` qui:

- planifie des generations de posts
- prepare automatiquement des candidats de publication
- les envoie dans le circuit de validation existant
- reutilise le moteur de publication deja en place
- garde un historique clair entre `regle -> post genere -> approbation -> publication`

## 2. Resume executif

La bonne approche chez nous est la suivante:

- `Pulse Autopilot` ne publie pas "a cote" de Pulse
- `Pulse Autopilot` cree des `SocialPost` standards
- ces `SocialPost` standards passent ensuite par:
  - `SocialPostTarget`
  - `SocialApprovalService`
  - `SocialPublishingService`
  - `PublishSocialPostTargetJob`

Autrement dit:

- le systeme actuel reste le coeur
- l automatisation devient une couche amont de generation et d orchestration

Le flux recommande est:

1. une regle `SocialAutomationRule` devient due
2. le systeme choisit une source de contenu eligible
3. le systeme genere un `SocialPost` candidat
4. le systeme lui attache ses `SocialPostTarget`
5. le systeme le soumet a validation
6. un humain modifie, approuve, programme ou rejette
7. l approbation reutilise le moteur Pulse actuel pour la vraie publication

Par defaut, aucune publication automatique ne doit partir sans validation humaine.

## 3. Ce que Pulse sait deja faire aujourd hui

Le socle actuel est deja tres bon pour supporter `Autopilot`.

### Ce que nous avons deja

- des comptes sociaux connectes dans `SocialAccountConnection`
- des brouillons et posts dans `SocialPost`
- des cibles reseaux dans `SocialPostTarget`
- un workflow d approbation dans `SocialApprovalRequest`
- un moteur de publication asynchrone par cible
- un composeur Vue/Inertia deja bien structure
- un systeme de templates
- des prefills metier depuis:
  - `promotion`
  - `product`
  - `service`
  - `campaign`
- un systeme de suggestions de copy local et deterministe

### Points d extension existants deja reutilisables

- `app/Services/Social/SocialPostService.php`
- `app/Services/Social/SocialApprovalService.php`
- `app/Services/Social/SocialPublishingService.php`
- `app/Services/Social/SocialPrefillService.php`
- `app/Services/Social/SocialSuggestionService.php`
- `app/Services/Social/SocialTemplateService.php`
- `app/Jobs/PublishSocialPostTargetJob.php`

Conclusion importante:

- nous n avons pas besoin de creer un "deuxieme moteur de publication"
- nous devons seulement creer un moteur de planification et de generation qui alimente le pipeline Pulse existant

## 4. Decision d architecture centrale

### Decision principale

Un post genere par automatisation doit etre un `SocialPost` normal.

Il ne faut pas creer une table parallele du type:

- `social_generated_posts`
- `social_queue_posts`
- `social_candidate_posts`

Le meilleur compromis chez nous est:

- `SocialPost` reste l entite de publication unique
- un post automatique est simplement un `SocialPost` enrichi par:
  - `social_automation_rule_id`
  - un bloc `metadata.automation`

### Pourquoi c est le bon choix

- le composeur sait deja afficher un post
- l approbation sait deja verrouiller puis approuver/rejeter
- la publication sait deja gerer la queue et les cibles
- l historique sait deja suivre les statuts
- les tests existants restent largement reutilisables

### Consequence

`Autopilot` doit etre pense comme:

- un createur de candidats `SocialPost`
- un planificateur de recurrence
- un controleur de garde-fous

et non comme:

- un nouveau module de publication autonome

## 5. Positionnement produit dans Malikia Pulse

`Pulse Autopilot` devient une nouvelle surface du module `social`.

### Nouvelles surfaces recommandees

- `Automatisations`
- `Publications a valider`

### Nouveau positionnement du module

- `Overview` = vue d ensemble Pulse
- `Accounts` = connexions sociales
- `Composer` = brouillons manuels
- `Templates` = templates reutilisables
- `History` = historique des posts
- `Autopilot` = regles recurrentes
- `Approvals` = inbox des candidats a valider

Branding recommande:

- onglet visible: `Pulse Autopilot`
- section technique: toujours sous la cle module `social`

## 6. Nouveau scope fonctionnel

L utilisateur doit pouvoir:

- creer une regle d automatisation
- choisir une frequence
- choisir des comptes sociaux cibles
- choisir des sources de contenu
- choisir une langue
- activer ou mettre en pause la regle
- decider si une validation humaine est requise
- consulter les candidats generes
- approuver, programmer, modifier, rejeter ou regenerer

## 7. Donnees a ajouter

## 7.1 Nouvelle table `social_automation_rules`

But:

- stocker les regles recurrentes de generation de posts

### Champs minimums recommandes

- `user_id`
- `created_by_user_id`
- `updated_by_user_id`
- `name`
- `description`
- `is_active`
- `frequency_type`
- `frequency_interval`
- `scheduled_time`
- `timezone`
- `approval_mode`
- `language`
- `content_sources`
- `target_connection_ids`
- `max_posts_per_day`
- `min_hours_between_similar_posts`
- `last_generated_at`
- `next_generation_at`
- `last_error`
- `metadata`

### Pourquoi j ajoute `updated_by_user_id`

Le champ n etait pas dans la liste initiale, mais il est important chez nous pour rester coherent avec:

- `SocialPost`
- `SocialPostTemplate`
- `CampaignAutomationRule`

### Types recommandes

- `is_active` -> boolean
- `frequency_type` -> string enum
- `frequency_interval` -> integer
- `scheduled_time` -> string `HH:MM` ou `time`
- `timezone` -> string
- `approval_mode` -> string enum
- `language` -> string
- `content_sources` -> json
- `target_connection_ids` -> json
- `max_posts_per_day` -> integer
- `min_hours_between_similar_posts` -> integer
- `last_generated_at` -> datetime
- `next_generation_at` -> datetime
- `last_error` -> text nullable
- `metadata` -> json

### Enums recommandes

`frequency_type`

- `hourly`
- `daily`
- `every_two_days`
- `weekly`
- `monthly`

`approval_mode`

- `required`
- `auto_publish`

Important:

- `required` doit etre la valeur par defaut
- `auto_publish` ne doit etre autorise que par activation explicite

### Index recommandes

- index `(user_id, is_active, next_generation_at)`
- index `(user_id, frequency_type)`

## 7.2 Evolution de `social_posts`

Pour garder le lien clair entre un post et la regle qui l a genere, je recommande d ajouter:

- `social_automation_rule_id` nullable

### Pourquoi cette colonne est importante

Le bloc `metadata` ne suffit pas pour tout:

- il permet le contexte
- mais il est moins pratique pour filtrer et auditer

Avec `social_automation_rule_id`, on peut facilement:

- lister les posts generes par une regle
- afficher l historique de la regle
- calculer les quotas journaliers
- detecter les repetitions recentes

### Metadata recommande pour les posts automatiques

Dans `social_posts.metadata.automation`, stocker au minimum:

- `rule_id`
- `rule_name_snapshot`
- `generated_at`
- `generation_mode`
- `approval_mode`
- `language`
- `source_pool_type`
- `selected_source_type`
- `selected_source_id`
- `selected_source_label`
- `content_fingerprint`
- `generation_attempt`
- `regenerated_from_post_id`
- `superseded_post_id`

## 7.3 Extension des sources Pulse

Aujourd hui `SocialPrefillService` gere:

- `promotion`
- `product`
- `service`
- `campaign`

Pour `Autopilot`, il faut ajouter:

- `template`

Donc la logique de source Pulse doit evoluer pour accepter aussi:

- `template`

Cela peut se faire en etendant `SocialPrefillService::allowedSourceTypes()`
ou en introduisant une resolution parallele pour les templates dans le generateur.

Recommendation:

- garder `SocialPrefillService` pour les objets business
- laisser `SocialContentGeneratorService` gerer la branche `template`

## 7.4 Table complementaire optionnelle `social_automation_runs`

Elle n est pas obligatoire pour un MVP.
Mais elle est fortement recommandee si on veut une tres bonne observabilite.

### Role

- garder une trace de chaque execution de regle
- distinguer:
  - generation reussie
  - skip
  - rejet qualite
  - erreur OAuth
  - erreur source

### Champs utiles

- `user_id`
- `social_automation_rule_id`
- `social_post_id` nullable
- `status`
- `selected_source_type`
- `selected_source_id`
- `selected_source_label`
- `generated_at`
- `published_at`
- `error_message`
- `metadata`

Pour un MVP rapide, on peut commencer sans cette table et s appuyer sur:

- `last_error`
- `last_generated_at`
- `social_posts.metadata.automation`

## 8. Structure des sources de contenu

Le champ `content_sources` ne doit pas etre un simple tableau de strings.
Il doit decrire:

- le type de source
- le mode de selection
- les ids cibles eventuels
- les filtres eventuels

### Format recommande

```json
[
  {
    "type": "product",
    "mode": "all"
  },
  {
    "type": "service",
    "mode": "selected_ids",
    "ids": [12, 18, 25]
  },
  {
    "type": "campaign",
    "mode": "selected_ids",
    "ids": [8, 9]
  },
  {
    "type": "template",
    "mode": "selected_ids",
    "ids": [3, 4]
  }
]
```

### Pourquoi ce format est preferable

- il permet un MVP simple
- il permet d ajouter plus tard:
  - filtres par categorie
  - filtres par statut
  - exclusions
  - priorites de rotation

## 9. Comptes et reseaux cibles

Le coeur de ciblage doit rester base sur:

- `target_connection_ids`

Les plateformes ciblees peuvent etre derivees de ces connexions.

### Pourquoi ne pas stocker uniquement les plateformes

Parce qu une plateforme seule ne suffit pas.
L utilisateur doit pouvoir choisir:

- quelles pages Facebook
- quels profils X
- quelles pages LinkedIn
- quels comptes Instagram Business

Donc la vraie source d autorite doit rester:

- les ids de `SocialAccountConnection`

## 10. Services a ajouter

## 10.1 `SocialAutomationRuleService`

Responsabilites:

- CRUD des regles
- validation des frequences
- validation des comptes cibles
- calcul de `next_generation_at`
- pause/reprise
- health summary de la regle

### Il doit centraliser

- la creation
- la mise a jour
- le recalcul de planning
- l activation/desactivation

## 10.2 `SocialContentPlannerService`

Responsabilites:

- identifier les regles dues
- calculer la prochaine date de generation
- selectionner un contenu eligible dans le pool
- tenir compte des quotas et limites

### Il decide

- si la regle doit tourner maintenant
- quelle source concrete sera utilisee
- quand la prochaine execution doit avoir lieu

## 10.3 `SocialContentGeneratorService`

Responsabilites:

- construire le payload de `SocialPost`
- reutiliser `SocialPrefillService` pour:
  - `product`
  - `service`
  - `promotion`
  - `campaign`
- reutiliser `SocialTemplateService` pour:
  - `template`
- enrichir le texte avec `SocialSuggestionService`
- produire la version finale du candidat

### Important

Ce service ne doit pas publier.
Il ne doit faire que:

- selectionner
- assembler
- formater

## 10.4 `SocialContentRotationService`

Responsabilites:

- eviter de choisir trop souvent la meme source
- eviter de republier un contenu quasi identique
- faire tourner les sources dans le temps

### Regles typiques

- ne pas reprendre le meme `source_type + source_id` avant X heures/jours
- ne pas republier le meme `content_fingerprint` avant X heures/jours
- preferer les contenus jamais utilises recentement

## 10.5 `SocialContentQualityChecker`

Responsabilites:

- verifier que les comptes cibles sont exploitables
- verifier que les tokens ne sont pas expires
- verifier le quota journalier
- verifier la repetition de contenu
- verifier qu il reste assez de matiere pour generer un bon post

### Il doit pouvoir renvoyer

- `pass`
- `skip`
- `fail`

avec raison detaillee.

## 10.6 Service manquant a ajouter explicitement

En plus de la liste initiale, je recommande un orchestrateur central:

- `SocialAutomationRunnerService`

### Pourquoi il manque dans la liste initiale

Les services proposes sont utiles, mais il faut un coordonnateur qui:

- charge les regles dues
- verrouille les executions
- appelle planner, generator, checker
- cree le post
- soumet pour validation
- met a jour la regle
- journalise les erreurs

Sans ce service, la commande risquerait de devenir trop lourde.

## 11. Jobs a ajouter

## 11.1 `GenerateSocialPostCandidateJob`

But:

- generer un `SocialPost` candidat pour une regle due

### Flux recommande

1. verrouiller la regle
2. charger la regle et ses connexions cibles
3. executer `SocialContentPlannerService`
4. executer `SocialContentQualityChecker`
5. executer `SocialContentGeneratorService`
6. creer un `SocialPost`
7. creer ses `SocialPostTarget`
8. si `approval_mode = required`, soumettre via `SocialApprovalService`
9. mettre a jour `last_generated_at` et `next_generation_at`
10. dispatcher la notification d approbation

## 11.2 `NotifySocialPostCandidateForApprovalJob`

But:

- notifier les approbateurs et/ou l owner qu un candidat a ete genere

### Sorties possibles

- notification in-app
- email
- event interne si on veut brancher d autres canaux plus tard

## 11.3 `PublishApprovedSocialPostJob`

Ce job peut exister, mais il faut etre clair:

- il n est pas strictement necessaire pour le MVP
- car `SocialApprovalService->approve()` sait deja appeler le moteur de publication

### Recommendation

Si on l ajoute:

- il doit rester un simple wrapper asynchrone
- il ne doit pas reimplementer la publication
- il doit deleguer a `SocialApprovalService` et `SocialPublishingService`

## 12. Commande Laravel a ajouter

Commande:

- `social:run-automations`

### Options recommandees

- `--account_id=`
- `--rule_id=`
- `--dry-run`

### Comportement attendu

La commande doit:

- charger les regles actives
- ignorer les tenants sans feature `social`
- ignorer les regles non dues
- dispatcher ou executer `GenerateSocialPostCandidateJob`
- recalculer `next_generation_at`
- journaliser les erreurs dans `last_error`

### Scheduling recommande

Dans `routes/console.php`, lancer:

- `Schedule::command('social:run-automations')->everyFifteenMinutes()->withoutOverlapping();`

Pourquoi `everyFifteenMinutes` et pas `hourly`:

- une regle `hourly` reste compatible
- une regle quotidienne/hebdo/mensuelle reste compatible
- on gagne de la souplesse pour les fuseaux horaires et pour un futur mode custom

## 13. Calcul des frequences

Le champ `frequency_type` doit rester simple.

### Mapping recommande

- `hourly` -> toutes les 1 heure
- `daily` -> tous les jours a `scheduled_time`
- `every_two_days` -> tous les 2 jours a `scheduled_time`
- `weekly` -> toutes les 1 semaine a `scheduled_time`
- `monthly` -> tous les 1 mois a `scheduled_time`

`frequency_interval` doit rester present meme si le MVP n expose pas encore toute la personnalisation.
Il permettra plus tard:

- toutes les 3 heures
- tous les 5 jours
- toutes les 2 semaines

## 14. Mode d approbation recommande

Par defaut:

- `approval_mode = required`

### Pourquoi

Le besoin fonctionnel dit explicitement:

- ne pas publier directement par defaut
- eviter toute publication auto sans validation explicite

### Comportement recommande

`required`

- le post est genere
- le post passe en `pending_approval`
- l approbateur decide de publier ou programmer

`auto_publish`

- reserve a une activation explicite
- idealement owner only
- a garder pour plus tard ou pour un tenant qui l autorise clairement

Recommendation pragmatique:

- livrer d abord uniquement `required`
- laisser `auto_publish` dans le schema et la spec
- ne l exposer en UI qu en phase 2 ou 3

## 15. Flux detaille d execution Autopilot

### Etape 1 - Une regle devient due

Conditions:

- `is_active = true`
- `next_generation_at <= now()`
- tenant avec feature `social`

### Etape 2 - Verification des comptes

Verifier pour chaque connection cible:

- `is_active = true`
- `status = connected`
- `token_expires_at` pas expire ou pas trop proche

Si aucune connexion valide:

- skip de la regle
- `last_error` renseigne

### Etape 3 - Choix du contenu source

Le planner choisit:

- un produit
- un service
- une promotion
- une campagne
- ou un template

selon:

- le pool configure
- l historique recent
- la rotation
- les quotas

### Etape 4 - Generation du candidat

Le generateur produit:

- texte
- image
- lien
- CTA
- langue

en reutilisant:

- `SocialPrefillService`
- `SocialSuggestionService`
- `SocialTemplateService`

### Etape 5 - Controle qualite

Verifier au minimum:

- contenu non vide
- non duplication recente
- quota journalier non depasse
- comptes cibles valides

### Etape 6 - Creation du `SocialPost`

Le post est cree avec:

- `social_automation_rule_id`
- `metadata.automation`
- `status = draft` ou `scheduled` selon le besoin

Puis les `SocialPostTarget` sont attaches.

### Etape 7 - Soumission a validation

Le systeme appelle:

- `SocialApprovalService->submit(...)`

Le post passe alors en:

- `pending_approval`

### Etape 8 - Notification

Le systeme notifie:

- owner
- approbateurs
- eventuellement membres autorises

### Etape 9 - Decision humaine

Depuis la section `Publications a valider`, l utilisateur peut:

- modifier
- approuver et publier maintenant
- approuver et programmer
- rejeter
- regenerer

### Etape 10 - Publication

L approbation reutilise:

- `SocialPublishingService`
- `PublishSocialPostTargetJob`

## 16. Section "Publications a valider"

Cette section est importante.
Elle ne doit pas etre noyee dans l historique general.

### Pourquoi une surface dediee est utile

- les candidats automatiques ont besoin d un inbox clair
- l equipe doit pouvoir traiter vite ce qui est en attente
- il faut distinguer les brouillons manuels des candidats recurrentement generes

### Vues recommandees

- liste des posts `pending_approval`
- filtres:
  - regle
  - plateforme
  - source de contenu
  - langue
  - date

### Actions demandees

- `Modifier`
- `Valider et publier maintenant`
- `Valider et programmer`
- `Rejeter`
- `Regenerer une autre version`

## 17. Comment gerer "Modifier" sans casser le workflow existant

Point important:

- aujourd hui, un `SocialPost` en `pending_approval` n est plus editable

Le systeme actuel fait bien cela.
Il ne faut pas le contourner brutalement.

### Proposition propre

L action `Modifier` doit faire:

1. rejeter la demande courante avec un motif technique `editing_requested`
2. dupliquer le candidat en brouillon editable
3. conserver le lien entre:
   - post original
   - nouvelle copie editable
   - regle d origine

### Metadata recommandees

Sur l original:

- `metadata.automation.superseded_by_post_id`

Sur la copie:

- `metadata.automation.edited_from_post_id`

## 18. Comment gerer "Regenerer une autre version"

`Regenerer` ne doit pas ecraser le post courant.

### Proposition

1. marquer l ancien candidat comme rejete ou remplace
2. dispatcher une nouvelle generation pour la meme regle
3. incrementer `generation_attempt`
4. changer de variante texte ou de source si possible

### Important

Le `SocialContentRotationService` doit essayer de:

- changer la source si le pool le permet
- sinon changer la variante de copy

## 19. Garde-fous obligatoires

## 19.1 Eviter les doublons de contenu

Calculer un `content_fingerprint` a partir de:

- texte normalise
- lien
- image principale
- source_type
- source_id

Puis comparer avec les posts recents de la meme regle ou du meme tenant.

### Regle MVP

- refuser un contenu trop proche sur les X dernieres heures/jours

## 19.2 Verifier les connexions sociales

Avant generation et avant publication, verifier:

- compte actif
- statut `connected`
- token pas expire

Si le token est proche de l expiration:

- tenter un refresh si la logique existe
- sinon lever une alerte claire

## 19.3 Limiter les posts par jour

Chaque regle doit pouvoir limiter:

- le nombre de candidats/jour
- ou le nombre de posts effectivement publies/jour

Recommendation MVP:

- `max_posts_per_day` au niveau regle

## 19.4 Validation humaine par defaut

Le systeme ne doit jamais publier sans validation par defaut.

Recommendation:

- le mode `auto_publish` n est meme pas active en UI au premier lot

## 19.5 Eviter les regles "cassantes"

Si une regle echoue plusieurs fois de suite:

- ne pas publier quand meme
- ne pas spammer les approbateurs
- garder un `last_error` clair

Option recommandee phase 2:

- auto-pause apres N echecs consecutifs

## 20. Permissions et gouvernance

Le module `social` a deja:

- `social.view`
- `social.manage`
- `social.publish`
- `social.approve`

### Ce qui manque

Je recommande d ajouter:

- `social.automate`

### Pourquoi cette permission est utile

Configurer une recurrence n est pas exactement pareil que:

- gerer un brouillon
- publier un post

### Repartition recommandee

Owner:

- tous les droits

Team:

- `social.view` -> voir Autopilot
- `social.automate` -> creer, modifier, activer, mettre en pause les regles
- `social.approve` -> traiter les publications a valider
- `social.publish` -> utile seulement si la personne peut aussi approuver ou programmer

Si on veut minimiser les changements permissionnels pour un premier lot, on peut temporairement mapper:

- `social.manage` -> gestion des regles

Mais a moyen terme, `social.automate` est plus propre.

## 21. UI et pages a ajouter

## 21.1 Pages Inertia/Vue

- `resources/js/Pages/Social/Automations.vue`
- `resources/js/Pages/Social/Approvals.vue`

## 21.2 Composants Vue recommandes

- `SocialAutomationManager.vue`
- `SocialAutomationRuleForm.vue`
- `SocialApprovalInbox.vue`
- `SocialAutomationHealthPanel.vue`
- `SocialAutomationRunList.vue` si on ajoute la table des runs

## 21.3 Evolutions de l entete workspace

`SocialWorkspaceHeader` devra ajouter les tabs:

- `autopilot`
- `approvals`

et les traductions associees dans:

- `resources/js/i18n/modules/fr/social.json`
- `resources/js/i18n/modules/en/social.json`
- `resources/js/i18n/modules/es/social.json`

## 22. Routes et endpoints recommandes

### Pages

- `GET /social/automations`
- `GET /social/approvals`

### CRUD des regles

- `POST /social/automations`
- `PUT /social/automations/{rule}`
- `DELETE /social/automations/{rule}`
- `POST /social/automations/{rule}/pause`
- `POST /social/automations/{rule}/resume`
- `POST /social/automations/{rule}/run-now`

### Actions sur les candidats

- `POST /social/posts/{post}/automation-regenerate`
- `POST /social/posts/{post}/automation-edit-copy`

### Important

Les actions suivantes restent inchangees et doivent etre reutilisees:

- `POST /social/posts/{post}/submit-approval`
- `POST /social/posts/{post}/approve`
- `POST /social/posts/{post}/reject`
- `POST /social/posts/{post}/publish`
- `POST /social/posts/{post}/schedule`

## 23. Controllers recommandes

- `SocialAutomationController`
- `SocialApprovalInboxController`

### Pourquoi separer

- le CRUD des regles n a pas la meme responsabilite que le composeur
- l inbox des validations a besoin de filtres et de stats propres

## 24. Reutilisation du composeur existant

Le composeur Pulse ne doit pas disparaitre.
Au contraire, il doit devenir le point d edition des candidats generes.

### Utilisation recommandee

- un clic `Modifier` depuis `Approvals` peut ouvrir `route('social.composer', { draft: post.id })`
- mais seulement si le workflow a d abord cree une copie editable ou rejete la demande courante

## 25. Strategie de test recommandee

Tests feature a ajouter:

- `SocialAutomationRulesSchemaTest.php`
- `SocialAutomationRuleCrudTest.php`
- `SocialAutopilotCommandTest.php`
- `SocialAutopilotApprovalInboxTest.php`
- `SocialAutopilotGenerationTest.php`
- `SocialAutopilotRotationTest.php`
- `SocialAutopilotQuotaGuardTest.php`
- `SocialAutopilotConnectionHealthTest.php`
- `SocialAutopilotTemplateSourceTest.php`

### Cas critiques a couvrir

- une regle due cree bien un `SocialPost`
- le post genere a bien `social_automation_rule_id`
- le post est soumis pour approbation
- l approbation appelle le moteur de publication existant
- un compte expire bloque la generation
- le quota journalier bloque la generation
- le contenu duplique est evite
- la regeneration cree une nouvelle variante
- la modification cree une copie editable

## 26. Plan de livraison recommande

## Phase 1 - Socle backend

- migration `social_automation_rules`
- ajout de `social_automation_rule_id` sur `social_posts`
- modele `SocialAutomationRule`
- `SocialAutomationRuleService`
- `SocialAutomationRunnerService`
- `SocialContentPlannerService`
- `SocialContentGeneratorService`
- `SocialContentRotationService`
- `SocialContentQualityChecker`
- commande `social:run-automations`
- scheduling dans `routes/console.php`
- tests backend de base

## Phase 2 - Surfaces UI

- onglet `Autopilot`
- onglet `Publications a valider`
- CRUD des regles
- liste des candidats en attente
- actions:
  - approuver
  - programmer
  - rejeter
  - regenerer
  - modifier

## Phase 3 - Garde-fous et polish

- fingerprint de duplication
- quotas fins
- auto-pause apres erreurs repetes
- health indicators
- notifications
- meilleure observabilite
- eventuelle table `social_automation_runs`

## 27. Decisions importantes a garder

### 27.1 Ne pas remplacer Pulse

La publication doit rester:

- `SocialPost`
- `SocialPostTarget`
- `SocialApprovalService`
- `SocialPublishingService`
- `PublishSocialPostTargetJob`

### 27.2 Ne pas publier directement par defaut

Le mode standard doit rester:

- generation
- validation
- publication

### 27.3 Garder l historique lisible

Chaque post automatique doit porter clairement:

- quelle regle l a cree
- quelle source a ete utilisee
- s il a ete regenere
- s il a ete remplace

### 27.4 Garder un systeme evolutif

Le schema et les services doivent deja permettre plus tard:

- frequence custom
- calendarisation plus riche
- variantes par reseau
- analytics
- auto publish explicite

## 28. Recommandation finale

Dans notre cas, la meilleure implementation de `Malikia Pulse Autopilot` est:

- un nouveau sous-module du workspace `social`
- base sur `SocialAutomationRule`
- qui cree des `SocialPost` standards
- qui reutilise les services Pulse existants
- qui impose une validation humaine par defaut
- qui ajoute une inbox dediee des publications a valider

C est la voie la plus propre techniquement, la plus coherente avec l architecture actuelle, et la plus sure pour faire evoluer Pulse sans casser le V1 existant.

## 29. Fichiers du repo a toucher en priorite

### Backend

- `app/Models/SocialPost.php`
- `app/Models/SocialApprovalRequest.php`
- `app/Models/SocialAccountConnection.php`
- `app/Http/Controllers/SocialPostController.php`
- `app/Services/Social/SocialPostService.php`
- `app/Services/Social/SocialApprovalService.php`
- `app/Services/Social/SocialPublishingService.php`
- `app/Services/Social/SocialPrefillService.php`
- `app/Services/Social/SocialTemplateService.php`
- `app/Jobs/PublishSocialPostTargetJob.php`
- `routes/web.php`
- `routes/console.php`

### Nouveaux fichiers probables

- `app/Models/SocialAutomationRule.php`
- `app/Http/Controllers/SocialAutomationController.php`
- `app/Http/Controllers/SocialApprovalInboxController.php`
- `app/Services/Social/SocialAutomationRuleService.php`
- `app/Services/Social/SocialAutomationRunnerService.php`
- `app/Services/Social/SocialContentPlannerService.php`
- `app/Services/Social/SocialContentGeneratorService.php`
- `app/Services/Social/SocialContentRotationService.php`
- `app/Services/Social/SocialContentQualityChecker.php`
- `app/Jobs/GenerateSocialPostCandidateJob.php`
- `app/Jobs/NotifySocialPostCandidateForApprovalJob.php`
- `app/Jobs/PublishApprovedSocialPostJob.php`
- `database/migrations/*create_social_automation_rules_table.php`
- `database/migrations/*add_social_automation_rule_id_to_social_posts_table.php`

### Frontend

- `resources/js/Pages/Social/Index.vue`
- `resources/js/Pages/Social/Components/SocialWorkspaceHeader.vue`
- `resources/js/Pages/Social/Automations.vue`
- `resources/js/Pages/Social/Approvals.vue`
- `resources/js/Pages/Social/Components/SocialAutomationManager.vue`
- `resources/js/Pages/Social/Components/SocialApprovalInbox.vue`
- `resources/js/i18n/modules/fr/social.json`
- `resources/js/i18n/modules/en/social.json`
- `resources/js/i18n/modules/es/social.json`

### Tests

- `tests/Feature/SocialAutomationRuleCrudTest.php`
- `tests/Feature/SocialAutopilotCommandTest.php`
- `tests/Feature/SocialAutopilotApprovalInboxTest.php`
- `tests/Feature/SocialAutopilotGenerationTest.php`
