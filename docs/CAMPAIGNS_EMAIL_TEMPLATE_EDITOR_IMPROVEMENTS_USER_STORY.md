# Campaigns Email Template Editor Improvements - User Story

Derniere mise a jour: 2026-03-16

## Goal
Ameliorer l editeur de templates email du module Campaigns pour le rendre plus pratique, plus fiable et plus rapide a utiliser, sans perdre la simplicite du builder actuel.

L objectif n est pas de revenir vers un builder complexe. Il faut conserver une experience legere avec 3 sections de contenu simples, mais ajouter les ameliorations qui augmentent vraiment la qualite d usage au quotidien.

## Scope
- preview desktop et mobile
- affichage clair du subject et du preheader
- insertion rapide des variables dynamiques
- options visuelles simples par section
- gestion d image plus propre
- envoi de test depuis l editeur
- autosave brouillon et protection contre la perte de modifications

## Principles
- garder le builder simple
- ne pas reintroduire un systeme de blocs complexe
- respecter le design system de la plateforme
- conserver la compatibilite avec le moteur de rendu email existant
- prioriser la clarte visuelle sur la richesse fonctionnelle

## Current Baseline
- un builder email simple existe deja avec 3 sections de contenu
- chaque section peut avoir de 1 a 3 colonnes
- chaque colonne contient un bloc simple avec texte, image et CTA
- les images peuvent deja etre uploades par drag and drop
- les sections peuvent etre masquees
- le rendu email est deja plus propre et plus coherent avec la plateforme

## Primary User Story

### US-CMP-EMAIL-001 - Better daily editing experience
As a tenant owner or marketing manager, I want a simpler but more practical email template editor so I can prepare professional branded campaigns faster and with less friction.

Acceptance criteria:
- the editor keeps the current simple structure with 3 content sections
- the user can preview the email in desktop and mobile modes
- the preview shows the subject and preview text clearly
- the user can insert supported dynamic variables in one click
- section styling remains simple and constrained
- image handling is fast and understandable
- the user can send a test email from the editor
- the user does not lose work accidentally

## Supporting User Stories

### US-CMP-EMAIL-002 - Desktop and mobile preview
As a marketer, I want to preview my email in desktop and mobile layouts so I can validate readability before sending.

Acceptance criteria:
- the preview has at least 2 modes: `desktop` and `mobile`
- switching preview mode does not reload the whole page
- desktop and mobile widths are visually distinct
- the preview remains consistent with the rendered email HTML

### US-CMP-EMAIL-003 - Subject and preheader visibility
As a marketer, I want to see the subject and preheader in the preview area so I can validate the first impression of the email.

Acceptance criteria:
- the preview area displays the current `subject`
- the preview area displays the current `previewText`
- empty values are clearly indicated
- the information remains visible in both desktop and mobile preview modes

### US-CMP-EMAIL-004 - One-click token insertion
As a marketer, I want to insert dynamic variables quickly so I do not need to type tokens manually.

Acceptance criteria:
- supported tokens are listed in the editor
- clicking a token inserts it into the active field
- insertion works for subject, title, body, CTA label, and CTA URL when relevant
- no invalid token formatting is generated

### US-CMP-EMAIL-005 - Simple section styling
As a marketer, I want a few useful visual settings per section so I can adapt the email without making the builder complicated.

Acceptance criteria:
- each section supports only a limited set of styling controls
- allowed controls may include:
  - background mode
  - text alignment
  - spacing top and bottom
  - CTA visual style
- styling choices remain consistent with the platform look and feel
- the email renderer applies these settings consistently in preview and final output

### US-CMP-EMAIL-006 - Cleaner image workflow
As a marketer, I want to manage images more easily so visual editing feels straightforward.

Acceptance criteria:
- the user can upload an image from the computer
- the user can replace an existing image
- the user can remove an existing image
- the preview updates immediately after image changes
- upload errors are shown clearly without breaking the builder

### US-CMP-EMAIL-007 - Test send from editor
As a marketer, I want to send myself a test email directly from the editor so I can validate the real result before saving or using the template.

Acceptance criteria:
- a test send action is available in the editor
- the system sends the rendered email to a controlled test recipient
- the user receives success or error feedback clearly
- sending a test does not publish or schedule a real campaign

### US-CMP-EMAIL-008 - Autosave and unsaved changes protection
As a marketer, I want the editor to protect my work so I do not lose a template while editing.

Acceptance criteria:
- changes can be autosaved as draft
- the editor indicates when a save is in progress or completed
- the user is warned before leaving with unsaved changes
- autosave does not create duplicate templates

## Delivery Priority

### Phase 1 - High value UX
- US-CMP-EMAIL-002
- US-CMP-EMAIL-003
- US-CMP-EMAIL-004

### Phase 2 - Controlled styling and image polish
- US-CMP-EMAIL-005
- US-CMP-EMAIL-006

### Phase 3 - Safety and validation
- US-CMP-EMAIL-007
- US-CMP-EMAIL-008

## Technical Notes
- reuse the current email template builder and renderer
- keep the existing 3-section content model
- avoid adding nested builders or drag-and-drop layouts
- keep compatibility with stored templates already created
- prefer incremental schema changes over a new rendering engine

## Test Strategy
- unit:
  - section style normalization
  - token insertion helpers
  - preview mode state
- feature:
  - test send endpoint
  - autosave flow
  - image replace/remove flow
- frontend:
  - desktop/mobile preview toggle
  - token insertion into active field
  - unsaved changes warning

## Done Definition
- the editor remains simple
- the preview is more realistic
- token insertion is faster
- image handling is cleaner
- test send is available
- draft safety is improved
- UI stays aligned with the platform
