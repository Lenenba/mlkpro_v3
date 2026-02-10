# Reservation Phase 5 - QA metier guidee

## Objectif
Valider la phase 5 du module reservation:
- notifications queue avancees (pre-call, call, grace expired)
- ecran live salon/restaurant
- coherence des parcours owner/membre/client avec le planning deja en place

## Preconditions
1. Compte entreprise avec module reservations actif.
2. Au moins 1 owner/admin, 1 membre equipe, 1 client portal.
3. Services actifs et disponibilites hebdo configurees.
4. `queue_mode_enabled` actif dans `/settings/reservations`.

## Setup recommande
1. Ouvrir `/settings/reservations`.
2. Cocher:
   - `queue_mode_enabled`
   - `notify_on_queue_pre_call`
   - `notify_on_queue_called`
   - `notify_on_queue_grace_expired`
3. Laisser `queue_grace_minutes` a `5`.
4. Sauvegarder.

## Scenario A - Owner/Admin (operations globales)
1. Aller sur `/app/reservations`.
2. Verifier la section `Hybrid queue`:
   - cards `waiting/called/in_service` visibles
   - bouton `Open live screen` visible
3. Cliquer `Open live screen`:
   - page `/app/reservations/screen` charge sans erreur
   - cartes `Now serving`, `Up next`, `Overview` visibles
4. Depuis `/app/reservations`, sur un item queue:
   - action `Pre-call`
   - puis action `Call`
5. Resultats attendus:
   - statut item evolue correctement
   - compteur queue se met a jour
   - client recoit notif pre-call puis call (in-app/email selon config)
6. Simuler expiration grace:
   - forcer un item `called` avec `call_expires_at` passe
   - recharger board/screen
7. Resultat attendu:
   - item passe en `skipped` (ou `no_show` si policy active)
   - notification `queue_grace_expired` envoyee

## Scenario B - Team Member (scope mine)
1. Connecter membre equipe.
2. Aller sur `/app/reservations`.
3. Verifier:
   - scope par defaut = `mine`
   - seuls items assignes (ou non assignes autorises) sont actionnables
4. Sur un ticket assignable:
   - `Call` puis `Start` puis `Done`
5. Resultats attendus:
   - transitions reussies
   - pas d acces sur items hors perimetre
   - stats queue mises a jour

## Scenario C - Client (ticket + suivi live)
1. Connecter client.
2. Aller sur `/client/reservations/book`.
3. Dans bloc queue:
   - creer ticket (service + duree)
4. Resultats attendus:
   - ticket cree avec numero
   - position/ETA affiches
5. Depuis `/client/reservations`:
   - verifier carte ticket
   - utiliser `Still here`
6. Resultat attendu:
   - statut/horodatage ticket mis a jour
7. Option:
   - utiliser `Cancel/Leave`
8. Resultat attendu:
   - ticket passe `left`
   - ticket non actionnable ensuite

## Scenario D - Ecran live salon/restaurant
1. Ouvrir `/app/reservations/screen?anonymize=1`.
2. Verifier:
   - noms clients masques
   - liste d attente affiche ticket, service, membre, position, ETA
3. Basculer via bouton `Show names / Hide names`.
4. Resultats attendus:
   - affichage alterne anonymise/non anonymise
   - refresh manuel fonctionne
   - refresh automatique (10s) garde l ecran a jour

## Checks de non-regression
1. Booking client classique sans ticket toujours fonctionnel.
2. Waitlist reste operationnelle si active.
3. Planning et generation de slots non casses.
4. Paiements/tips inchanges sur flux facture.

## Definition of done QA
1. Tous scenarios A/B/C/D passes.
2. Notifications queue recues selon settings.
3. Aucun warning bloquant front/back sur les pages reservations.
