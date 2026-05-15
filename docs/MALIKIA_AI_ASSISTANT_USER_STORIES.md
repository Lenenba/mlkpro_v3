# Malikia AI Assistant - User stories et cadrage produit

Derniere mise a jour: 2026-05-13

## 1. But du document

Ce document pose le cadrage produit et technique du module `ai_assistant` pour
Malikia Pro.

Le but est de construire progressivement un receptionniste virtuel IA pour les
petites entreprises:

- repondre aux demandes clients
- qualifier les prospects
- creer des prospects ou clients selon les regles de l entreprise
- proposer de vrais creneaux disponibles
- creer ou replanifier des reservations
- creer des taches internes
- resumer les conversations
- transferer a un humain quand l IA n est pas assez sure

Le module doit etre livre sur une branche dediee avant merge vers `develop`:

- branche recommandee: `feature/malikia-ai-assistant`
- merge uniquement apres tests fonctionnels et non-regression reservations
- aucune action IA critique ne doit etre invisible ou non auditable

## 2. Definition produit

### 2.1 Nom produit

Nom public:

- Malikia AI Assistant

Nom technique:

- `ai_assistant`

### 2.2 Vision

Malikia AI Assistant est un receptionniste virtuel capable de traiter une
conversation client simple comme le ferait une personne a l accueil:

- comprendre la demande
- poser les bonnes questions
- rester court et humain
- respecter la langue du client
- utiliser les services, disponibilites et regles de l entreprise
- creer les actions dans Malikia seulement quand les conditions sont remplies

### 2.3 Inspiration

Le concept est inspire de Workiz Genius AI Answering:

- repondre aux appels, textes et emails
- prendre des messages
- creer ou replanifier des jobs
- utiliser les regles business et disponibilites

Adaptation Malikia:

- focus plus fort sur reservations et liens publics de reservation
- experience bilingue FR/EN
- qualification prospect plus propre
- experience plus humaine pour services, salons, restaurants, consultants,
  equipes terrain et petites entreprises locales
- actions tracees, validables et tenant-scopees

### 2.4 Non-goals MVP

La phase 1 ne doit pas:

- faire de voix ou appels telephoniques
- gerer WhatsApp ou Messenger
- envoyer des SMS ou emails reels sans infrastructure terminee
- remplacer l inbox humaine
- confirmer une reservation sans creneau reel
- creer un client final sans permission explicite
- contourner les policies, permissions et limites du tenant
- exposer des IDs internes ou details techniques au client
- inventer des prix, disponibilites ou politiques

## 3. Principes produit

- L IA assiste, mais les regles metier restent deterministes.
- Les disponibilites viennent du moteur reservation existant.
- Une reservation confirmee doit toujours correspondre a un slot reel.
- Un visiteur inconnu devient d abord un prospect.
- Un client est cree seulement si les settings du tenant l autorisent.
- L IA pose une seule question a la fois.
- L IA reste breve, chaleureuse et claire.
- L IA repond dans la langue du client quand possible.
- Les actions sensibles sont journalisees dans `ai_actions`.
- Le mode validation humaine doit transformer les actions en `pending`.
- Le transfert humain doit etre simple et visible dans l inbox admin.

## 4. Personas

### 4.1 Proprietaire d entreprise

Elle veut ne plus perdre de demandes entrantes quand elle est occupee.

Exemples:

- salon de beaute
- restaurant
- entreprise de menage
- consultant
- clinique
- agence locale
- entreprise terrain

### 4.2 Membre equipe / reception

Il veut reprendre une conversation quand l IA est bloquee, voir le resume et
approuver les actions proposees.

### 4.3 Client final

Il veut obtenir une reponse rapide, reserver un service ou laisser une demande
sans installer d application.

### 4.4 Admin / owner technique

Il veut configurer les regles de l assistant, suivre les actions IA, comprendre
les echecs et garder le controle.

## 5. Channels

### 5.1 Phase 1

- web chat widget
- public reservation link

