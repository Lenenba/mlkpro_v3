# Speech detaille - Module Produits (32 minutes)

## Objectif de cette version
Ce document sert a presenter en video le module produits de Malikia pro, avec:
- un script oral complet
- une demo ecran minute par minute
- l explication metier du flux retail de bout en bout

## Duree cible
32 minutes (minimum 20 minutes respecte).

## Public cible
- Prospect ecommerce / retail
- Owner / manager qui veut piloter stock + commandes
- Equipe operationnelle (vente, preparation, livraison, support)

## Pre-requis avant tournage
- Connecte-toi avec un compte owner ou un membre ayant `sales.manage` ou `sales.pos`.
- Verifie que l entreprise est en mode `products`.
- Prepare au moins:
  - 6 produits avec categories
  - 2 entrepots
  - 3 commandes (pending, paid, canceled)
  - 1 commande delivery et 1 commande pickup
  - 1 produit avec stock minimum pour montrer l alerte stock bas

---

## 00:00 - 03:00 | Introduction: comment fonctionne le mode produits

### Speech a lire
"Dans cette video, on se concentre sur le module produits de Malikia pro.

Ici, la plateforme gere un flux retail complet: catalogue, stock, boutique publique, commande, paiement, fulfillment, et mise a jour automatique du stock.

L enjeu principal est de garder une coherence parfaite entre ce qui est vendu, ce qui est reserve, ce qui est prepare, et ce qui est vraiment sorti du stock.

Ce qu on va montrer aujourd hui: comment configurer le socle, comment creer les produits correctement, comment piloter les commandes, et comment eviter les erreurs qui coutent de l argent, comme la survente ou les statuts incoherents." 

### Actions ecran
- Ouvrir dashboard.
- Ouvrir `Products` puis `Sales` puis `Orders` rapidement.

### Message cle
Le module produits est un systeme d execution commerciale en temps reel, pas juste un catalogue.

---

## 03:00 - 06:00 | Architecture: roles, permissions, perimetre

### Speech a lire
"En mode produits, les acces sont controles par roles et permissions.

Le proprietaire a la vue complete. Les membres ont un acces selon permissions, notamment `sales.manage` et `sales.pos`.

Cette couche est importante car elle protege les operations sensibles:
- changement de statut commande
- actions de stock
- paiements
- configuration entrepots

La plateforme est multi-entreprise: chaque compte travaille dans son propre espace de donnees. Donc les produits, ventes et stocks restent isoles par entreprise." 

### Actions ecran
- Montrer rapidement un ecran settings/team (si disponible).
- Revenir sur `Products`.

### Message cle
Sans bonne gouvernance d acces, le flux produit devient vite instable.

---

## 06:00 - 09:00 | Settings store: livraison, retrait, regles de checkout

### Speech a lire
"Avant de vendre, on configure la boutique.

Dans `Settings > Company > Store`, on active livraison et/ou retrait.

Regles importantes:
- Si livraison est active, la zone de livraison est obligatoire.
- Si retrait est actif, adresse de retrait et delai de preparation sont obligatoires.
- Si aucun mode n est configure, le checkout public est bloque.

Cette logique evite d accepter des commandes impossibles a executer.

Au checkout:
- si seule livraison est active, la methode delivery est forcee
- si seul retrait est actif, pickup est force
- si les deux sont actifs, le client choisit." 

### Actions ecran
- Ouvrir `Settings > Company > Store`.
- Montrer activation delivery/pickup.
- Montrer champs obligatoires.
- Sauvegarder.

### Message cle
La qualite du fulfillment commence dans les parametres store.

---

## 09:00 - 12:00 | Catalogue produits: creation propre et standardisation

### Speech a lire
"On passe a la creation produit.

Chaque produit doit avoir une fiche propre:
- nom clair
- SKU
- code-barres si disponible
- prix
- taxe
- categorie
- stock initial
- fournisseur
- visuels

Le but est double:
1. Une boutique lisible pour le client.
2. Une execution interne sans ambiguite pour l equipe.

Bonne pratique: imposer des standards de nommage et de SKU. C est ce qui facilite la recherche, le reporting et la gestion de stock sur le long terme." 

### Actions ecran
- Creer un produit en direct.
- Montrer image, SKU, categorie, prix.
- Sauvegarder.

### Message cle
Un catalogue propre reduit les erreurs de preparation et de vente.

---

## 12:00 - 15:00 | Stock tracking: standard, lot, serial

### Speech a lire
"Malikia pro gere plusieurs niveaux de tracking stock:
- standard: le plus simple
- lot: utile pour tracer des lots et dates d expiration
- serial: un numero de serie unique par unite

Regles a retenir:
- en mode lot, numero de lot requis
- en mode serial, numero de serie requis, avec logique unitaire

Choisir le bon type de tracking depend de votre metier:
- retail classique: standard
- alimentaire / pharma: lot
- electronique / equipement: serial

Plus le tracking est precis, plus la tracabilite est forte, mais plus la rigueur operationnelle doit etre elevee." 

### Actions ecran
- Ouvrir un produit standard.
- Ouvrir un produit lot ou serial (si disponible).
- Montrer les differences de champs/contraintes.

### Message cle
Le tracking est un choix strategique qui impacte toute l execution.

---

## 15:00 - 18:00 | Entrepots et inventaire: on_hand, reserved, available

### Speech a lire
"Maintenant, le coeur du pilotage stock.

Malikia pro distingue:
- On hand: stock physique present
- Reserved: stock bloque par commandes pending
- Available: on_hand - reserved

Le stock affichable pour la vente correspond a available.

