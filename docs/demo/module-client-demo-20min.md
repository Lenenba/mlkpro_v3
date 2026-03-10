# Speech detaille - Module Client (30 minutes)

## Objectif de cette version
Ce document est une version elaboree pour presenter:
- le module Client en profondeur
- la logique complete de la plateforme MLK Pro
- les liens reels entre Client et tous les autres modules

Cette version est faite pour une video longue, pedagogique, et orientee metier.

## Duree cible
30 minutes (minimum 20 minutes respecte).

## Public cible
- Prospect qui decouvre la plateforme
- Client final qui veut comprendre le flux complet
- Equipe interne (sales, onboarding, support) qui doit faire une demo claire

## Pre-requis avant tournage
- Connecte-toi avec un compte owner ou admin.
- Ouvre `Customers`.
- Aie 2 jeux de donnees:
  - 1 client particulier
  - 1 client entreprise multi-sites
- Si possible, prepare aussi:
  - 1 request
  - 1 quote
  - 1 job
  - 1 invoice
pour montrer les liens depuis la fiche client.

---

## 00:00 - 03:00 | Introduction: ce que MLK Pro fait vraiment

### Speech a lire
"Dans cette video, on va commencer par le module Client, mais je vais aussi vous montrer comment toute la plateforme fonctionne autour de lui.

MLK Pro est une plateforme multi-entreprise qui permet de gerer un cycle complet: acquisition du prospect, creation de devis, execution terrain, facturation, paiement, puis fidelisation et campagnes.

Le module Client est le socle de ce systeme. Si ce socle est propre, tout est fluide: les equipes vont plus vite, les erreurs de saisie diminuent, et les workflows deviennent automatisables.

Notre objectif aujourd hui est simple: vous montrer comment on cree une base client solide, comment on structure les adresses et les proprietes, et comment ces donnees alimentent automatiquement les modules Requests, Quotes, Jobs, Invoices, Portal client, et Marketing." 

### Actions ecran
- Ouvrir le dashboard.
- Montrer rapidement les modules principaux dans le menu.
- Entrer dans `Customers`.

### Message cle
Le module Client n est pas un carnet d adresses: c est la source de verite operationnelle.

---

## 03:00 - 06:00 | Architecture plateforme: multi-entreprise, roles, permissions

### Speech a lire
"Avant d entrer dans la fiche client, il faut comprendre la logique d acces.

Chaque entreprise fonctionne dans son espace de donnees. Le proprietaire voit tout. Les membres d equipe ont des permissions selon leur role. Le client final, lui, a un acces portail limite a ses actions metier: accepter un devis, valider un job, payer une facture.

La plateforme supporte deux grands types d entreprise:
1. Services: workflow Request -> Quote -> Job -> Validation -> Invoice -> Payment.
2. Products: workflow Catalogue -> Stock -> Sale -> Fulfillment -> Payment.

Dans les deux cas, le client reste l entite centrale. C est pour ca qu on commence toujours une demo serieuse par ce module.

Autre point important: plusieurs modules peuvent etre actives ou desactives par feature flags selon le plan de l entreprise. Donc la fiche client doit rester compatible avec des usages simples comme avances."

### Actions ecran
- Montrer un exemple de compte service si disponible.
- Montrer rapidement un compte produit si disponible.
- Revenir dans `Customers`.

### Message cle
Le module Client est transverse: il doit fonctionner avec des permissions, des features et des workflows differents.

---

## 06:00 - 09:00 | Modele de donnees client: ce qui est stocke et pourquoi

### Speech a lire
"Dans MLK Pro, un client stocke beaucoup plus que nom, email, telephone.

On a les informations d identite, la societe, des tags, des notes operationnelles, des preferences de facturation, des remises, des options d auto-validation, et l acces portail.

La logique adresse est geree via des proprietes: une propriete physique, de facturation, ou autre. Une propriete est marquee par defaut pour accelerer les devis et les interventions.

Techniquement, un client peut etre relie a:
- requests
- quotes
- works
- invoices
- payments
- sales
- activite
- loyalty
- campagnes marketing

Donc quand on cree un client propre, on ne prepare pas juste la relation commerciale, on prepare aussi l execution operationnelle, la facturation et le suivi post-vente." 

### Actions ecran
- Ouvrir une fiche client existante.
- Scroller lentement les zones principales.
- Montrer les sections: infos, proprietes, activite, stats.

### Message cle
Une fiche client bien structuree est reutilisee dans toute la plateforme, sans resaisie.

---