### 5.2 Phase 2

- SMS
- email

### 5.3 Phase 3

- WhatsApp
- Messenger
- voice calls
- call transcript

## 6. Scope recommande

### 6.1 Phase 1 - MVP reservation web

Objectif:

Livrer un assistant web capable de qualifier une demande et de creer une
reservation avec prospect, en gardant une validation humaine optionnelle.

Inclus:

- migrations et modeles `ai_assistant`
- settings admin tenant
- conversations et messages persistants
- endpoint public de creation conversation
- endpoint public d envoi message
- prompt builder tenant-scope
- detection d intention reservation simple
- collecte des infos minimales
- suggestion de 3 slots disponibles
- creation prospect
- creation reservation si slot reel selectionne
- actions IA journalisees
- mode validation humaine
- inbox admin conversations
- approbation ou rejet d actions pending
- tests feature MVP

Exclu volontairement:

- SMS
- email entrant
- WhatsApp
- voice
- vraie base de connaissance avancee
- rescheduling complet
- creation tache automatique
- analytics avancees

### 6.2 Phase 2 - Multicanal et productivite

Objectif:

Ajouter les canaux differes, les resumes, la knowledge base et les actions
operationnelles.

Inclus:

- SMS
- email
- resume conversation
- knowledge base admin
- reschedule reservation
- creation de taches
- historique client dans le contexte
- relance ou message sortant selon canal

### 6.3 Phase 3 - Receptionniste complet

Objectif:

Transformer le module en receptionniste multicanal complet.

Inclus:

- WhatsApp
- Messenger
- voix
- transcription appel
- scoring performance IA
- dashboard analytics
- statistiques d intents, conversion et handoff

## 7. User stories MVP

### AIA-001 - Configurer l assistant IA

En tant que proprietaire,
je veux activer et configurer Malikia AI Assistant,
afin que l assistant respecte mon entreprise, ma langue et mes regles.

Criteres d acceptation:

- je peux activer/desactiver l assistant
- je peux definir le nom de l assistant
- je peux choisir la langue par defaut
- je peux choisir les langues supportees
- je peux choisir un ton: professional, warm, friendly, premium, direct
- je peux definir un message d accueil
- je peux definir un message fallback
- je peux autoriser ou interdire:
  - creation prospect
  - creation client
  - creation reservation
  - replanification reservation
  - creation tache
- je peux activer la validation humaine obligatoire
- je peux saisir un contexte business
- les settings sont scopes au tenant

### AIA-002 - Demarrer une conversation publique

En tant que visiteur,
je veux ouvrir une conversation depuis un widget ou un lien public,
afin de poser ma question sans compte client.

Criteres d acceptation:

- un endpoint public cree une conversation
- la conversation recoit un identifiant public non previsible
- le tenant est resolu sans exposer d IDs internes
- le channel peut etre `web_chat` ou `public_reservation`
- le message d accueil utilise les settings du tenant
- la conversation est refusee si l assistant est desactive
- l endpoint est rate-limite

### AIA-003 - Stocker les messages

En tant que business owner,
je veux que tous les messages soient sauvegardes,
afin de garder une trace et reprendre la conversation.

Criteres d acceptation:

- chaque message utilisateur est stocke dans `ai_messages`
- chaque reponse assistant est stockee dans `ai_messages`
- les messages sont rattaches a une conversation
- `sender_type` peut etre `user`, `assistant`, `system` ou `human`
- le payload brut peut etre conserve pour audit
- les messages ne peuvent pas etre lus par un autre tenant

### AIA-004 - Repondre en FR ou EN

En tant que client final,
je veux que l assistant me reponde dans ma langue,
afin que l experience soit naturelle.

Criteres d acceptation:

- l assistant detecte francais ou anglais quand possible
- il utilise `default_language` si la langue est incertaine
- il respecte `supported_languages`
- il ne melange pas les langues dans une meme reponse sauf besoin
- les messages systeme peuvent rester en anglais cote code, mais pas cote client

