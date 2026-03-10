# Speech detaille - Module Requests / Leads (31 minutes)

## Objectif de cette version
Ce document permet de presenter le module Requests avec:
- un speech complet pret a lire
- un scenario de demo minute par minute
- une explication claire de la logique lead -> quote

## Duree cible
31 minutes (minimum 20 minutes respecte).

## Public cible
- Equipe commerciale
- Owner / manager operations
- Prospect qui veut comprendre l acquisition et la conversion

## Pre-requis avant tournage
- Compte interne owner/admin.
- Feature `requests` active.
- Donnees minimales:
  - 8 a 12 leads avec statuts varies
  - 3 clients existants
  - 2 membres equipe assignables
  - 1 lead pret a convertir en quote
- Si possible:
  - un CSV import de leads
  - un lien public de formulaire lead

---

## 00:00 - 03:00 | Introduction: role du module Requests

### Speech a lire
"Dans cette video, on presente le module Requests, c est-a-dire la gestion des leads entrants.

Dans MLK Pro, c est le point de depart du flux commercial. Un lead bien capture, bien qualifie et bien suivi se convertit plus vite en devis, puis en job et en facture.

Le module Requests sert a trois choses:
1. Centraliser les demandes (web, appel, import, autres canaux).
2. Structurer la qualification commerciale.
3. Convertir proprement les leads en devis sans perdre l historique.

On va voir ensemble le fonctionnement complet: liste, analytics, priorisation, assignation, suivi de statut, conversion, import, et bonnes pratiques." 

### Actions ecran
- Ouvrir `Requests`.
- Montrer vue globale.

### Message cle
Requests est le moteur d acquisition et de conversion, pas juste une liste de contacts.

---

## 03:00 - 06:00 | Statuts lead et logique pipeline

### Speech a lire
"Le pipeline Requests repose sur des statuts clairs:
- REQ_NEW
- REQ_CALL_REQUESTED
- REQ_CONTACTED
- REQ_QUALIFIED
- REQ_QUOTE_SENT
- REQ_WON
- REQ_LOST

Un lead converti en devis passe ensuite en REQ_CONVERTED.

Ces statuts servent a visualiser la maturite commerciale et a piloter les actions equipe.

Regle importante: quand un lead est marque LOST, un motif de perte est obligatoire. Cela force une discipline commerciale utile pour l analyse des pertes et l amelioration continue." 

### Actions ecran
- Montrer legendes/statuts sur leads existants.
- Ouvrir un lead LOST et montrer `lost_reason`.

### Message cle
Le statut n est pas cosmetique: il pilote le suivi, les KPI et les decisions.

---

## 06:00 - 09:00 | Vue liste: filtres, recherche, priorites

### Speech a lire
"La vue liste est le cockpit quotidien.

On peut filtrer par:
- texte de recherche
- statut
- client lie
- mode d affichage table ou board

Le tri met en avant le `next_follow_up_at`, donc les leads qui exigent une action rapide remontent naturellement.

C est essentiel pour eviter les leads oublies et les cycles de vente trop longs.

Autre point fort: on peut agir en masse. Par exemple assigner plusieurs leads a un commercial, ou changer le statut d un lot de leads en une seule operation." 

### Actions ecran
- Rechercher un lead par nom/email/service.
- Filtrer par statut.
- Filtrer par client.
- Montrer nettoyage des filtres.
- Selection multiple + action bulk.

### Message cle
La performance commerciale depend de la discipline de tri, suivi et execution.

---

## 09:00 - 12:00 | Vue board (kanban): pilotage visuel

### Speech a lire
"Le mode board transforme les leads en kanban commercial.

Chaque colonne correspond a un statut. On peut deplacer un lead d une colonne a l autre pour faire avancer le pipeline.

Avantage:
- lecture visuelle immediate des blocages
- detection rapide des leads en retard
- meilleure coordination equipe

Le systeme garde des regles metier: si on passe en LOST, le motif est demande. Donc meme en interaction rapide, la qualite de donnee est preservee.

Cette vue est parfaite pour les rituels d equipe: point quotidien commercial, priorisation, et plan d action." 

### Actions ecran
- Basculer en vue board.
- Drag and drop d un lead vers statut suivant.
- Passer un lead en LOST pour montrer exigence de motif.

### Message cle
Le board rend visible le pipeline et force la progression des opportunites.

---

## 12:00 - 15:00 | Analytics requests: conversion, reponse, risque

### Speech a lire
"MLK Pro inclut une couche analytics directement dans le module Requests.

On suit notamment:
- delai moyen de premiere reponse
- taux de conversion
- volume de leads sur la fenetre analysee
- conversion par source

On a aussi une vue de risque: leads ouverts sans activite recente.
Cela aide a prevenir la perte silencieuse d opportunites.

En plus, si vous utilisez le formulaire public, on suit:
- vues du formulaire
- soumissions
- conversion vue -> soumission
- top referrers
- top UTM sources/mediums/campaigns

Donc on peut piloter acquisition et performance commerciale avec des donnees factuelles." 

### Actions ecran
- Montrer cartes KPI analytics.
- Montrer `conversion by source`.
- Montrer `risk leads`.
- Montrer bloc analytics formulaire (views/submits/utm).

### Message cle
Le module ne suit pas seulement les leads, il mesure la qualite du funnel.

---

## 15:00 - 18:00 | Creation et qualification d un lead