## 09:00 - 12:00 | Ecran liste clients: pilotage, filtres, priorisation

### Speech a lire
"On commence par la liste clients, parce que c est l ecran de pilotage quotidien.

En haut, on a des indicateurs: total clients, nouveaux clients, clients actifs, clients avec devis, clients avec jobs.
Ces KPI donnent une lecture immediate de la sante commerciale et operationnelle.

Ensuite, on a la recherche rapide et les filtres avances: ville, pays, statut actif/archive, presence de devis, presence de jobs, plage de dates de creation, tri.

On peut aussi changer de vue table/cartes selon le besoin.

Pour les equipes qui gerent du volume, les actions en masse sont essentielles: activer/desactiver le portail, archiver/restaurer, supprimer.

Donc cette page n est pas juste une liste: c est une console de segmentation et d administration client." 

### Actions ecran
- Montrer les KPI.
- Faire une recherche par nom.
- Ouvrir filtres avances et appliquer:
  - ville
  - has quotes
  - status active
- Montrer tri par date puis par nom.
- Montrer selection multiple et menu d actions bulk.

### Message cle
La liste clients sert a gouverner la base, pas seulement a consulter.

---

## 12:00 - 15:00 | Creation d un client: standard qualite + acces portail

### Speech a lire
"Maintenant on cree un client de zero.

Je renseigne civilite, prenom, nom, email, telephone et societe. Ici la qualite est critique: email valide, numero joignable, libelle clair.

Ensuite je decide si le client a acces au portail. Si l acces portail est actif, la plateforme peut lier ce client a un utilisateur portail et envoyer une invitation. Si l acces est desactive, le client existe en interne sans compte portail actif.

On peut aussi definir des preferences avancees:
- mode de facturation
- cycle de facturation
- regroupement des factures
- delai
- remise

Et des options d automatisation:
- auto accept quotes
- auto validate jobs
- auto validate tasks
- auto validate invoices

Ces options permettent d adapter l experience a chaque client: certains veulent valider manuellement, d autres veulent un flux presque automatique." 

### Actions ecran
- Cliquer `Add customer`.
- Remplir le formulaire complet.
- Cocher/decocher `portal access` pour expliquer les 2 scenarios.
- Montrer les toggles auto-validation.
- Sauvegarder.

### Message cle
Le formulaire client configure a la fois la relation, l operationnel et le niveau d automatisation.

---

## 15:00 - 18:00 | Proprietes et adresses: multi-sites, defaut, coherence

### Speech a lire
"On passe aux proprietes du client, qui sont tres importantes.

Une propriete represente une adresse metier utilisable par les devis, jobs et parfois la facturation.
Types possibles: physical, billing, other.

Sur un client entreprise, on peut avoir plusieurs sites: siege, entrepot, point de vente, chantier recurrent.
On marque ensuite une propriete par defaut pour eviter la resaisie.

Regle operationnelle: toujours garder au moins une propriete exploitable. Si une propriete par defaut est supprimee, la plateforme doit pouvoir basculer sur une autre pour garder la continuite du flux.

Concretement, c est ce qui evite les devis sans adresse, les jobs mal planifies et les erreurs d intervention." 

### Actions ecran
- Sur le client entreprise, ajouter 3 proprietes:
  - Siege
  - Entrepot
  - Boutique
- Mettre `Siege` en propriete par defaut.
- Modifier une propriete pour montrer la maintenance.
- Supprimer une propriete non critique.

### Message cle
La gestion des proprietes transforme une fiche client simple en dossier multi-sites utilisable en production.

---

## 18:00 - 21:00 | Fiche client 360: pilotage en temps reel

### Speech a lire
"Ici on est sur la vue detail client, la vue 360.

Pour un compte services, on retrouve des indicateurs relies au workflow:
- nombre de requests
- nombre de quotes
- nombre de jobs
- nombre d invoices
- jobs actifs

On voit aussi:
- taches liees au client
- jobs a venir
- resume de facturation (total facture, total paye, solde)
- paiements recents
- timeline d activite

Cette vue permet de prendre une decision rapide: relancer, planifier, facturer, escalader, ou automatiser.

Pour un compte produits, la meme fiche met plus en avant les ventes, insights d achat, et top produits.

Dans les deux cas, on garde un principe unique: une seule fiche client pour piloter le cycle de vie complet." 

### Actions ecran
- Ouvrir la fiche d un client avec historique.
- Montrer stats.
- Montrer calendrier/taches/jobs a venir.
- Montrer resume facturation et paiements.
- Montrer la timeline d activite.