### AIA-005 - Construire un prompt tenant-scope

En tant que systeme,
je veux construire un prompt avec uniquement le contexte du tenant,
afin que l IA reponde avec les bonnes informations.

Criteres d acceptation:

- `AiPromptBuilder` inclut le nom de l assistant
- il inclut le nom de l entreprise
- il inclut le contexte business
- il inclut les services actifs autorises
- il inclut les regles de reservation utiles
- il inclut les actions autorisees
- il inclut les messages recents de la conversation
- il n inclut aucune donnee d un autre tenant

### AIA-006 - Detecter une intention reservation

En tant que visiteur,
je veux pouvoir demander une reservation naturellement,
afin de ne pas remplir un long formulaire.

Criteres d acceptation:

- l assistant detecte les demandes du type:
  - je veux reserver
  - avez-vous une disponibilite demain
  - can I book an appointment
  - I need a service this week
- l intention est stockee dans `ai_conversations.intent`
- un score est stocke dans `confidence_score`
- si le score est trop bas, la conversation passe en `waiting_human`

### AIA-007 - Qualifier une demande de reservation

En tant que visiteur,
je veux que l assistant me demande les informations manquantes,
afin que ma demande soit complete sans confusion.

Criteres d acceptation:

- l assistant collecte:
  - full name
  - phone
  - email
  - requested service
  - preferred date/time
  - preferred team member if applicable
  - notes
- l assistant pose une seule question a la fois
- l assistant ne demande pas une information deja connue
- l assistant accepte des reponses courtes
- l assistant peut mettre a jour le contexte de conversation progressivement

### AIA-008 - Proposer des slots reels

En tant que visiteur,
je veux recevoir 3 creneaux disponibles,
afin de choisir rapidement une heure.

Criteres d acceptation:

- l assistant utilise le moteur de disponibilite existant
- il ne propose jamais un slot invente
- il propose jusqu a 3 slots reels
- si aucun slot n existe, il demande une autre preference
- les slots proposes sont stockes dans le metadata de conversation ou action
- l assistant attend le choix du visiteur avant creation reservation

### AIA-009 - Creer un prospect depuis l IA

En tant que business owner,
je veux qu un visiteur inconnu devienne un prospect,
afin de garder la demande dans mon CRM.

Criteres d acceptation:

- l assistant cree un prospect seulement si `allow_create_prospect` est true
- le prospect est scope au tenant
- le prospect reprend nom, email, telephone, service demande et notes
- la conversation est liee au prospect
- si la validation humaine est activee, l action reste `pending`
- si l action echoue, l erreur est stockee dans `ai_actions.error_message`

### AIA-010 - Creer une reservation depuis l IA

En tant que visiteur,
je veux confirmer un creneau choisi,
afin que ma reservation soit envoyee a l entreprise.

Criteres d acceptation:

- l assistant cree une reservation seulement si `allow_create_reservation` est true
- la reservation utilise un slot reel disponible
- la reservation est liee au prospect
- la reservation est liee a la conversation
- la reservation respecte les regles existantes de reservation
- la reservation n est pas creee si le slot est deja pris
- le client recoit un message clair:
  - demande envoyee si confirmation manuelle
  - reservation confirmee si confirmation automatique
- le owner recoit une notification

### AIA-011 - Respecter la validation humaine

En tant que proprietaire,
je veux pouvoir valider les actions avant execution,
afin de garder le controle sur l IA.

Criteres d acceptation:

- si `require_human_validation` est true, les actions critiques restent
  `pending`
- l IA explique au visiteur que l equipe va verifier
- l admin peut approuver ou rejeter
- une action approuvee passe a `approved`, puis `executed` si elle reussit
- une action rejetee passe a `rejected`
- chaque transition est horodatee ou visible dans le log

### AIA-012 - Voir l inbox des conversations

En tant que membre equipe,
je veux voir les conversations IA dans une inbox,
afin de suivre les demandes et reprendre la main.

