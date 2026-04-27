# Malikia Pulse - roadmap des 8 ameliorations en 3 etapes

Derniere mise a jour: 2026-04-26

## 1. Objectif

Ce document organise les prochaines ameliorations de `Malikia Pulse` en 3 etapes simples.

L objectif n est pas d ajouter beaucoup d ecrans ou de texte.
L objectif est de rendre Pulse plus fiable, plus clair et plus facile a valider avant publication.

Chaque amelioration doit respecter 4 principes:

- garder les pages sobres
- eviter les textes inutiles ou redondants
- montrer le rendu reel du post quand c est utile
- garder la validation humaine au centre avant publication

## 2. Les 8 ameliorations visees

1. Page de validation enrichie
2. Calendrier editorial Pulse
3. Brand Voice par tenant
4. Score qualite avant publication
5. Previsualisation reseau dans le composeur
6. Bibliotheque medias Pulse
7. Historique IA lisible
8. Mode campagne

## 3. Etape 1 - Confiance avant publication

But:

Permettre a l utilisateur de voir clairement ce qui sera publie, de corriger vite et de valider sans doute.

Ameliorations incluses:

- 5. Previsualisation reseau dans le composeur
- 1. Page de validation enrichie
- 4. Score qualite avant publication, version MVP

Statut au 2026-04-26:

- demarre
- preview reseau ajoutee dans le composeur
- preview reseau ajoutee dans la file de validation
- score qualite MVP ajoute avant publication et validation
- detection simple de brouillon recent proche ajoutee dans le composeur
- preview limitee a un reseau actif a la fois pour eviter l empilement visuel
- score qualite ajuste selon les limites du reseau cible
- alerte image affichee seulement quand le reseau la rend vraiment utile

Livrables recommandes:

- ajouter dans le composeur une preview par reseau cible
- reutiliser le meme moteur visuel que l email de validation
- afficher Facebook, Instagram, LinkedIn ou autre cible avec un rendu proche du post final
- garder les controles de validation simples: approuver, programmer, refuser, demander revision
- ajouter un score qualite discret avant publication
- signaler uniquement les problemes utiles:
  - texte trop long
  - image manquante
  - lien manquant si un CTA est present
  - aucune cible selectionnee
  - post tres proche d un contenu recent

Garde-fous UI:

- ne pas afficher de gros bloc explicatif
- ne pas afficher toutes les metriques techniques
- ne pas surcharger la preview avec des badges partout
- afficher les alertes seulement quand elles aident a prendre une decision

Critere de fin:

Un utilisateur peut ouvrir un brouillon ou une demande de validation, voir le rendu par reseau, comprendre les risques principaux et valider ou demander une correction en moins d une minute.

## 4. Etape 2 - Organisation et coherence de marque

But:

Donner a Pulse une structure de travail plus propre pour planifier, reutiliser les visuels et garder une voix de marque stable.

Ameliorations incluses:

- 2. Calendrier editorial Pulse
- 3. Brand Voice par tenant
- 6. Bibliotheque medias Pulse

Statut au 2026-04-26:

- demarre
- onglet calendrier editorial ajoute dans Pulse
- vue semaine/mois ajoutee pour brouillons, programmations, validations et publications
- reprogrammation simple ajoutee pour les brouillons non encore mis en file de publication
- garde-fou ajoute pour ne pas modifier directement un post deja queue pour publication
- onglet Brand Voice ajoute dans Pulse
- ton, langue, mots a eviter, hashtags, CTA et phrase repere configurables par tenant
- Brand Voice branchee dans la generation Autopilot texte et dans les metadonnees du post genere
- onglet Bibliotheque medias ajoute dans Pulse
- images importees, images IA et medias attaches aux posts/templates visibles dans une grille sobre
- reutilisation d un media dans le composeur via un brouillon pre-rempli avec l image

Livrables recommandes:

- creer une vue calendrier semaine/mois pour:
  - brouillons
  - posts programmes
  - posts en attente de validation
  - posts publies
- permettre de reprogrammer un post depuis le calendrier
- ajouter un profil `Brand Voice` simple par tenant:
  - ton par defaut
  - mots a eviter
  - hashtags favoris
  - CTA favoris
  - langue principale
  - consignes courtes de style
- ajouter une bibliotheque medias Pulse:
  - images importees
  - images generees par IA
  - image source rattachee a un post
  - reutilisation dans un nouveau brouillon

Garde-fous UI:

- le calendrier doit rester un outil de pilotage, pas un tableau surcharge
- la Brand Voice doit etre un formulaire court
- la bibliotheque media doit privilegier la recherche, le filtre et la reutilisation rapide
- les details techniques IA restent caches par defaut

Critere de fin:

Une equipe peut planifier sa semaine de posts, reutiliser ses visuels et appliquer une voix de marque coherente sans devoir retaper les memes consignes a chaque generation.

## 5. Etape 3 - Production IA a plus grande echelle

But:

Transformer Pulse en assistant de campagne capable de produire plusieurs posts coherents, tout en gardant une trace claire des decisions IA.

Ameliorations incluses:

- 7. Historique IA lisible
- 8. Mode campagne
- 4. Score qualite avant publication, version avancee

Statut au 2026-04-26:

- termine
- trace IA lisible ajoutee dans l historique Pulse
- source, regle, campagne, modele texte, modele image, score choisi, fallback et date de generation affiches sous forme resumee
- mode campagne ajoute dans Pulse pour generer plusieurs brouillons planifies depuis une intention
- intentions couvertes: lancement produit, promotion, evenement, service a pousser, relance client
- chaque post de campagne reste modifiable dans le composeur avant validation ou publication
- score qualite avance ajoute aux payloads Pulse avec verification Brand Voice, repetition recente, adequation reseau, CTA et coherence image / texte

Livrables recommandes:

- ajouter un historique IA lisible sur chaque post genere:
  - source utilisee
  - regle Autopilot
  - modele texte
  - modele image
  - score selectionne
  - raison courte du choix
  - fallback utilise ou non
- creer un mode campagne qui peut generer plusieurs posts a partir d une intention:
  - lancement produit
  - promotion
  - evenement
  - service a pousser
  - relance client
- proposer une repartition des posts sur plusieurs jours
- permettre de modifier chaque post avant validation
- enrichir le score qualite avec:
  - respect de la Brand Voice
  - repetition par rapport aux posts recents
  - adequation au reseau cible
  - presence d un CTA clair
  - coherence image / texte

Garde-fous UI:

- ne jamais publier automatiquement une campagne sans validation
- afficher les raisons IA en resume court, pas en log brut
- ne pas exposer les prompts complets dans l interface principale
- garder la generation de campagne comme un assistant, pas comme une obligation

Critere de fin:

Un utilisateur peut lancer une campagne Pulse, obtenir plusieurs posts coherents, verifier les raisons IA, ajuster le calendrier et envoyer les contenus en validation.

## 6. Ordre recommande

Ordre de developpement conseille:

1. Previsualisation reseau dans le composeur
2. Page de validation enrichie
3. Score qualite MVP
4. Calendrier editorial
5. Brand Voice
6. Bibliotheque medias
7. Historique IA lisible
8. Mode campagne

Cet ordre est recommande parce qu il commence par la confiance utilisateur.
Avant de produire plus de contenu, Pulse doit d abord mieux montrer ce qui va etre publie.

## 7. Definition de reussite globale

Pulse sera considere comme nettement ameliore quand:

- les posts sont faciles a verifier visuellement
- les validations sont rapides et propres
- les pages restent sobres
- les campagnes sont planifiables
- les visuels sont reutilisables
- la voix de marque est respectee
- l IA est tracable sans encombrer l interface
