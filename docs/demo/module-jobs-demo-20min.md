# Speech detaille - Module Jobs / Works (34 minutes)

## Objectif de cette version
Ce document sert a presenter le module Jobs de Malikia pro de maniere complete:
- creation et planification d intervention
- execution terrain et suivi des statuts
- preuves, validation client, litiges
- declenchement facture et cloture du cycle

## Duree cible
34 minutes (minimum 20 minutes respecte).

## Public cible
- operations manager
- equipe terrain / coordinateurs
- owner qui veut comprendre devis -> job -> facture
- prospect qui cherche un workflow execution fiable

## Pre-requis avant tournage
- compte owner/admin.
- feature `jobs` active.
- donnees minimales:
  - 3 jobs `to_schedule/scheduled`
  - 2 jobs `in_progress`
  - 2 jobs `pending_review` ou `tech_complete`
  - 1 job `validated` ou `auto_validated`
  - 1 job `dispute`
  - 1 job `closed`
- equipe:
  - 2 a 4 team members actifs
- preuves:
  - taches avec medias pour l ecran proofs

---

## 00:00 - 03:00 | Introduction: role du module Jobs

### Speech a lire
"Dans cette video, on se concentre sur le module Jobs, c est-a-dire le coeur de l execution operationnelle.

Dans Malikia pro, un job n est pas juste une tache calendrier:
c est une unite complete avec client, planning, equipe, lignes de travail, statuts qualite, preuves, et impact facturation.

Le job est cree en general depuis un devis accepte. Ensuite il suit un cycle controle jusqu a validation client et facturation.

On va voir toutes les etapes avec les vraies regles metier: de la planification jusqu au closing." 

### Actions ecran
- Ouvrir `Jobs`.
- Montrer rapidement les cartes KPI.

### Message cle
Le module Jobs transforme la vente en execution mesurable.

---

## 03:00 - 06:00 | Vue liste jobs: pilotage operationnel quotidien

### Speech a lire
"La page Jobs est le cockpit operations.

KPI visibles:
- total jobs
- scheduled
- in progress
- completed
- cancelled

On peut filtrer par:
- recherche
- statut
- client
- plage de dates

Et on peut trier par job title, statut, total, start date, created_at.

Cette vue permet d organiser la charge, detecter les retards, et prioriser les interventions a risque." 

### Actions ecran
- Filtrer `status=in_progress`.
- Filtrer un client.
- Trier par start date.
- Montrer badge `overdue tasks` si present.

### Message cle
Sans pilotage quotidien des jobs, la qualite de service chute rapidement.

---

## 06:00 - 10:00 | Creation job: client, contenu, equipe

### Speech a lire
"On cree maintenant un job.

Le job contient:
- client
- titre intervention
- instructions
- dates/horaires
- statut initial
- lignes de travail (produits/services)
- equipe assignee
- regles de facturation

Si le job vient d un devis verrouille, certaines donnees restent alignees avec ce devis pour garantir la coherence commerciale.

A la sauvegarde, Malikia pro calcule subtotal/total a partir des lignes, et peut synchroniser l equipe selectionnee.

Objectif: preparer une intervention executable sans ambiguite." 

### Actions ecran
- Ouvrir modal `Create job`.
- Choisir client.
- Remplir formulaire principal.
- Ajouter 2-3 lignes.
- Affecter 2 membres equipe.
- Sauvegarder.

### Message cle
Un job bien cree diminue les erreurs terrain et les retours clients.

---

## 10:00 - 14:00 | Planification recurrente et disponibilite equipe

### Speech a lire
"Le module Jobs permet aussi la recurrence:
- Daily
- Weekly
- Monthly
- Yearly

On choisit:
- frequence
- repetitions (jours semaine/mois)
- mode de fin (Never, On, After)
- total visits

Le systeme calcule les occurrences, puis peut generer les tasks associees.

Autre point fort: la disponibilite equipe est affichee pour eviter les collisions.
En cas de conflit d assignation, les taches peuvent rester non assignees et une alerte peut etre envoyee.

C est fondamental pour garder un planning realiste, surtout avec volume multisite." 

### Actions ecran
- Ouvrir onglet planning sur create/edit.
- Configurer un exemple recurrent.
- Montrer preview calendrier.
- Montrer disponibilite team members.

### Message cle
Le planning n est pas decoratif: il pilote la capacite reelle d execution.

---

## 14:00 - 18:00 | Cycle de statuts Jobs et gates qualite

### Speech a lire
"Le job suit un cycle complet:
- to_schedule
- scheduled
- en_route
- in_progress
- tech_complete
- pending_review
- validated / auto_validated
- dispute
- closed / cancelled / completed

Deux regles qualite sont critiques:
1. Passer en `in_progress` demande au moins 3 photos before.
2. Passer en `tech_complete` demande:
   - checklist 100% done
   - au moins 3 photos after

Ces gates obligent une execution documentee, et evitent les validations trop rapides sans preuve.

Autre regle:
si le client a `auto_validate_jobs=true`, un statut `tech_complete` ou `pending_review` peut basculer en `auto_validated` automatiquement." 