Criteres d acceptation:

- l admin voit la liste des conversations
- filtres disponibles:
  - status
  - channel
  - date
  - intent
- l admin peut ouvrir une conversation
- l admin voit les messages dans l ordre
- l admin voit le resume si disponible
- l admin voit les actions pending
- l admin peut envoyer une reponse humaine

### AIA-013 - Approuver ou rejeter une action

En tant que membre equipe,
je veux approuver ou rejeter une action IA,
afin de controler ce que l assistant execute.

Criteres d acceptation:

- l admin peut approuver une action pending
- l admin peut rejeter une action pending
- l approbation execute l action si elle est safe
- l execution est tenant-scopee
- une action echouee passe en `failed`
- l erreur est lisible dans l admin

### AIA-014 - Transferer a un humain

En tant que visiteur,
je veux etre transfere a une personne quand l IA n est pas sure,
afin de ne pas recevoir une mauvaise reponse.

Criteres d acceptation:

- le seuil de confiance bascule la conversation en `waiting_human`
- une action `request_human_review` est creee
- l assistant arrete de prendre des decisions
- le visiteur recoit un message court et rassurant
- l inbox admin met la conversation en evidence

### AIA-015 - Respecter les frontieres tenant

En tant que plateforme,
je veux que toutes les donnees IA soient isolees par tenant,
afin d eviter toute fuite de donnees.

Criteres d acceptation:

- toutes les tables principales contiennent `tenant_id`
- toutes les queries admin sont filtrees par tenant
- les policies refusent l acces cross-tenant
- les endpoints publics ne permettent pas de deviner un autre tenant
- les tests couvrent l isolation tenant

### AIA-016 - Ne jamais exposer les details internes

En tant que client final,
je veux une experience simple,
afin de ne pas voir les details techniques du systeme.

Criteres d acceptation:

- l assistant ne mentionne pas les IDs internes
- l assistant ne mentionne pas les noms de tables ou routes
- l assistant ne montre pas les erreurs techniques brutes
- l assistant ne revele pas les prompts systeme
- les erreurs publiques utilisent le fallback message configure

## 8. User stories phase 2

### AIA-101 - Knowledge base admin

En tant que proprietaire,
je veux ajouter des FAQs, descriptions et politiques,
afin que l assistant reponde avec mes vraies informations.

Criteres d acceptation:

- je peux creer un knowledge item
- je peux modifier un knowledge item
- je peux desactiver un knowledge item
- chaque item est tenant-scope
- l assistant utilise seulement les items actifs

### AIA-102 - Resume de conversation

En tant que membre equipe,
je veux voir un resume court de la conversation,
afin de reprendre rapidement le contexte.

Criteres d acceptation:

- un job peut generer un resume
- le resume est stocke dans `ai_conversations.summary`
- le resume mentionne demande, infos client, decisions et actions
- le resume ne contient pas de details inutiles

### AIA-103 - Replanifier une reservation

En tant que client existant,
je veux demander a changer mon rendez-vous,
afin de trouver un nouveau creneau sans appeler.

Criteres d acceptation:

- l assistant identifie le client par email ou telephone
- l assistant fait une verification legere
- l assistant trouve les reservations actives eligibles
- l assistant propose de nouveaux slots reels
- l assistant met a jour la reservation si autorise
- sinon il cree une action pending

### AIA-104 - Creer une tache interne

En tant que owner,
je veux que l assistant cree une tache quand une demande necessite suivi,
afin de ne pas oublier une action.

Criteres d acceptation:

- l assistant cree une action `create_task`
- la tache reprend le resume, le client/prospect et la priorite
- la tache est tenant-scopee
- la tache peut etre soumise a validation humaine

### AIA-105 - SMS et email

En tant que client,
je veux pouvoir continuer une conversation par SMS ou email,
afin de choisir le canal le plus naturel.

Criteres d acceptation:

- les channels `sms` et `email` sont supportes
- les messages entrants creent ou retrouvent une conversation
- les preferences de communication sont respectees
- les reponses sortantes sont journalisees

## 9. User stories phase 3

### AIA-201 - WhatsApp et Messenger

En tant que client,
je veux contacter l entreprise depuis mes messageries habituelles,
afin de recevoir une reponse rapide.

Criteres d acceptation:

- le channel `whatsapp` est supporte
- le channel `messenger` est supporte
- les conversations restent rattachees au tenant
- les actions suivent les memes regles que le web chat

### AIA-202 - Voice answering

En tant que business owner,
je veux que l assistant puisse repondre aux appels,
afin de capturer les demandes hors horaires.

Criteres d acceptation:

- le channel `voice` est supporte
- un transcript est stocke
- l IA peut creer une demande ou un message de rappel
- les decisions critiques restent validables

### AIA-203 - Analytics IA

En tant que owner,
je veux mesurer la performance de l assistant,
afin de savoir s il aide vraiment l entreprise.

Criteres d acceptation:

- le dashboard montre volume conversations
- taux de resolution
- taux de handoff
- reservations creees
- prospects crees
- actions echouees
- langues et channels

## 10. Schema de donnees MVP

### 10.1 `ai_assistant_settings`

Champs:

- `id`
- `tenant_id`
- `assistant_name`
- `enabled`
- `default_language`
- `supported_languages` json
- `tone`
- `greeting_message`
- `fallback_message`
- `allow_create_prospect`
- `allow_create_client`
- `allow_create_reservation`
- `allow_reschedule_reservation`
- `allow_create_task`
- `require_human_validation`
- `business_context` text
- `service_area_rules` json nullable
- `working_hours_rules` json nullable
- timestamps

Notes:

- une seule ligne active par tenant
- valeurs par defaut creees au premier acces settings

### 10.2 `ai_conversations`

Champs:

- `id`
- `tenant_id`
- `public_uuid`
- `channel`
- `status`
- `visitor_name`
- `visitor_email`
- `visitor_phone`
- `client_id`
- `prospect_id`
- `reservation_id`
- `detected_language`
- `intent`
- `confidence_score`
- `summary`
- `metadata` json nullable
- timestamps

Enums:

- channel: `web_chat`, `public_reservation`, `sms`, `email`, `whatsapp`, `voice`
- status: `open`, `waiting_human`, `resolved`, `abandoned`

### 10.3 `ai_messages`

Champs:

- `id`
- `conversation_id`
- `sender_type`
- `content` longText
- `payload` json nullable
- timestamps

Enums:

- sender_type: `user`, `assistant`, `system`, `human`

### 10.4 `ai_actions`

Champs:

- `id`
- `tenant_id`
- `conversation_id`
- `action_type`
- `status`
- `input_payload` json
- `output_payload` json nullable
- `error_message` text nullable
- `executed_at` nullable
- timestamps

Enums:

- action_type:
  - `create_prospect`
  - `create_client`
  - `create_reservation`
  - `reschedule_reservation`
  - `create_task`
  - `send_message`
  - `request_human_review`
- status:
  - `pending`
  - `approved`
  - `executed`
  - `failed`
  - `rejected`

### 10.5 `ai_knowledge_items`

Champs:

- `id`
- `tenant_id`
- `title`
- `content` longText
- `category`
- `is_active`
- timestamps

## 11. Structure Laravel cible