### Speech a lire
"On cree maintenant un lead de zero.

Champs importants:
- titre / type de service
- description
- contact (nom, email, telephone)
- canal (web, phone, import, etc.)
- urgence
- localisation
- assignee
- prochain follow-up

Bonnes pratiques:
- toujours saisir un canal
- toujours mettre un prochain follow-up si le lead est ouvert
- assigner rapidement un responsable

Sans ces trois elements, la conversion baisse fortement car le lead perd son proprietaire et sa temporalite." 

### Actions ecran
- Creer un lead.
- Renseigner canal, urgence, assignee, follow-up.
- Sauvegarder puis reouvrir.

### Message cle
Un lead non assigne et sans follow-up est un revenu potentiel en risque.

---

## 18:00 - 21:00 | Detail lead: notes, media, activite, doublons

### Speech a lire
"Sur la fiche detail, on travaille le lead en profondeur.

On retrouve:
- statut et owner commercial
- informations contact
- adresse et contexte
- notes de qualification
- media attaches
- activite horodatee
- detection de doublons (email/telephone)

Cette partie est cruciale pour garder l intelligence commerciale dans la plateforme, et non dans des messages prives ou fichiers disperses.

Quand un lead est repris par un autre commercial, tout le contexte est deja la." 

### Actions ecran
- Ouvrir un lead detail.
- Ajouter une note.
- Ajouter un media si possible.
- Montrer section activite.
- Montrer bloc duplicatas.

### Message cle
La fiche lead transforme un contact en opportunite qualifiee et transferable.

---

## 21:00 - 24:00 | Assignation et actions bulk

### Speech a lire
"Quand le volume augmente, la gestion en masse devient obligatoire.

On peut:
- reassigner plusieurs leads a un commercial
- basculer un lot de leads vers un statut
- appliquer les regles de validation (ex: LOST -> lost reason)

Cette capacite est utile dans trois cas:
1. Repartition de charge equipe.
2. Campagne ponctuelle avec gros flux entrant.
3. Nettoyage du pipeline avant reporting.

Le but est de maintenir un pipeline propre, sans actions repetitives lead par lead." 

### Actions ecran
- Selectionner plusieurs leads.
- Bulk assign.
- Bulk update status.
- Montrer validation lost reason sur bulk LOST.

### Message cle
Le bulk management est indispensable pour l echelle operationnelle.

---

## 24:00 - 27:00 | Conversion lead -> quote (moment critique)

### Speech a lire
"Le point le plus important du module: la conversion en devis.

Depuis un lead qualifie:
- on choisit un client existant ou on cree un client
- on choisit la propriete/adresse
- on definit titre et description de travail
- on lance la conversion

Resultat:
- creation du devis (quote)
- liaison request <-> quote
- statut lead mis a jour vers converti

Cette conversion evite la resaisie et assure la continuite du flux commercial vers operations.

C est la charniere entre acquisition et execution." 

### Actions ecran
- Ouvrir un lead qualifie.
- Cliquer `Convert`.
- Choisir client + propriete.
- Valider.
- Ouvrir le devis cree.

### Message cle
La valeur du module Requests se concretise au moment de la conversion.

---

## 27:00 - 29:00 | Import leads et intake public

### Speech a lire
"Pour alimenter le pipeline, MLK Pro supporte plusieurs entrees:
- formulaire public signe
- import CSV
- API d integration

Le lien public de formulaire permet de capter des demandes sans login.
L import est utile pour migrer une base historique ou charger une campagne.

Avec ces canaux, on industrialise l entree des leads dans un modele commun, ce qui simplifie ensuite la qualification et la conversion." 

### Actions ecran
- Montrer section `lead intake` et URL public form.
- Montrer bouton/import CSV si visible.
- Mentionner endpoint API integration.

### Message cle
Un bon module requests doit capter, normaliser et distribuer les leads.

---

## 29:00 - 31:00 | Conclusion + erreurs frequentes

### Speech a lire
"En conclusion, le module Requests apporte:
1. Controle du pipeline commercial.
2. Visibilite analytics.
3. Conversion rapide en devis.

Erreurs frequentes a eviter:
- leads sans assignee
- leads ouverts sans date de follow-up
- changement de statut sans discipline de qualification
- motif LOST absent ou non exploitable
- conversion en devis trop tardive

Routine recommandee:
- revue quotidienne du board
- traitement des risk leads
- nettoyage hebdo des statuts
- revue mensuelle conversion par source

Si ce module est bien utilise, le taux de transformation en devis augmente et le cycle de vente se raccourcit." 

### Actions ecran
- Recap rapide liste + board + analytics + convert.
- Fin sur un lead converti.

### Message cle
Requests bien pilote = plus de devis, moins d opportunites perdues.

---

## Annexe A - Statuts a citer pendant la demo
- `REQ_NEW`
- `REQ_CALL_REQUESTED`
- `REQ_CONTACTED`
- `REQ_QUALIFIED`
- `REQ_QUOTE_SENT`
- `REQ_WON`
- `REQ_LOST`
- `REQ_CONVERTED`

---

## Annexe B - Checklist tournage rapide
- Montrer les 2 vues: table + board.
- Montrer analytics + risk leads + conversion by source.
- Montrer creation lead complete (channel + urgency + assignee + follow-up).
- Montrer action bulk.
- Montrer conversion vers quote.
- Rappeler la regle LOST -> lost_reason obligatoire.