### Actions ecran
- Ouvrir un job edit.
- Montrer selecteur statut.
- Illustrer les prerequis before/after/checklist (avec cas exemple).

### Message cle
Les statuts jobs sont des controles qualite, pas de simples etiquettes.

---

## 18:00 - 22:00 | Checklist, preuves, medias

### Speech a lire
"Le module jobs inclut une logique preuve/qualite tres forte.

Checklist:
- creee depuis les lignes devis (dans les jobs issus de devis)
- items `pending/done`
- progression requise avant certains statuts

Preuves:
- photos before/after au niveau job
- medias et preuves au niveau tasks
- vue proofs pour revue interne et client

La page proofs consolide:
- taches
- materiaux utilises
- medias (image/video)
- source upload
- utilisateur upload

C est ce qui securise la transparence et la defensibilite en cas de contestation." 

### Actions ecran
- Ouvrir `Job -> Proofs`.
- Montrer task media.
- Montrer details source/type/note.
- Montrer checklist update (pending -> done).

### Message cle
Les preuves transforment l execution en historique verifiable.

---

## 22:00 - 26:00 | Validation client, dispute, schedule confirmation

### Speech a lire
"Cote client, Malikia pro propose des actions portail/public:
- valider le job
- mettre en litige
- confirmer ou refuser la proposition de planning

Validation/dispute client possible quand le job est:
- pending_review
- ou tech_complete

Si validation:
- statut -> validated
- notification owner
- facture potentiellement generee selon regles billing

Si dispute:
- statut -> dispute
- alerte owner pour traitement

Pour le planning:
- client peut accepter le schedule, ce qui genere les tasks
- ou le rejeter, ce qui renvoie le job en `to_schedule` si aucune task n existe encore." 

### Actions ecran
- Montrer page publique `WorkAction` (si dispo).
- Montrer boutons `Validate`, `Dispute`, `Accept schedule`, `Request changes`.

### Message cle
Le client participe au workflow sans casser la gouvernance interne.

---

## 26:00 - 30:00 | Facturation depuis job: quand et comment

### Speech a lire
"Le job est relie directement a la facturation.

Quand le statut passe a `validated` ou `auto_validated`, la plateforme peut creer la facture automatiquement selon la politique billing.

En mode `end_of_job`, la logique est directe: validation job -> creation invoice.

En plus, depuis la liste jobs ou la fiche job, on peut aussi forcer `Create invoice` si besoin.

Ensuite:
- paiement partiel/complet suit le module invoices
- paiement complet peut mener au statut `closed`

Donc le job est le pivot entre operations et revenu." 

### Actions ecran
- Sur un job eligible, cliquer `Create invoice`.
- Ouvrir facture liee.
- Montrer liaison job <-> invoice.

### Message cle
Un job bien gere raccourcit le delai execution -> encaissement.

---

## 30:00 - 33:00 | Cas avances: extra quote pendant execution

### Speech a lire
"Scenario avance frequent: pendant le chantier, le client demande un extra.

Malikia pro permet de creer un devis additionnel lie au job (change order).
Ce devis enfant conserve le contexte du job et alimente la checklist avec les nouveaux elements.

Resultat:
- scope supplementaire trace
- impact prix formalise
- execution et billing restent coherents

C est une protection importante contre les derives de scope non facturees." 

### Actions ecran
- Expliquer le principe sur un job existant.
- Montrer un exemple de quote enfant si present.

### Message cle
Le module jobs gere aussi les changements de scope en cours d execution.

---

## 33:00 - 34:00 | Conclusion

### Speech a lire
"Le module Jobs de Malikia pro apporte:
1. planification robuste
2. execution controlee par preuves
3. validation client cadre
4. declenchement facture fiable

Si ce module est bien applique, on gagne:
- meilleure ponctualite
- meilleure qualite percue
- moins de litiges
- meilleure vitesse de facturation

La suite logique de demo est le module Invoices/Paiements pour boucler le cycle revenu." 

### Actions ecran
- Recap visuel: liste jobs -> fiche -> proofs -> facture.

### Message cle
Jobs maitrise = operations predictibles et revenus securises.

---

## Annexe A - Statuts a citer pendant la demo
- `to_schedule`
- `scheduled`
- `en_route`
- `in_progress`
- `tech_complete`
- `pending_review`
- `validated`
- `auto_validated`
- `dispute`
- `closed`
- `cancelled`
- `completed`

---

## Annexe B - Regles critiques a rappeler a l oral
- `in_progress` requiert au moins 3 photos before.
- `tech_complete` requiert checklist 100% done + 3 photos after.
- `auto_validate_jobs=true` peut forcer `auto_validated`.
- validation/dispute client autorisees surtout en `pending_review` ou `tech_complete`.
- validation job peut declencher creation facture selon mode billing.

---

## Annexe C - Checklist tournage rapide
- Montrer KPI jobs + filtres.
- Montrer creation job complete.
- Montrer recurrence + preview planning.
- Montrer gates qualite (before/after/checklist).
- Montrer page proofs.
- Montrer actions client validate/dispute/schedule.
- Montrer creation facture depuis job.
