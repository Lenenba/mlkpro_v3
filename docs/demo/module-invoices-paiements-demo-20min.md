# Speech detaille - Module Invoices / Paiements (35 minutes)

## Objectif de cette version
Ce document sert a presenter le module factures/paiements de MLK Pro de maniere complete:
- pilotage des factures ouvertes et du cash encaisse
- lecture detaillee d une facture et de ses lignes
- paiement interne, paiement client, paiement Stripe
- gestion du cash pending, pourboires, securite et cloture du job

## Duree cible
35 minutes (minimum 20 minutes respecte).

## Public cible
- owner / finance manager
- operation manager qui suit job -> revenue
- equipe admin qui encaisse les paiements
- prospect qui veut comprendre comment la plateforme securise le revenu

## Pre-requis avant tournage
- compte owner/admin connecte.
- feature `invoices` active.
- donnees minimales:
  - 1 facture `sent`
  - 1 facture `partial`
  - 1 facture `paid`
  - 1 facture `overdue`
  - 1 facture `void` (optionnel mais recommande)
  - 1 paiement `cash` en `pending` pour montrer `Mark as paid`
  - 1 facture liee a un job pour montrer fermeture automatique du job
- config paiement:
  - au moins 2 modes actifs (`cash` + `card`) pour montrer le selecteur
  - Stripe configure si vous voulez montrer le checkout Stripe live

---

## 00:00 - 03:00 | Introduction: pourquoi ce module est critique

### Speech a lire
"Dans cette video, on se concentre sur le module Invoices / Paiements, qui est la derniere etape du cycle metier.

Tout le travail commercial et operationnel sert un objectif final: transformer une execution terrain en revenu securise.

Ce module ne sert pas seulement a afficher une facture PDF.
Il sert a piloter les soldes, encaisser partiellement ou totalement, gerer les exceptions, et garder un lien direct avec le statut du job.

On va voir ensemble le flux complet:
liste factures, details, paiements internes, paiements clients, Stripe, cash pending, pourboires, et controles anti-erreur." 

### Actions ecran
- Ouvrir `Invoices`.
- Montrer rapidement les KPI en haut.

### Message cle
Le module facture est un moteur d encaissement, pas juste un document administratif.

---

## 03:00 - 06:00 | Vue liste: cockpit cash et recouvrement

### Speech a lire
"La vue liste donne une vision immediate de la sante de facturation.

Les KPI principaux:
- total factures
- total value
- outstanding
- open
- paid
- partial

Ce qui est tres utile ici, c est qu on ne pilote pas seulement le nombre de factures.
On pilote aussi la valeur financiere restante a encaisser.

Pour un owner, cette page repond a 3 questions:
1. Combien j ai deja facture.
2. Combien est deja encaisse.
3. Combien reste encore a collecter." 

### Actions ecran
- Lire les 6 KPI un par un.
- Montrer la coherence entre `partial/open` et `outstanding`.

### Message cle
La page liste donne une lecture cash immediate, exploitable chaque jour.

---

## 06:00 - 09:00 | Filtres, tri, vues table/cartes

### Speech a lire
"Le module est fait pour le pilotage operationnel quotidien.

On peut filtrer par:
- recherche (numero, client)
- statut
- client
- total min/max
- dates de creation

On peut trier par:
- numero
- statut
- total
- date de creation

Et on peut changer d affichage entre vue table et vue cartes.
En pratique:
- la table sert au controle fin et a la comparaison ligne par ligne
- les cartes servent a une lecture plus rapide en reunion de suivi.

Cette flexibilite evite de sortir des exports externes pour des analyses simples." 

### Actions ecran
- Activer filtres avances.
- Filtrer `status=partial`, puis `status=overdue`.
- Trier par `total` puis par `created_at`.
- Basculer table <-> cards.

### Message cle
Filtres + tri = priorisation rapide des factures a traiter.

---

## 09:00 - 12:00 | Fiche facture: lecture metier complete

### Speech a lire
"Quand on ouvre une facture, on voit un ecran complet:
- client
- job lie
- adresse
- montant total, deja paye, solde restant
- statut actuel
- historique paiements

