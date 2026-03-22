# Speech detaille - Module Quotes / Devis (33 minutes)

## Objectif de cette version
Ce document est un script video complet pour presenter le module devis de Malikia pro:
- creation et structuration du devis
- envoi client et prise de decision
- acceptance/decline et conversion en job
- impact metier sur requests, checklist, transactions et workflow global

## Duree cible
33 minutes (minimum 20 minutes respecte).

## Public cible
- commerciaux
- operationnels
- owner qui veut comprendre la logique devis -> job
- prospect qui veut voir un flux complet et concret

## Pre-requis avant tournage
- compte interne owner/admin.
- feature `quotes` active.
- donnees minimales:
  - 3 clients avec proprietes
  - 6 produits/services
  - 2 taxes
  - 1 devis draft
  - 1 devis sent
  - 1 devis accepted
  - 1 devis declined
- optionnel mais recommande:
  - 1 lead lie a un devis
  - 1 devis archive
  - 1 example de lien public quote

---

## 00:00 - 03:00 | Introduction: role central du module devis

### Speech a lire
"Dans cette video, on se concentre sur le module Quotes, c est-a-dire le coeur de la transformation commerciale.

Le devis est la charniere entre le besoin client et l execution operationnelle.

Dans Malikia pro, un devis bien construit sert a:
1. figer l offre commerciale dans le temps
2. engager le client avec une decision claire
3. declencher automatiquement la suite du workflow, notamment la creation du job

On va voir ensemble comment un devis passe de draft a sent, puis accepted ou declined, et comment cette decision impacte les leads, les jobs, la checklist, et la facturation." 

### Actions ecran
- Ouvrir `Quotes`.
- Montrer KPI en haut de page.

### Message cle
Le devis est un contrat operationnel, pas un simple document commercial.

---

## 03:00 - 06:00 | Vue liste: pilotage portefeuille de devis

### Speech a lire
"La vue liste permet de piloter tout le portefeuille devis.

On retrouve:
- total devis
- valeur totale
- valeur moyenne
- devis ouverts
- devis acceptes
- devis declines

On peut filtrer par:
- recherche texte
- statut (draft, sent, accepted, declined, archived)
- client
- montant min/max
- periode creation
- presence d acompte
- presence de taxes

Cette vue sert a prioriser les actions commerciales et a detecter les opportunites qui stagnent." 

### Actions ecran
- Rechercher un devis par numero.
- Filtrer `status=sent`.
- Filtrer `has_deposit=1`.
- Montrer tri par total puis par created_at.
- Basculer table/cards.

### Message cle
La liste devis est un tableau de pilotage revenu en temps reel.

---

## 06:00 - 09:00 | Creation devis: client, propriete, structure

### Speech a lire
"On cree maintenant un devis depuis un client.

Etapes fondamentales:
1. selection du client
2. selection de la propriete
3. titre du job
4. statut initial (souvent draft)

Le choix de la propriete est important car il aligne l adresse d intervention des la phase commerciale.

Le devis peut etre cree depuis un client, ou via conversion d un lead request.

Objectif: eviter toute resaisie entre commerce et operations." 

### Actions ecran
- Cliquer `New quote`.
- Selectionner client + propriete.
- Saisir `job_title`.
- Laisser statut draft.

### Message cle
Un devis bien ancre sur client/propriete fluidifie toutes les etapes suivantes.

---

## 09:00 - 13:00 | Lignes devis: produits/services et snapshot

### Speech a lire
"Le bloc le plus important est la table des lignes devis.

Chaque ligne definit:
- l item
- la quantite
- le prix unitaire
- le total de ligne

Dans Malikia pro, les lignes du devis sont snapshottees: on fige ce qui a ete vendu au moment du devis.
Cela evite qu un changement futur de prix catalogue modifie retroactivement un accord commercial deja emis.

On peut travailler avec services ou produits selon le type d entreprise.
Le systeme supporte aussi des details de source/prix si disponibles, ce qui renforce la transparence et la traçabilite commerciale." 

### Actions ecran
- Ajouter plusieurs lignes.
- Modifier quantite/prix.
- Montrer recalcul subtotal.
- Montrer detail ligne sur un devis existant.

### Message cle
Le snapshot protege la marge et la coherence contractuelle.

---

## 13:00 - 16:00 | Taxes, total et acompte (initial_deposit)

### Speech a lire
"Apres les lignes, on construit le total final:
- subtotal
- taxes selectionnees
- total
- acompte initial si necessaire

L acompte est une variable strategique:
- il securise l engagement client
- il reduit le risque financier
- il structure le flux de paiement

Regle importante:
l acompte demande ne peut pas depasser le total devis.

Lors de l acceptation, la plateforme peut enregistrer une transaction de type deposit si un acompte est regle.

Cela connecte directement la partie commerciale a la partie finance." 

### Actions ecran
- Activer 1-2 taxes.
- Montrer calcul du total avec taxes.
- Saisir `initial_deposit`.
- Sauvegarder devis.

### Message cle
Un devis correct doit etre commercialement clair et financiere-ment executable.

---

## 16:00 - 19:00 | Statuts devis et regles de transition

### Speech a lire
"Le cycle de statut devis est simple et robuste:
- draft
- sent
- accepted
- declined

Points de controle:
- un devis archive ne peut pas etre accepte
- un devis deja accepted ne peut pas etre decline
- un devis deja declined ne peut pas etre accepte

Ces regles evitent les incoherences contractuelles.

