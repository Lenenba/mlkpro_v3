# Lead Adaptatif -> Quote ou Appel - User Story

## Objectif
Permettre a un prospect de decrire son besoin via un formulaire adaptatif qui:
- propose des services pertinents en temps reel depuis le catalogue Malikia Pro
- ne fabrique jamais d informations absentes
- se termine par 2 options:
  - `Recevoir mon devis`
  - `Demander un appel`

## Story
En tant que prospect sur Malikia Pro,
je veux remplir un formulaire de besoin adaptatif qui me propose des services pertinents au fur et a mesure de ma saisie (sans invention),
afin de recevoir automatiquement un devis par email ou de demander un appel sans creation de devis.

## Regles de gestion

### 1) Source des services proposes
- Les services affiches proviennent uniquement du catalogue configure par l entreprise:
  - modules actives
  - offres actives
  - regles et fourchettes de chiffrage
- Aucun service hors catalogue n est propose.

### 2) Regle anti-invention
- Le systeme ne complete pas des informations non fournies.
- Si une information necessaire au chiffrage est absente:
  - poser des questions complementaires
  - marquer les lignes concernees en `Sur devis` si l info reste manquante.

### 3) Finalisation obligatoire par choix explicite
- Le prospect valide son besoin via un ecran final a 2 choix:
  - `Recevoir mon devis` (creation de quote)
  - `Demander un appel` (pas de quote)

### 4) Notifications owner obligatoires
- Pour les events `quote_created_from_lead_form` et `lead_call_requested`:
  - email owner obligatoire
  - notification in-app owner obligatoire.

## User Stories

### US-LEAD-1 - Proposition dynamique de services
As a prospect, I receive service suggestions while filling the form so I can validate a realistic scope quickly.

Acceptance criteria:
- Le systeme detecte une ou plusieurs categories compatibles (ex: site vitrine, reservation, paiement, CRM, integration).
- Les suggestions sont mappees uniquement vers des services/modules existants du catalogue entreprise.
- Les services proposes sont affiches avec options/parametres quand disponibles.

### US-LEAD-2 - Gestion des informations manquantes
As a prospect, I clearly see what is missing for accurate pricing so I can complete the request or accept `Sur devis`.

Acceptance criteria:
- En etape finale, la section `Informations manquantes` est visible et explicite.
- Chaque ligne impactee peut etre marquee `Sur devis`.
- Le recap final inclut hypotheses + donnees manquantes.

### US-LEAD-3 - Parcours A: recevoir un devis
As a prospect, I can request a quote directly from my validated need.

Acceptance criteria:
- Pre-conditions:
  - au moins un service propose/valide
  - email prospect valide
- Action:
  - clic sur `Recevoir mon devis`
- Resultats:
  - creation d un `Quote` avec:
    - items services proposes/valides
    - options/parametres saisis
    - resume du besoin
    - hypotheses et informations manquantes
    - statut initial `sent` (ou `draft` puis envoi immediat)
  - envoi email prospect (devis + lien)
  - envoi email owner (nouveau devis genere)
  - creation notification in-app owner
  - creation event log `quote_created_from_lead_form`.

### US-LEAD-4 - Echec email sur parcours devis
As an owner, I keep operational visibility if quote emails fail after quote creation.

Acceptance criteria:
- Si envoi email echoue apres creation du quote:
  - le quote reste cree
  - notification in-app `email failed` pour owner
  - tentative de retry selon la strategie applicative.

### US-LEAD-5 - Parcours B: demander un appel sans devis
As a prospect, I can submit my need and request a call without receiving a quote.

Acceptance criteria:
- Action:
  - clic sur `Demander un appel`
- Resultats:
  - creation/mise a jour du lead avec:
    - resume besoin
    - services pressentis
    - hypotheses et manquants si necessaire
  - aucun `Quote` cree
  - statut lead passe a `call_requested` (ou `needs_qualification`)
  - email confirmation prospect
  - email owner
  - notification in-app owner
  - creation tache `Qualifier le lead / Planifier appel`
  - creation event log `lead_call_requested`.

## Criteres Given / When / Then

### A) Proposition de services (pendant la saisie)
- Given le prospect saisit sa description et repond aux questions
- When le systeme detecte une categorie compatible
- Then il propose une liste de services/modules issus du catalogue Malikia Pro uniquement

- Given des informations necessaires au chiffrage manquent
- When le prospect arrive a l etape finale
- Then le systeme affiche `Informations manquantes` et peut marquer des lignes `Sur devis`

### B) Finalisation option `Recevoir mon devis`
- Given au moins un service est propose/valide et le prospect fournit un email valide
- When il clique `Recevoir mon devis`
- Then Malikia Pro cree un quote complet avec services, options, resume besoin, hypotheses et manquants

- Given le devis est cree
- When la creation est confirmee
- Then le systeme envoie email prospect + email owner, cree notification in-app owner et log `quote_created_from_lead_form`

- Given l envoi email echoue
- When l echec est detecte
- Then le quote reste cree, une notification in-app `email failed` est ajoutee, puis retry selon strategie

### C) Finalisation option `Demander un appel`
- Given le prospect choisit `Demander un appel`
- When il valide
- Then le systeme cree/maj le lead, ne cree aucun quote, passe le statut a `call_requested` (ou `needs_qualification`), envoie emails prospect/owner, cree notification in-app owner et tache de qualification

## Etats et traces minimales
- Lead status:
  - `new`
  - `in_progress`
  - `call_requested` (ou `needs_qualification`)
  - `quote_created` (si parcours devis)
- Quote status (parcours A uniquement):
  - `sent` (ou `draft` + envoi immediat)
- Event log minimal:
  - `quote_created_from_lead_form`
  - `lead_call_requested`
  - `lead_email_failed` (si applicable)

## Definition of Done
- UI formulaire adaptatif livree avec ecran final a 2 choix.
- Creation/maj Lead + timeline/event log.
- Creation Quote uniquement pour parcours `Recevoir mon devis`.
- Emails prospect + owner couverts pour les 2 parcours.
- Notifications in-app owner pour les 2 parcours.
- Gestion echec email avec trace + retry.
- Creation tache de suivi `Qualifier le lead / Planifier appel` pour parcours appel.
- Statuts et traces visibles dans la fiche lead.