On a aussi des actions pratiques:
- `Download PDF`
- acces a la timeline de l entite

Important:
la facture n est pas isolee.
Elle reste rattachee au job, au client, et a l historique d activites.
C est ce lien qui rend le suivi fiable en cas de litige ou de question comptable." 

### Actions ecran
- Ouvrir une facture `partial`.
- Montrer `timeline`.
- Cliquer `Download PDF`.

### Message cle
La fiche facture centralise toutes les infos de preuve, montant et historique.

---

## 12:00 - 15:00 | Origine de la facture: conversion depuis Job

### Speech a lire
"La facture est en general creee depuis un job.

Le systeme calcule le montant avec une logique metier:
- base issue du devis accepte ou du total job
- deduction des acomptes deja enregistres
- creation de lignes (items) selon produits, taches done, materiaux billables

Si un job a deja une facture, la creation est bloquee pour eviter les doublons.

Le resultat est important:
la facturation est alignee avec l execution reelle, pas saisie au hasard en fin de mois." 

### Actions ecran
- Ouvrir un job valide/closed qui a une facture.
- Montrer lien job <-> facture.
- Expliquer oralement la deduction de deposit si exemple disponible.

### Message cle
La generation facture est structuree et protegee contre les incoherences.

---

## 15:00 - 19:00 | Paiement interne: complet ou partiel

### Speech a lire
"Sur la fiche, l equipe interne peut enregistrer un paiement.

On peut:
- saisir un montant libre
- utiliser les raccourcis full balance, 50%, 25%
- choisir la methode (selon config entreprise)
- ajouter reference, date de paiement, notes

Point cle:
la plateforme empeche de depasser le balance due.

Quand le paiement est enregistre, le statut facture est recalcule:
- 0 paye: reste `sent` ou `overdue`
- montant partiel: passe `partial`
- montant total atteint: passe `paid`

Cette mecanique permet un suivi progressif, notamment sur gros montants payes en plusieurs fois." 

### Actions ecran
- Sur facture `sent`, saisir un paiement partiel.
- Montrer le reste a payer.
- Ajouter un 2e paiement pour atteindre `paid`.

### Message cle
Le module gere naturellement les paiements fractionnes sans casser la logique facture.

---

## 19:00 - 22:00 | Cas cash: pending puis Mark as paid

### Speech a lire
"Si la methode choisie est `cash`, le systeme enregistre le paiement en `pending`.

Pourquoi c est utile:
- le client a annonce un paiement cash
- mais l encaissement reel doit etre confirme

Ensuite, un owner/admin peut cliquer `Mark as paid`.
Ce passage `pending -> paid` est trace dans les activites.

Et seulement apres confirmation, ce paiement est considere comme encaisse dans les calculs de solde.

C est tres important pour eviter un faux sentiment de cash collecte." 

### Actions ecran
- Creer un paiement cash.
- Montrer badge pending cash.
- Cliquer `Mark as paid`.
- Verifier mise a jour statut facture.

### Message cle
Le flux cash separe declaration et encaissement reel.

---

## 22:00 - 25:00 | Pourboires: calcul et impact

### Speech a lire
"Le module supporte aussi le tip.

Le tip peut etre:
- en pourcentage
- en montant fixe

La plateforme calcule:
- subtotal
- tip
- total charge

Et elle peut associer le tip a un membre equipe (tip assignee), avec allocation interne si activee.

Pour l entreprise, cela apporte deux benefices:
1. transparence client sur ce qui est paye
2. tracabilite interne des montants attribues equipe" 

### Actions ecran
- Faire un paiement avec tip.
- Montrer dans historique: amount, tip, total paid, tip assignee.

### Message cle
Le tip n est pas un champ libre, il est gere comme une composante finance tracee.

---

## 25:00 - 29:00 | Paiement client: portail et lien public signe

### Speech a lire
"Cote client, il y a deux canaux principaux:

1. Portail client authentifie
- le client connecte voit sa facture
- il peut payer selon methodes autorisees

2. Lien public signe
- lien temporaire securise (signature + expiration)
- partageable par email pour paiement rapide