Le statut du devis peut aussi synchroniser le statut du lead associe:
- sent -> REQ_QUOTE_SENT
- accepted -> REQ_WON
- declined -> REQ_LOST

Donc une decision devis met a jour le pipeline commercial automatiquement." 

### Actions ecran
- Ouvrir un devis sent.
- Montrer boutons/actions accept/decline.
- Ouvrir un devis archived pour montrer les limites.

### Message cle
Les statuts devis pilotent directement la sante du pipeline vente.

---

## 19:00 - 22:00 | Envoi devis au client (email + portail/public)

### Speech a lire
"Le devis passe en phase client avec l envoi.

Depuis l application:
- on envoie le devis par email
- si le devis etait draft, il passe en sent
- un log d activite est cree

Le client peut ensuite agir via portail ou lien public signe:
- accepter
- refuser

Le lien public a une duree limitee, ce qui ajoute un niveau de securite operationnelle.

Ce flow est essentiel pour accelerer le time-to-decision sans friction." 

### Actions ecran
- Cliquer `Send email`.
- Montrer message de succes.
- Ouvrir un exemple de page publique `public/quotes/{id}` si disponible.

### Message cle
Le module devis connecte vente interne et decision client dans le meme flux.

---

## 22:00 - 26:00 | Acceptation devis: creation job + checklist + acompte

### Speech a lire
"Quand un devis est accepte, Malikia pro peut creer le job automatiquement.

Ce qui se passe:
1. statut devis -> accepted
2. creation ou mise a jour du job associe
3. copie des lignes devis vers le job
4. creation des checklist items a partir des lignes
5. enregistrement d acompte en transaction si applicable

Le job est cree en statut `to_schedule`, pret pour la planification.

C est une automation majeure:
on passe du commercial a l execution sans rupture ni resaisie." 

### Actions ecran
- Accepter un devis.
- Montrer redirection vers job edit.
- Montrer checklist issue des lignes devis.
- Montrer transaction deposit si presente.

### Message cle
L acceptation devis est le trigger de production terrain.

---

## 26:00 - 28:00 | Decline devis: gestion perte propre

### Speech a lire
"Si le client decline, le devis passe en `declined`.

Effets:
- pas de creation job
- pipeline lead mis a jour vers perte (si lie)
- activite historisee

Le point important ici est la lisibilite commerciale:
une perte traquee proprement vaut mieux qu un devis qui reste indeterminement en sent.

Cela clarifie les previsions et permet d analyser les causes de non-conversion." 

### Actions ecran
- Decliner un devis test.
- Montrer statut final.
- Montrer effet dans contexte lead si lie.

### Message cle
Un refus bien trace est une information business utile, pas un echec cache.

---

## 28:00 - 30:00 | Conversion manuelle devis -> job

### Speech a lire
"En plus de l acceptation directe, on peut convertir explicitement un devis en job.

Ce mode est utile quand l equipe veut garder le controle du timing de lancement operationnel.

Lors de la conversion:
- creation job si inexistant
- liaison quote/work
- synchronisation lignes et checklist
- statut devis peut basculer accepted selon cas

Malikia pro evite aussi les doublons: si un job existe deja pour ce devis, la plateforme le reutilise." 

### Actions ecran
- Cliquer `Convert to job` sur devis eligible.
- Montrer message `job already created` sur un devis deja converti.

### Message cle
La conversion garantit un passage propre du document commercial a l execution.

---

## 30:00 - 32:00 | Archivage, restauration et hygiene du portefeuille

### Speech a lire
"Pour garder un portefeuille lisible, on peut archiver les devis obsoletes.

Archivage:
- retire des listes actives
- conserve l historique

Restauration:
- remet le devis en circulation si necessaire

Regle metier:
les devis archives ne doivent pas etre actionnes (accept/convert) tant qu ils ne sont pas restaures.

Cette hygiene evite la pollution des vues et limite les erreurs de manipulation." 

### Actions ecran
- Archiver un devis.
- Filtrer `status=archived`.
- Restaurer le devis.

### Message cle
Archivage propre = portefeuille exploitable et moins de bruit.

---

## 32:00 - 33:00 | Conclusion business

### Speech a lire
"Le module Quotes apporte quatre gains majeurs:
1. standardiser l offre commerciale
2. accelerer la decision client
3. automatiser le passage en execution
4. fiabiliser la trace business de chaque opportunite

Si le devis est bien gere, tout le reste devient plus simple:
planification job, checklist equipe, validation client, facturation et paiement.

La prochaine etape logique est de presenter le module Jobs pour montrer l execution complete apres acceptation du devis." 

### Actions ecran
- Recap rapide liste devis + un devis accepte + job lie.

### Message cle
Devis maitrise = pipeline clair, execution rapide, revenus mieux controles.

---

## Annexe A - Statuts et champs a citer

### Statuts devis
- `draft`
- `sent`
- `accepted`
- `declined`
- `archived` (etat via `archived_at`)

### Champs importants
- `customer_id`
- `property_id`
- `job_title`
- `initial_deposit`
- `subtotal`
- `total`
- `parent_id` (change order / extras)
- `work_id`
- `accepted_at`
- `signed_at`

---

## Annexe B - Checklist tournage rapide
- Montrer creation devis complete.
- Montrer lignes + taxes + acompte.
- Montrer envoi email et passage draft -> sent.
- Montrer acceptation avec creation job.
- Montrer decline sur un autre devis.
- Montrer conversion manuelle et cas job deja existant.
- Montrer archivage/restauration.