```txt
app/
  Modules/
    AiAssistant/
      Models/
        AiAssistantSetting.php
        AiConversation.php
        AiMessage.php
        AiAction.php
        AiKnowledgeItem.php

      Services/
        AiAssistantService.php
        AiPromptBuilder.php
        AiIntentDetector.php
        AiActionExecutor.php
        AiReservationOrchestrator.php
        AiKnowledgeResolver.php
        AiConversationSummarizer.php

      Actions/
        CreateProspectFromAiAction.php
        CreateReservationFromAiAction.php
        RescheduleReservationFromAiAction.php
        CreateTaskFromAiAction.php
        RequestHumanReviewAction.php

      Http/
        Controllers/
          AiAssistantSettingsController.php
          AiConversationController.php
          AiPublicChatController.php
          AiActionController.php
          AiKnowledgeItemController.php

      Requests/
        StoreAiAssistantSettingRequest.php
        StoreAiKnowledgeItemRequest.php
        SendAiMessageRequest.php
        ApproveAiActionRequest.php

      Jobs/
        ProcessAiMessageJob.php
        ExecuteAiActionJob.php
        SummarizeAiConversationJob.php

      Policies/
        AiConversationPolicy.php
        AiAssistantSettingPolicy.php

      DTO/
        AiConversationContext.php
        AiDetectedIntent.php
        AiProposedAction.php
        AiAssistantResponse.php
```

Commentaires de code:

- tous les commentaires PHP/JS doivent etre en anglais
- le texte UI peut etre FR/EN selon i18n

## 12. Routes cible

### 12.1 Routes admin

```txt
GET    /admin/ai-assistant/settings
PUT    /admin/ai-assistant/settings
GET    /admin/ai-assistant/conversations
GET    /admin/ai-assistant/conversations/{conversation}
POST   /admin/ai-assistant/conversations/{conversation}/reply
POST   /admin/ai-assistant/actions/{action}/approve
POST   /admin/ai-assistant/actions/{action}/reject
GET    /admin/ai-assistant/knowledge
POST   /admin/ai-assistant/knowledge
PUT    /admin/ai-assistant/knowledge/{item}
DELETE /admin/ai-assistant/knowledge/{item}
```

### 12.2 Routes publiques

```txt
POST /public/ai-assistant/conversations
POST /public/ai-assistant/conversations/{conversation}/messages
```

Notes:

- les routes publiques doivent etre rate-limitees
- l identifiant conversation public doit etre UUID ou token signe
- aucune route publique ne doit accepter un `tenant_id` brut non verifie

## 13. Prompt systeme cible

Template:

```txt
You are {assistant_name}, the virtual assistant for {business_name}.
You help clients with reservations, service questions, and follow-ups.
You must communicate in {language}.

Business context:
{business_context}

Available services:
{services}

Booking rules:
{booking_rules}

Allowed actions:
{allowed_actions}

Important rules:
- Never invent data.
- Never promise something that is not available.
- If you are unsure, request human review.
- Ask one question at a time.
- Keep the tone warm, clear, and professional.
- When the user wants a reservation, collect all required information before proposing slots.
- If the user is new, create a prospect, not a client.
- A prospect can become a client only after confirmation, attendance, or payment depending on business workflow.

Reservation workflow:
1. Detect service.
2. Detect preferred date.
3. Detect preferred team member if any.
4. Check availability.
5. Propose available slots.
6. Wait for user choice.
7. Create reservation.
8. Confirm clearly.
```

## 14. Regles IA obligatoires

L assistant doit:

- repondre dans la langue du client quand possible
- ne jamais inventer une disponibilite
- ne jamais confirmer une reservation sans slot reel
- ne jamais creer un client sans permission settings
- creer un prospect d abord quand l utilisateur est inconnu
- poser une question a la fois
- rester court, humain et chaleureux
- demander une revue humaine quand le score de confiance est faible
- respecter les settings tenant
- respecter les horaires et regles de reservation
- ne pas exposer les details techniques

## 15. Plan d implementation par etapes

### Etape 0 - Branche et cadrage

Statut: termine.

Objectif:

- travailler sur une branche dediee
- poser les stories avant code

Livrables:

- branche `feature/malikia-ai-assistant`
- document user stories
- sequence de livraison MVP validee

### Etape 1 - Migrations et models

Statut: termine.

Objectif:

- creer le socle data auditable.

Livrables:

- migrations des 5 tables
- indexes tenant/status/channel
- models et relations
- factories
- tests tenant boundaries de base