Regles de securite metier:
- facture `draft` ou `void`: paiement refuse
- si balance due = 0: paiement refuse
- si client en mode auto validate invoices: actions bloquees cote client

Donc meme si quelqu un tente un appel direct, le backend garde les verrous metier." 

### Actions ecran
- Ouvrir un lien public `pay/invoices/{id}`.
- Montrer ecran de paiement client.
- Expliquer les cas de blocage (invoice non payable).

### Message cle
Le paiement client est simple en facade, mais fortement controle en back end.

---

## 29:00 - 32:00 | Stripe checkout et synchronisation

### Speech a lire
"Quand Stripe est actif, le client peut cliquer `Pay with Stripe`.

Le systeme cree une session Stripe avec:
- montant facture (ou montant partiel)
- tip eventuel
- metadonnees facture utiles pour reconciliation

Au retour checkout, la plateforme synchronise le paiement.
En plus, le webhook Stripe confirme les paiements asynchrones.

Resultat:
- paiement cree en `completed`
- statut facture recalcule automatiquement
- si la facture devient `paid`, le job associe passe en `closed`" 

### Actions ecran
- Cliquer `Pay with Stripe` (ou montrer en simulation).
- Montrer apres retour: nouveau paiement et statut facture.

### Message cle
Stripe est integre avec synchronisation metier, pas juste un bouton externe.

---

## 32:00 - 34:00 | Gouvernance des methodes de paiement (multi-tenant)

### Speech a lire
"Chaque entreprise configure ses methodes autorisees.

Exemples:
- entreprise A: carte uniquement
- entreprise B: cash + carte
- entreprise C: cash uniquement

L interface n affiche que les options autorisees.
Et surtout, le backend refuse toute methode non permise avec une erreur standard.

Ce point est critique en multi-tenant:
on garantit la meme policy sur tous les canaux (interne, portail, public, Stripe)." 

### Actions ecran
- Montrer un cas avec une seule methode (pas de selecteur).
- Montrer un cas avec plusieurs methodes (selecteur visible).

### Message cle
La policy paiement est centralisee et appliquee partout.

---

## 34:00 - 35:00 | Conclusion

### Speech a lire
"Le module Invoices / Paiements ferme la boucle revenue:
1. job execute
2. facture generee
3. paiement encaisse
4. job cloture

Ce qui fait la difference dans MLK Pro:
- suivi temps reel du solde
- paiements partiels fiables
- flux cash pending robuste
- paiements clients multi-canal
- controle strict des methodes et des statuts

La prochaine suite logique est de presenter le module Reporting/Finance pour analyser performance, cash-in et recouvrement dans la duree." 

### Actions ecran
- Recap visuel: jobs -> invoice -> payments -> status paid -> job closed.

### Message cle
Ce module securise la conversion du travail realise en revenu reel.

---

## Annexe A - Statuts a citer pendant la demo
- Facture (operationnel): `draft`, `sent`, `partial`, `paid`, `overdue`, `void`
- Paiement: `pending`, `paid`, `completed`, `failed`, `refunded`, `reversed`
- Rappel technique: le solde encaisse compte surtout les statuts regles (`paid` + `completed`)

---

## Annexe B - Regles critiques a rappeler a l oral
- Paiement interdit si facture `draft` ou `void`.
- Paiement interdit si `balance_due <= 0`.
- Montant paiement ne peut pas depasser le solde restant.
- Paiement cash cree en `pending`, puis confirmation manuelle `Mark as paid`.
- Quand facture devient `paid`, le job lie peut passer automatiquement `closed`.
- Policy methodes de paiement appliquee par tenant sur tous les canaux.

---

## Annexe C - Checklist tournage rapide
- Montrer KPI factures + outstanding.
- Montrer filtres avances + tri + vue table/cartes.
- Ouvrir fiche facture + PDF + timeline.
- Montrer paiement partiel puis total.
- Montrer cash pending puis `Mark as paid`.
- Montrer paiement avec tip.
- Montrer lien public de paiement et/ou portail client.
- Montrer bouton Stripe (si configure) et expliquer sync webhook.