Donc une commande peut reduire le disponible sans encore sortir physiquement le stock. C est essentiel pour eviter la survente.

On peut aussi faire des ajustements, transferts et corrections. Chaque mouvement met a jour l inventaire puis resynchronise le stock produit.

Enfin, avec un minimum stock defini, la plateforme envoie des alertes quand on passe en dessous du seuil." 

### Actions ecran
- Ouvrir settings warehouses.
- Montrer entrepot par defaut.
- Ouvrir un produit et montrer reserve / available.
- Faire un ajustement simple de stock (si possible).

### Message cle
Le reserve protege la promesse client tant que la commande n est pas finalisee.

---

## 18:00 - 21:00 | Boutique publique: experience client cote front

### Speech a lire
"On passe cote client avec la boutique publique.

Le client peut:
- rechercher
- filtrer
- consulter promotions, arrivages, meilleurs vendeurs
- ajouter au panier
- passer en checkout delivery ou pickup

La boutique n est pas deconnectee du backoffice: elle utilise les memes produits actifs, le meme stock disponible et les memes regles de fulfillment.

Quand un client valide, la commande entre dans le systeme avec un statut initial coherent pour l execution." 

### Actions ecran
- Ouvrir `/store/{slug}`.
- Ajouter des produits au panier.
- Aller au checkout.
- Montrer selection delivery/pickup.

### Message cle
Front public et operations internes partagent la meme verite metier.

---

## 21:00 - 24:00 | Creation commande (sale) et statuts de vente

### Speech a lire
"Une commande peut venir de 3 sources:
- store public
- portail client
- POS interne

Statuts de vente principaux:
- draft
- pending
- paid
- canceled

En pratique:
- pending signifie commande recue avec reserve active
- paid signifie paiement complet
- canceled stoppe le flux et libere la reserve

La force de Malikia pro est d imposer des transitions claires pour garder un etat de commande fiable entre equipe vente, preparation et comptabilite." 

### Actions ecran
- Ouvrir `Sales` puis un detail de commande.
- Montrer un exemple pending, paid, canceled.
- Montrer la timeline si visible.

### Message cle
Le statut de vente pilote la finance et le stock en meme temps.

---

## 24:00 - 27:00 | Fulfillment: livraison/retrait sans incoherence

### Speech a lire
"Le fulfillment suit des statuts operationnels:
- pending
- preparing
- out_for_delivery
- ready_for_pickup
- completed
- confirmed

Regles critiques:
- une commande pickup ne peut pas aller en out_for_delivery
- une commande delivery ne peut pas aller en ready_for_pickup
- confirmed est possible seulement apres completed
- une commande deja livree ne doit plus revenir en arriere

Ces blocages ne sont pas des limites, ce sont des protections metier pour eviter les erreurs terrain." 

### Actions ecran
- Ouvrir une commande pickup et tenter un statut delivery (montrer refus si possible).
- Ouvrir une commande delivery et montrer le bon enchainement.
- Passer sur completed puis confirmed.

### Message cle
Le moteur de statuts empêche les contradictions operationnelles.

---

## 27:00 - 30:00 | Paiements: manuel, Stripe, et impact metier

### Speech a lire
"Cote paiement, on peut avoir plusieurs scenarios.

Paiement manuel:
- permet de marquer paid selon le process interne.

Paiement Stripe:
- selon le flux, la commande peut rester pending jusqu a confirmation.

La commande est consideree reellement finalisee quand on a coherence entre paiement et fulfillment.

Autre logique utile en portal: un depot peut etre applique automatiquement dans certaines transitions pour securiser la commande.

Le point le plus important:
paiement, fulfillment et stock ne doivent jamais etre traites comme des blocs separes. Dans Malikia pro, ces 3 dimensions sont synchronisees." 

### Actions ecran
- Montrer ajout de paiement sur une commande.
- Montrer evolution des montants (amount paid / due).
- Montrer statut final.

### Message cle
Le paiement est une piece du workflow global, pas un evenement isole.

---

## 30:00 - 32:00 | Conclusion + erreurs frequentes + transition

### Speech a lire
"Pour conclure, le module produits repose sur 5 piliers:
1. Catalogue propre
2. Stock fiable (on_hand, reserved, available)
3. Statuts de vente clairs
4. Fulfillment coherent delivery/pickup
5. Paiement aligne avec execution

Erreurs frequentes a eviter:
- oublis de configuration store (delivery/pickup)
- SKU non standardises
- confusion entre reserve et stock physique
- changement manuel de statut sans logique metier
- absence de seuil minimum stock

Si ces bases sont bien gerees, vous obtenez:
- moins de surventes
- meilleure qualite de service
- meilleure vitesse operationnelle
- meilleure lisibilite business en temps reel.

Dans la prochaine video, on peut enchaîner sur le module commandes/detail fulfillment ou sur la partie campaigns pour reactivation clients." 

### Actions ecran
- Retour liste products.
- Retour liste sales/orders.
- Recap visuel final.

### Message cle
Module produits maitrise = execution retail fiable, scalable et rentable.

---

## Annexe A - Statuts a citer pendant la demo

### Sale status
- `draft`
- `pending`
- `paid`
- `canceled`

### Fulfillment status
- `pending`
- `preparing`
- `out_for_delivery`
- `ready_for_pickup`
- `completed`
- `confirmed`

---

## Annexe B - Checklist tournage rapide
- Montrer settings store delivery/pickup.
- Creer ou editer un produit avec SKU/categorie/prix.
- Montrer un cas reserve vs available.
- Montrer au moins une commande delivery et une pickup.
- Montrer un changement de fulfillment avec regle de coherence.
- Montrer impact paiement sur statut commande.