Tests:

- creation settings
- creation conversation
- creation messages
- creation action
- isolation tenant

### Etape 2 - Settings admin

Statut: termine.

Objectif:

- permettre au owner de configurer l assistant.

Livrables:

- controller settings
- request validation
- policy
- page Inertia settings
- valeurs par defaut

Tests:

- owner peut lire/modifier settings
- employee non autorise refuse
- settings restent tenant-scopes

### Etape 3 - Public chat endpoints

Statut: termine.

Objectif:

- creer et alimenter une conversation publique.

Livrables:

- `AiPublicChatController`
- endpoint create conversation
- endpoint send message
- UUID public ou token signe
- rate limit
- stockage messages

Tests:

- AI can create a conversation
- AI stores messages
- disabled assistant rejects public conversations
- public endpoint does not expose internal IDs

### Etape 4 - Prompt builder et contexte

Statut: termine.

Objectif:

- construire le contexte IA sans fuite cross-tenant.

Livrables:

- `AiPromptBuilder`
- `AiConversationContext`
- chargement settings, services, booking rules, recent messages

Tests:

- prompt contient le contexte tenant
- prompt exclut les donnees autres tenants
- prompt inclut FR/EN selon conversation

### Etape 5 - Assistant service et intent detection

Statut: termine.

Objectif:

- traiter un message et produire une reponse structurée.

Livrables:

- `AiAssistantService`
- `AiIntentDetector`
- DTO `AiDetectedIntent`
- DTO `AiAssistantResponse`
- fallback human review low confidence

Tests:

- AI detects reservation intent
- AI supports French and English
- AI falls back to human review when confidence is low

### Etape 6 - Reservation orchestration

Statut: termine.

Objectif:

- brancher l IA au moteur de reservations existant.

Livrables:

- `AiReservationOrchestrator`
- resolution service
- collecte infos manquantes
- suggestion de 3 slots
- protection anti slot invente

Tests:

- AI proposes real slots
- AI does not create reservation without available slot
- AI handles no availability

### Etape 7 - Actions et execution

Statut: termine.

Objectif:

- journaliser et executer les actions IA.

Livrables:

- `AiActionExecutor`
- action create prospect
- action create reservation
- action human review
- jobs execution
- approve/reject admin

Tests:

- AI creates prospect when user is unknown
- AI creates pending action when validation is enabled
- AI executes action when approved
- AI logs failed action safely

### Etape 8 - Inbox admin

Statut: prochain.

Objectif:

- permettre a l equipe de suivre et reprendre les conversations.

Livrables:

- liste conversations
- detail messages
- pending actions
- reply humain
- filtres status/channel/date

Tests:

- admin can list conversations
- admin can view tenant conversation
- admin cannot view another tenant conversation
- admin can reply as human

### Etape 9 - Public widget MVP

Objectif:

- ajouter une UI simple utilisable sur lien public.

Livrables:

- composant chat public
- affichage messages
- input client
- etat loading
- erreurs fallback
- support public reservation context

Tests:

- widget starts conversation
- widget sends message
- widget displays assistant response

### Etape 10 - QA MVP et merge readiness

Objectif:

- securiser le merge vers `develop`.

Livrables:

- suite feature complete
- test reservations non-regression
- verification manual web chat
- checklist securite

Definition of done:

- tests verts
- tenant boundaries couverts
- aucune reservation creee sans slot reel
- action log complet
- validation humaine fonctionnelle
- UI admin utilisable

## 16. Tests requis

Tests MVP obligatoires:

- AI can create a conversation
- AI stores messages
- AI detects reservation intent
- AI creates prospect when user is unknown
- AI does not create reservation without available slot
- AI creates pending action when human validation is enabled
- AI executes action when approved
- AI respects tenant boundaries
- AI supports French and English
- AI falls back to human review when confidence is low

Tests securite:

- endpoint public rate-limited
- public UUID not guessable
- tenant A cannot read tenant B settings
- tenant A cannot approve tenant B action
- assistant does not expose internal IDs