### Message cle
La fiche client centralise l execution et la decision, pas seulement la consultation.

---

## 21:00 - 24:00 | Options avancees client: tags, notes, VIP, loyalty, mailing lists

### Speech a lire
"Maintenant les fonctions avancees qui font gagner du temps aux equipes.

Premiere couche: notes et tags. On peut classifier vite un client: prioritaire, recurrent, a risque, multisite, etc. Ces tags servent ensuite au ciblage marketing et au suivi interne.

Deuxieme couche: VIP et loyalty si la feature est active. On personnalise l experience client avec un niveau de service et un suivi de points.

Troisieme couche: campagnes marketing. Depuis la fiche client, on peut lier a des mailing lists pour activer des campagnes ciblees sans export manuel.

Quatrieme couche: auto-validation. C est utile pour des clients matures qui veulent un flux rapide:
- devis acceptes automatiquement
- jobs valides automatiquement
- etc.

Attention: plus on automatise, plus il faut une base de donnees propre et des regles metier bien configurees." 

### Actions ecran
- Modifier notes.
- Ajouter tags.
- Montrer parametre auto-validation.
- Si disponible: montrer VIP tier / loyalty / mailing lists.

### Message cle
Le module client est aussi un module de segmentation et d automatisation.

---

## 24:00 - 27:00 | Comment la plateforme marche de bout en bout depuis le client

### Speech a lire
"Je fais maintenant le lien global pour bien comprendre toute la plateforme.

A partir de ce client:
1. Je cree une request: la demande entre dans le pipeline commercial.
2. Je convertis en quote: les lignes de prix sont figees au moment du devis.
3. Une quote acceptee peut creer un job.
4. Le job suit des regles qualite: photos before, checklist, photos after.
5. Quand le job est valide ou auto-valide, la facture est generee.
6. Le paiement met a jour la facture et peut cloturer le job.
7. Ensuite on passe en fidelisation: avis, loyalty, campagnes.

Tout ce cycle reutilise les donnees du client et de ses proprietes.

C est la raison pour laquelle on insiste autant sur la creation propre d une fiche client: la qualite en entree determine la fluidite de tout le systeme." 

### Actions ecran
- Depuis la fiche client, ouvrir:
  - requests
  - quotes
  - jobs
  - invoices
- Montrer une transition de statut ou un exemple reel si disponible.

### Message cle
Le client est la cle de voute du workflow end-to-end.

---

## 27:00 - 30:00 | Conclusion business + erreurs frequentes + transition

### Speech a lire
"Pour conclure:

Le module Client dans MLK Pro sert a trois choses:
1. Structurer la donnee de base.
2. Accelerer les operations quotidiennes.
3. Activer les automatisations sans perdre le controle.

Les erreurs frequentes a eviter:
- email ou telephone incomplet
- pas de propriete exploitable
- pas de propriete par defaut
- tags incoherents
- automatisation active sans gouvernance

Bonne pratique simple:
- standardiser la saisie
- verifier la fiche juste apres creation
- utiliser notes/tags pour le contexte equipe
- relire les options portail et auto-validation

Quand ce module est maitrise, la suite devient naturelle:
Request, Quote, Job, Invoice, Payment.

Dans la prochaine video, on pourra enchaîner directement avec le module Requests ou Quotes en repartant des clients crees aujourd hui." 

### Actions ecran
- Retour liste clients.
- Ouvrir un client complet et faire un recap final.
- Fermer sur la promesse de la prochaine video.

### Message cle
Un module client bien gere = moins d erreurs, plus de vitesse, meilleure experience client.

---

## Annexe A - Explication rapide des statuts a citer dans la video

### Requests
- `REQ_NEW`
- `REQ_CONTACTED`
- `REQ_QUALIFIED`
- `REQ_QUOTE_SENT`
- `REQ_WON`
- `REQ_LOST`
- `REQ_CONVERTED`

### Quotes
- `draft`
- `sent`
- `accepted`
- `declined`

### Jobs (services)
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

### Invoices
- `draft`
- `sent`
- `partial`
- `paid`
- `overdue`
- `void`

### Sales (products)
- `draft`
- `pending`
- `paid`
- `canceled`

---

## Annexe B - Checklist qualite avant publication video
- Le speech explique clairement le role central du module client.
- La demo montre creation + edition + proprietes + defaut.
- La demo montre portail access et auto-validation.
- La demo relie client aux modules requests/quotes/jobs/invoices.
- La conclusion explique la valeur business (productivite + qualite + automatisation).