Tests reservations:

- no slots available
- slot selected already taken
- manual confirmation required
- automatic confirmation allowed
- service inactive not offered
- team member inactive not offered

## 17. Securite et privacy

Regles:

- chaque query doit etre tenant-scopee
- endpoints publics rate-limites
- IDs internes jamais exposes
- actions IA toujours journalisees
- PII minimisee dans les payloads envoyes au provider IA
- policies obligatoires sur ecrans admin
- public conversation token UUID ou signe
- messages techniques remplaces par fallback public

## 18. Decisions techniques a trancher

### 18.1 React vs UI actuelle

Le cadrage demande React, mais le repo utilise aujourd hui surtout Inertia avec
Vue pour les pages existantes.

Decision a prendre avant implementation UI:

- option A: suivre l existant Inertia/Vue pour coherence et vitesse
- option B: ajouter React pour ce module, avec setup frontend dedie

Recommandation:

- MVP en suivant le stack frontend deja actif dans le repo, sauf decision produit
  explicite de migrer ou introduire React.

### 18.2 Provider IA

Questions:

- quel provider IA utiliser en production?
- comment limiter les couts par tenant?
- faut-il un mode mock deterministic pour tests?
- quelles donnees client sont autorisees dans le prompt?

Recommandation:

- definir une interface provider
- utiliser un fake provider en tests
- garder les actions metier deterministes cote Laravel

### 18.3 Validation humaine par defaut

Question:

- le MVP doit-il activer `require_human_validation` par defaut?

Recommandation:

- oui pour les premiers tests
- permettre l execution directe uniquement quand l owner l active clairement

## 19. Risques

### Risque 1 - L IA invente une disponibilite

Mitigation:

- disponibilites toujours calculees par `ReservationAvailabilityService`
- slot selectionne revalide avant creation reservation
- test obligatoire: no reservation without real slot

### Risque 2 - Confusion prospect/client

Mitigation:

- visiteur inconnu devient prospect
- creation client separee et desactivee par defaut
- conversion client garde le workflow existant

### Risque 3 - Fuite cross-tenant

Mitigation:

- `tenant_id` partout
- policies admin
- tests tenant boundaries
- prompt builder scope strict

### Risque 4 - Trop de magie IA

Mitigation:

- actions journalisees
- approval flow
- inbox humaine
- explanations courtes des decisions

### Risque 5 - UI incoherente avec le repo

Mitigation:

- trancher React/Vue avant implementation
- suivre les composants existants quand possible
- livrer d abord le backend testable

## 20. Parcours cible MVP

### 20.1 Nouveau visiteur veut reserver

1. Le visiteur ouvre un lien public.
2. L IA salue le visiteur.
3. L IA demande le service souhaite.
4. L IA collecte nom, telephone, email, preference date/heure et notes.
5. L IA verifie les disponibilites.
6. L IA propose 3 creneaux.
7. Le visiteur choisit un creneau.
8. L IA cree ou propose l action create prospect.
9. L IA cree ou propose l action create reservation.
10. L IA confirme clairement le statut.
11. L entreprise recoit une notification.

### 20.2 IA incertaine

1. L IA detecte une faible confiance.
2. L IA arrete les decisions.
3. L IA cree une action `request_human_review`.
4. L IA dit que l equipe va verifier et repondre.
5. L admin voit la conversation dans l inbox.

## 21. Definition of done MVP

Le MVP est pret pour review quand:

- les 5 tables existent avec migrations
- les models et relations sont couverts
- settings admin fonctionne
- public chat peut creer une conversation
- messages user/assistant sont stockes
- intent reservation est detecte en FR/EN
- prospect peut etre cree
- reservation peut etre creee seulement avec slot reel
- human validation fonctionne
- inbox admin affiche conversations et actions
- tests requis passent
- aucune query critique n est cross-tenant
- le module est encore isole sur `feature/malikia-ai-assistant`
