# Packs et forfaits - User stories et cadrage produit

Derniere mise a jour: 2026-05-11

## 1. But du document

Ce document decrit comment Malikia pourrait permettre aux entreprises de creer,
vendre et suivre des packs et des forfaits bases sur leurs produits et services
existants.

Le but n est pas encore de figer l implementation. Le but est de poser une base
de discussion claire:

- ce que l utilisateur final doit pouvoir faire
- ce qui est MVP et ce qui peut attendre
- comment raccorder cette fonctionnalite au contexte actuel de Malikia
- quels risques produit et techniques eviter
- quelles questions doivent etre tranchees avant developpement

## 2. Definition produit

### 2.1 Pack

Un pack est une offre composee vendue comme un ensemble.

Exemples:

- Pack "Ouverture salon": logo + site vitrine + 3 posts sociaux
- Pack "Anniversaire": salle + decoration + gateau + photos
- Pack "Routine skincare": nettoyant + creme + consultation
- Pack "Lancement boutique": configuration boutique + 10 produits ajoutes + campagne email

Caracteristiques:

- vente souvent ponctuelle
- prix fixe ou prix calcule depuis les elements
- peut contenir plusieurs produits/services
- peut appliquer une remise globale
- peut etre ajoute a un devis ou une facture

### 2.2 Forfait

Un forfait est une offre qui donne droit a une quantite, une duree, une recurrence
ou des credits.

Exemples:

- 10 seances de massage
- 5 heures de consultation
- 4 visites menage par mois
- maintenance mensuelle
- forfait coiffure mensuel
- abonnement repas semaine

Caracteristiques:

- peut etre consomme dans le temps
- peut expirer
- peut etre recurrent
- peut avoir un solde restant
- peut etre lie a des reservations, interventions, factures ou paiements

### 2.3 Principe de vocabulaire recommande

Eviter le mot `Plan` cote code, car Malikia utilise deja des plans pour les offres
SaaS et la facturation de la plateforme.

Noms recommandes:

- `OfferPackage` pour le modele configure par l entreprise
- `OfferPackageItem` pour les lignes du pack/forfait
- `CustomerPackage` pour le pack/forfait vendu a un client
- `CustomerPackageUsage` pour les consommations

Noms affiches cote UI:

- "Packs"
- "Forfaits"
- "Offres composees"
- "Forfaits client"

## 3. Contexte Malikia existant

Malikia possede deja plusieurs briques utiles:

- `Product` gere les produits et les services via `item_type`
- `Quote` et `QuoteProduct` gerent les devis et les lignes vendues
- `Invoice` et `InvoiceItem` gerent les factures et les lignes facturees
- `Reservation` peut etre liee a un service
- `Customer` permet de rattacher une vente a un client
- Stripe/Paddle existent deja pour la facturation SaaS de Malikia, mais ne doivent
  pas etre melanges trop tot avec les forfaits vendus par les entreprises

Implication:

La V1 devrait reutiliser le catalogue existant et se brancher progressivement sur
devis/factures. La consommation automatique par reservation peut venir en V2.

## 4. Objectifs business

Objectifs pour l entreprise:

- vendre plus facilement des offres groupees
- augmenter le panier moyen
- simplifier les devis repetitifs
- proposer des forfaits clairs aux clients
- suivre ce qui a ete consomme ou reste a livrer
- eviter les oublis manuels

Objectifs pour le client final:

- comprendre exactement ce qui est inclus
- connaitre le prix et les conditions
- voir le solde restant d un forfait
- reserver ou consommer une prestation incluse sans confusion

Objectifs pour Malikia:

- enrichir le catalogue sans casser les produits/services existants
- preparer des opportunites marketing: promotions, relances, upsell
- connecter plus tard aux paiements recurrents et au portail client

## 5. Personas

### 5.1 Proprietaire d entreprise

Elle veut creer des packs attractifs a partir de ses produits et services pour
les vendre rapidement.

Exemples:

- salon de beaute
- restaurant
- agence digitale
- salle evenementielle
- consultant
- entreprise de menage

### 5.2 Membre equipe / manager

Il veut vendre ou appliquer un forfait a un client sans modifier la configuration
globale.

### 5.3 Client final

Il veut savoir ce qu il a achete, combien il lui reste et comment utiliser son
forfait.

### 5.4 Comptabilite / admin

Elle veut que les packs et forfaits produisent des lignes propres dans les devis,
factures et rapports.

## 6. Scope recommande

### 6.1 V1 - Packs simples et forfaits manuels

La V1 doit rester solide et utile:

- creer un pack ou forfait dans le catalogue
- ajouter des produits/services existants
- definir un prix fixe
- afficher ce qui est inclus
- ajouter le pack/forfait a un devis
- ajouter le pack/forfait a une facture
- vendre le forfait a un client
- suivre manuellement le statut: actif, consomme, expire, annule

V1 volontairement exclu:

- paiement recurrent automatique
- consommation automatique par reservation
- portail client complet
- prorata
- taxes complexes par ligne
- upload automatique d un contrat

### 6.2 V2 - Credits, seances et reservations

La V2 ajoute la vraie logique de consommation:

- quantite de credits ou seances
- date d expiration
- historique de consommation
- rattachement a une reservation
- bouton "Consommer 1 credit"
- alertes quand le solde est bas
- blocage si forfait expire ou solde insuffisant

### 6.3 V3 - Abonnements et paiements recurrents

La V3 ajoute la recurrence:

- forfait mensuel/annuel
- renouvellement automatique
- paiement Stripe Connect ou lien de paiement
- portail client pour suivre le forfait
- relances automatiques
- upgrade/downgrade de forfait

## 7. User stories MVP

### PF-001 - Creer un pack

En tant que proprietaire,
je veux creer un pack compose de plusieurs produits/services,
afin de vendre une offre claire et prete a utiliser.

Criteres d acceptation:

- je peux choisir le type `pack`
- je peux saisir un nom, une description et une image optionnelle
- je peux ajouter des produits existants
- je peux ajouter des services existants
- chaque ligne a une quantite
- chaque ligne peut avoir un prix inclus ou un prix override
- je peux definir un prix total fixe
- je peux activer/desactiver le pack
- le pack apparait dans une liste dediee

Notes:

- le pack ne doit pas decrementer le stock au moment de la configuration
- le stock ne sera impacte qu au moment de la vente/facturation, si on decide de
  le faire en V1

### PF-002 - Creer un forfait

En tant que proprietaire,
je veux creer un forfait avec des droits consommables,
afin de vendre des seances, heures ou visites en avance.

Criteres d acceptation:

- je peux choisir le type `forfait`
- je peux definir une unite: seance, heure, visite, credit, mois
- je peux definir une quantite incluse
- je peux definir une duree de validite optionnelle
- je peux ajouter les services ou produits couverts par le forfait
- je peux definir un prix total
- je peux activer/desactiver le forfait

Exemples d unite:

- `session`
- `hour`
- `visit`
- `credit`
- `month`

### PF-003 - Liste des packs et forfaits

En tant que proprietaire,
je veux voir tous mes packs et forfaits,
afin de les gerer rapidement.

Criteres d acceptation:

- je vois le nom
- je vois le type: pack ou forfait
- je vois le prix
- je vois le statut: actif/inactif
- je vois le nombre d elements inclus
- je peux filtrer par type
- je peux rechercher par nom
- je peux dupliquer une offre
- je peux archiver une offre

### PF-004 - Ajouter un pack a un devis

En tant que proprietaire ou membre autorise,
je veux ajouter un pack a un devis,
afin de vendre rapidement une offre composee.

Criteres d acceptation:

- depuis un devis, je peux chercher un pack actif
- le devis ajoute une ligne principale pour le pack
- les elements inclus restent visibles dans `source_details` ou metadata
- le prix du devis reprend le prix du pack
- je peux modifier la quantite du pack
- je peux modifier le prix si j ai la permission
- le client voit clairement ce qui est inclus

Decision a discuter:

- afficher une seule ligne "Pack X" avec details expandables
- ou exploser le pack en plusieurs lignes produit/service

Recommandation V1:

- une ligne principale, avec details inclus dans metadata et affichage de resume

### PF-005 - Ajouter un forfait a une facture

En tant que proprietaire,
je veux facturer un forfait vendu a un client,
afin de suivre la vente et le paiement.

Criteres d acceptation:

- depuis une facture, je peux ajouter un forfait actif
- la facture contient une ligne forfait
- le prix et la devise sont repris
- le detail des droits inclus est conserve
- apres paiement ou validation, le forfait peut etre associe au client

Question a trancher:

- creation du `CustomerPackage` au moment de la facture creee
- ou seulement quand la facture est payee

Recommandation:

- V1: creation manuelle ou au moment de validation facture
- V2: creation automatique au paiement

### PF-006 - Vendre un forfait a un client

En tant que proprietaire,
je veux attribuer un forfait a un client,
afin de suivre ce qu il a achete et ce qu il lui reste.

Criteres d acceptation:

- je peux choisir un client
- je peux choisir un forfait actif
- je peux definir une date de debut
- je peux definir une date d expiration optionnelle
- le solde initial est calcule depuis le forfait
- je peux voir le forfait dans la fiche client
- le forfait client a un statut: actif, consomme, expire, annule

### PF-007 - Consommer un credit de forfait

En tant que membre equipe,
je veux consommer une partie du forfait client,
afin de suivre l utilisation reelle.

Criteres d acceptation:

- je peux ouvrir un forfait client actif
- je peux ajouter une consommation
- je peux choisir la quantite consommee
- je peux ajouter une note
- le solde restant est mis a jour
- un historique est conserve
- si le solde tombe a zero, le statut peut devenir `consomme`

V1:

- consommation manuelle

V2:

- consommation depuis reservation ou intervention

### PF-008 - Voir le solde d un forfait client

En tant que proprietaire ou membre equipe,
je veux voir le solde restant d un forfait,
afin de savoir si le client peut encore utiliser son offre.

Criteres d acceptation:

- la fiche client affiche les forfaits actifs
- chaque forfait affiche: total, consomme, restant
- la date d expiration est visible
- les forfaits expires sont separes
- l historique est accessible

### PF-009 - Archiver un pack ou forfait

En tant que proprietaire,
je veux archiver une offre qui n est plus vendue,
afin de garder l historique sans la proposer aux nouvelles ventes.

Criteres d acceptation:

- archiver ne supprime pas les anciennes ventes
- une offre archivee ne peut plus etre ajoutee a un devis/facture
- les anciens devis/factures restent lisibles
- je peux reactiver si besoin

### PF-010 - Dupliquer une offre

En tant que proprietaire,
je veux dupliquer un pack/forfait,
afin de creer rapidement une variante.

Criteres d acceptation:

- la copie reprend les lignes incluses
- la copie reprend le prix
- la copie est creee en statut brouillon ou inactif
- le nom ajoute "copie" ou un suffixe equivalent

## 8. User stories V2

### PF-101 - Consommer depuis une reservation

En tant que membre equipe,
je veux rattacher une reservation a un forfait client,
afin que la seance soit deduite automatiquement.

Criteres d acceptation:

- au moment de confirmer ou completer une reservation, je peux choisir un forfait
- seuls les forfaits compatibles avec le service sont proposes
- le solde est verifie
- une consommation est creee automatiquement
- si la reservation est annulee, la consommation peut etre restauree selon les regles

### PF-102 - Alertes de solde bas

En tant que proprietaire,
je veux etre alerte quand un client a presque fini son forfait,
afin de lui proposer un renouvellement.

Criteres d acceptation:

- seuil configurable: ex. 1 seance restante ou 20%
- notification interne
- possibilite de creer une campagne ou relance
- le message peut etre personnalise plus tard

### PF-103 - Expiration automatique

En tant que proprietaire,
je veux que les forfaits expires soient marques automatiquement,
afin d eviter les erreurs d utilisation.

Criteres d acceptation:

- une commande planifiee detecte les forfaits expires
- le statut passe a `expired`
- les forfaits expires ne peuvent plus etre consommes sans permission speciale
- l historique reste visible

### PF-104 - Renouvellement manuel

En tant que proprietaire,
je veux renouveler un forfait client,
afin de prolonger ou recharger ses credits.

Criteres d acceptation:

- je peux repartir du forfait original
- je peux ajuster quantite, prix et expiration
- l ancien forfait reste dans l historique
- le nouveau forfait devient actif

## 9. User stories V3

### PF-201 - Forfait recurrent

En tant que proprietaire,
je veux vendre un forfait recurrent,
afin de facturer automatiquement un client tous les mois.

Criteres d acceptation:

- frequence: mensuelle, trimestrielle, annuelle
- prix recurrent
- date de prochain renouvellement
- generation de facture ou paiement automatique
- gestion du statut: actif, en retard, annule

### PF-202 - Portail client

En tant que client final,
je veux voir mes forfaits dans mon espace client,
afin de connaitre mon solde et mes dates importantes.

Criteres d acceptation:

- liste de mes forfaits actifs
- solde restant
- historique des consommations
- date d expiration
- bouton pour demander un renouvellement

### PF-203 - Automations marketing

En tant que proprietaire,
je veux declencher des campagnes depuis les forfaits,
afin de relancer les clients au bon moment.

Declencheurs possibles:

- forfait achete
- forfait presque consomme
- forfait expire bientot
- forfait expire
- forfait renouvele

## 10. Modele de donnees pressenti

### 10.1 `offer_packages`

Representerait le modele configure par l entreprise.

Champs possibles:

- `id`
- `user_id`
- `name`
- `slug`
- `type`: `pack`, `forfait`
- `status`: `draft`, `active`, `archived`
- `description`
- `image_path`
- `pricing_mode`: `fixed`, `calculated`
- `price`
- `currency_code`
- `validity_days`
- `included_quantity`
- `unit_type`: `session`, `hour`, `visit`, `credit`, `month`, null
- `is_public`
- `metadata`
- timestamps

### 10.2 `offer_package_items`

Representerait les produits/services inclus.

Champs possibles:

- `id`
- `offer_package_id`
- `product_id`
- `item_type_snapshot`
- `name_snapshot`
- `description_snapshot`
- `quantity`
- `unit_price`
- `included`
- `is_optional`
- `sort_order`
- `metadata`
- timestamps

Pourquoi snapshot?

Si le produit change plus tard, l offre vendue doit rester lisible avec les
valeurs historiques.

### 10.3 `customer_packages`

Representerait une vente/attribution a un client.

Champs possibles:

- `id`
- `user_id`
- `customer_id`
- `offer_package_id`
- `quote_id`
- `invoice_id`
- `status`: `active`, `consumed`, `expired`, `cancelled`
- `starts_at`
- `expires_at`
- `initial_quantity`
- `consumed_quantity`
- `remaining_quantity`
- `unit_type`
- `price_paid`
- `currency_code`
- `metadata`
- timestamps

### 10.4 `customer_package_usages`

Representerait chaque consommation.

Champs possibles:

- `id`
- `customer_package_id`
- `reservation_id`
- `invoice_id`
- `product_id`
- `quantity`
- `used_at`
- `note`
- `created_by_user_id`
- `metadata`
- timestamps

## 11. Regles metier importantes

### 11.1 Prix

Deux modes possibles:

- prix fixe: l entreprise definit le prix final
- prix calcule: total des lignes incluses moins remise

Recommandation V1:

- prix fixe obligatoire
- afficher le total theorique des lignes comme information optionnelle

### 11.2 Stock

Question sensible.

Options:

- decrementer le stock au moment de la facture
- decrementer le stock au moment de la consommation
- ne pas decrementer le stock en V1

Recommandation:

- produits physiques: decrement a la facture si le pack est vendu comme produit
- forfait consommable: decrement a la consommation si le produit/service est livre plus tard
- V1 peut demarrer sans automatisme stock pour reduire le risque

### 11.3 Taxes

Les packs peuvent melanger services et produits avec taxes differentes.

Options:

- taxe unique sur le pack
- taxes conservees par ligne incluse
- prix TTC sans detail

Recommandation V1:

- une taxe unique heritee du contexte facture/devis
- garder les details dans metadata pour une V2 plus fine

### 11.4 Permissions

Permissions possibles:

- `packages.view`
- `packages.manage`
- `packages.sell`
- `packages.consume`
- `packages.adjust`

Mapping simple V1:

- owner: tout
- manager: vendre et consommer
- team member: consommer si autorise
- client: voir uniquement plus tard

### 11.5 Statuts

Offre catalogue:

- `draft`
- `active`
- `archived`

Forfait client:

- `active`
- `consumed`
- `expired`
- `cancelled`

## 12. UX proposee

### 12.1 Navigation

Option A:

- Catalogue
  - Produits
  - Services
  - Packs et forfaits

Option B:

- Vente
  - Packs et forfaits

Recommandation:

- placer dans Catalogue, car la configuration ressemble plus a une offre vendable
  qu a une operation de vente.

### 12.2 Page liste

Elements:

- tabs: Tous, Packs, Forfaits, Archives
- bouton "Nouveau pack"
- bouton "Nouveau forfait"
- recherche
- cartes ou tableau compact
- actions: Modifier, Dupliquer, Archiver

### 12.3 Formulaire creation pack

Sections:

- Informations generales
- Elements inclus
- Prix
- Visibilite
- Resume

### 12.4 Formulaire creation forfait

Sections:

- Informations generales
- Droits inclus
- Services/produits couverts
- Prix et validite
- Resume

### 12.5 Fiche client

Ajouter un bloc:

- Forfaits actifs
- Solde restant
- Expiration
- Action "Consommer"
- Action "Renouveler"

## 13. Integrations avec modules existants

### 13.1 Produits et services

Le pack/forfait reference `Product`.

Important:

- `Product::ITEM_TYPE_PRODUCT`
- `Product::ITEM_TYPE_SERVICE`

### 13.2 Devis

Le pack peut devenir une ligne `QuoteProduct`.

Approche V1:

- `product_id` null ou reference optionnelle
- `description` contient resume
- `source_details` contient `offer_package_id` et les lignes incluses

### 13.3 Factures

Le pack peut devenir une ligne `InvoiceItem`.

Approche V1:

- `title` = nom du pack
- `description` = resume
- `meta.offer_package_id`
- `meta.offer_package_items`

### 13.4 Reservations

V2:

- `Reservation` peut consommer un `CustomerPackage`
- `reservation.service_id` permet de filtrer les forfaits compatibles

### 13.5 Marketing

V2/V3:

- utiliser les forfaits comme source de campagnes
- segmenter les clients par solde, expiration, achat recent

## 14. Backlog propose

### Phase 0 - Validation produit

Statut: termine.

But:

- verrouiller les decisions produit avant implementation
- eviter de coder une logique trop large ou ambigue
- transformer les choix V1/V2/V3 en backlog executable

Decisions deja validees:

- vocabulaire UI: "Packs" et "Forfaits"
- une seule entite catalogue recommandee: `OfferPackage`
- distinction par type: `pack` ou `forfait`
- ne pas utiliser `Plan` pour eviter la confusion avec les abonnements SaaS Malikia
- V1 avec prix fixe
- V1 sans options facultatives
- V1 sans packs imbriques
- forfait catalogue possible sans client rattache
- forfait client cree quand une offre est vendue/attribuee
- vente publique possible
- vente publique V1 via facture a payer
- modes paiement V1: Stripe card ou cash en magasin
- consommation en unite entiere uniquement
- expiration optionnelle
- expiration automatique seulement si une date est configuree
- solde negatif reserve aux admins
- annulation exceptionnelle sans remboursement possible
- raison obligatoire pour annulation exceptionnelle
- notification client obligatoire sur annulation exceptionnelle
- fonctionnalite disponible pour tous les secteurs
- facture publique impayee expire automatiquement apres un delai configurable
  avec valeur recommandee V1: 14 jours
- paiement cash en magasin cree une facture en attente
- le forfait achete en cash ne devient actif que lorsque le paiement cash est
  marque comme recu
- notification client V1 par email uniquement
- SMS/WhatsApp reportes a une version ulterieure

Cas clients prioritaires pour valider la V1:

1. Pack public vendable:
   - une entreprise cree un pack public
   - le client ouvre une page partageable
   - le client demande l achat
   - Malikia cree une facture a payer
   - le client paie par Stripe ou choisit cash en magasin

2. Forfait consommable manuel:
   - une entreprise cree un forfait 10 seances
   - le forfait est attribue a un client
   - l equipe consomme une unite entiere a chaque visite
   - le solde est visible dans la fiche client
   - un admin peut exceptionnellement autoriser un solde negatif

3. Pack vendu depuis devis/facture:
   - une entreprise ajoute un pack a un devis
   - le pack garde un snapshot des lignes incluses
   - le devis peut devenir facture
   - l historique reste stable meme si le pack catalogue change ensuite

Questions restantes de Phase 0:

- aucune question bloquante restante pour demarrer le decoupage technique V1

Sortie attendue Phase 0:

- decisions V1 marquees comme validees
- questions restantes tranchees
- user stories prioritaires confirmees
- terminologie UI confirmee
- backlog V1 pret a decouper en tickets techniques

### Phase 1 - Catalogue packs/forfaits

Statut: termine pour le socle catalogue interne.

- migrations `offer_packages`, `offer_package_items`
- modeles et relations
- service de creation/update
- liste UI
- formulaire creation/edit
- tests CRUD

Sortie livree:

- CRUD interne disponible via `offer-packages.index`
- types `pack` et `forfait`
- prix fixe uniquement
- lignes incluses basees sur produits/prestations existants
- options facultatives refusees en V1
- packs imbriques refuses en V1
- duplication en brouillon non public
- archivage/restauration sans suppression dure
- navigation ajoutee dans le hub Catalogue et le menu classique

### Phase 2 - Vente via devis/factures

Statut: termine pour le socle interne devis/factures.

- ajout d un pack a un devis
- ajout d un pack/forfait a une facture
- snapshot des lignes incluses
- affichage clair dans PDF/preview si applicable
- tests devis/factures

Sortie livree:

- selection d une offre active depuis le formulaire devis
- ajout manuel d une offre active sur une facture editable
- snapshot `offer_package` conserve dans `quote_products.source_details`
- propagation du snapshot vers `product_works.source_details`
- reprise du snapshot dans `invoice_items.meta`
- affichage des inclusions dans la preview facture et les PDF
- tests de non-regression devis -> job -> facture

### Phase 3 - Forfaits client manuels

Statut: termine pour le socle interne fiche client.

- creation `customer_packages`
- attribution a un client
- bloc fiche client
- consommation manuelle
- historique
- tests solde/statut

Sortie livree:

- creation `customer_packages` et `customer_package_usages`
- attribution manuelle d un forfait actif depuis la fiche client
- snapshot catalogue conserve dans `customer_packages.source_details`
- solde initial, consomme et restant suivis en unites entieres
- consommation manuelle avec historique recent
- statut automatique `consumed` quand le solde atteint zero
- bloc fiche client avec synthese, attribution et consommation
- tests attribution, garde-fous et solde/statut

### Phase 4 - Reservation et automatisation

Statut: termine pour le socle reservation + automatisation interne.

- consommation depuis reservation
- expiration automatique
- alertes solde bas
- relances marketing

Sortie livree:

- consommation automatique d un forfait client quand une reservation est marquee `completed`
- selection prioritaire du forfait qui contient le service reserve
- idempotence pour eviter une double consommation sur la meme reservation
- restauration du solde si une reservation terminee est repassee a un autre statut
- commande `offer-packages:automation` planifiee
- expiration automatique des forfaits actifs depasses
- alertes internes de solde bas
- rappels marketing internes pour forfaits expirant sous 7 jours
- traces CRM/activite pour relance marketing
- tests reservation, restauration, expiration et alertes

### Phase 5 - Recurrence

Statut: en cours pour le socle recurrent interne et la facturation de renouvellement.

- forfait recurrent
- paiement automatique
- portail client
- renouvellement

Sortie livree:

- champs recurrents sur `offer_packages` pour les forfaits catalogue
- champs de cycle recurrent sur `customer_packages`
- frequences mensuelle, trimestrielle et annuelle
- attribution client d un forfait recurrent avec calcul du premier cycle
- renouvellement manuel depuis la fiche client
- fermeture de l ancienne periode lors d un renouvellement
- lien `renewed_from_customer_package_id` entre periodes
- affichage fiche client: recurrence, prochain renouvellement et action renouveler
- generation manuelle d une facture de renouvellement depuis la fiche client
- generation automatique des factures de renouvellement dues via `offer-packages:automation`
- idempotence pour eviter plusieurs factures ouvertes sur la meme echeance
- affichage de la facture de renouvellement liee dans la fiche client
- rappel interne automatique quand un renouvellement arrive sous 7 jours
- statut recurrent `payment_due` quand une facture de renouvellement attend paiement
- traces CRM/activite pour forfait renouvele, renouvellement a venir et facture creee
- tests creation, attribution, renouvellement, rappel recurrent et facture de renouvellement

Reste a livrer plus tard:

- paiement automatique Stripe
- portail client complet pour suivre/renouveler
- upgrade/downgrade recurrent
- gestion paiement en retard/suspension

Phases restantes pour terminer l epique:

1. Finaliser la facturation recurrente Stripe

   But:

   - passer de la facture de renouvellement generee a une tentative de paiement
     automatique quand Stripe est configure

   Livrables:

   - liaison entre forfait recurrent, facture de renouvellement et moyen de paiement
   - tentative de paiement Stripe sur les renouvellements dus
   - fallback facture a payer si aucun moyen de paiement automatique n est disponible
   - journal des tentatives de paiement
   - synchronisation des statuts facture/paiement/forfait

   Sortie attendue:

   - un forfait recurrent peut etre facture et paye automatiquement, ou rester en
     attente de paiement avec une facture claire

2. Gerer paiement en retard, suspension et reprise

   But:

   - rendre les echecs de paiement lisibles et actionnables

   Livrables:

   - statut `payment_due` confirme sur facture ouverte
   - statut `suspended` apres echec ou retard selon une regle simple
   - reprise du forfait apres paiement
   - traces CRM/activite pour echec, suspension et reprise
   - notifications internes et client selon preferences

   Sortie attendue:

   - l entreprise sait quels forfaits recurrents demandent une action et le client
     comprend pourquoi son forfait est bloque

3. Annulation recurrente, upgrade et downgrade

   But:

   - permettre de modifier ou fermer proprement un forfait recurrent

   Livrables:

   - annulation fin de periode
   - annulation immediate admin avec raison obligatoire
   - upgrade manuel vers une autre offre recurrente
   - downgrade manuel vers une autre offre recurrente
   - historique des changements dans les metadonnees et l activite CRM
   - premiere version sans prorata automatique

   Sortie attendue:

   - l entreprise peut faire evoluer ou arreter un forfait recurrent sans perdre
     l historique des periodes

4. Portail client forfaits

   But:

   - donner au client final une vue autonome sur ses forfaits

   Livrables:

   - liste des forfaits actifs, consommes, expires et annules
   - solde restant, expiration et prochaine date de renouvellement
   - historique des consommations
   - factures liees au forfait
   - demande de renouvellement
   - demande d annulation selon les regles de l entreprise

   Sortie attendue:

   - le client peut suivre ses forfaits et ses factures sans contacter
     l entreprise

5. Declencheurs marketing et segments forfaits

   But:

   - utiliser les forfaits comme leviers de retention et relance

   Livrables:

   - evenement forfait achete
   - evenement solde bas
   - evenement expiration proche
   - evenement forfait expire
   - evenement forfait renouvele
   - segments clients bases sur forfait actif, solde, expiration et recurrence
   - respect des preferences de communication client

   Sortie attendue:

   - les forfaits peuvent alimenter des campagnes ciblees et des relances
     automatiques

6. Reporting V3 et QA finale

   But:

   - fermer l epique avec des indicateurs fiables et une couverture de tests

   Livrables:

   - rapport ventes ponctuelles vs forfaits consommables vs forfaits recurrents
   - rapport forfaits actifs, suspendus, annules, expires et renouveles
   - tests echec paiement et reprise
   - tests portail client
   - tests upgrade/downgrade/annulation
   - tests declencheurs marketing
   - verification non-regression V1/V2/V3

   Sortie attendue:

   - tous les criteres de la DoD V3 sont couverts, testables et utilisables en
     production

## 15. Risques a eviter

### Risque 1 - Confondre forfait client et abonnement Malikia

Les plans SaaS Malikia ne doivent pas etre melanges avec les forfaits vendus par
les entreprises a leurs propres clients.

Solution:

- ne pas utiliser `Plan`
- isoler le vocabulaire: `OfferPackage`, `CustomerPackage`

### Risque 2 - Faire trop complexe en V1

Les taxes, stocks, recurrence et reservations peuvent rendre la premiere version
lourde.

Solution:

- V1: catalogue + devis/facture + attribution manuelle

### Risque 3 - Perdre l historique quand l offre change

Si un pack change apres vente, les anciens devis/factures doivent rester exacts.

Solution:

- snapshot des lignes et prix au moment de la vente

### Risque 4 - Automatiser trop tot la consommation

Une consommation automatique mal reglee peut frustrer l utilisateur.

Solution:

- commencer par consommation manuelle
- ajouter reservation automatique plus tard avec annulation/restauration

## 16. Questions ouvertes

Decisions provisoires validees:

1. Les packs doivent pouvoir etre vendus publiquement dans une boutique, pas
   seulement en interne.
2. Un forfait catalogue ne doit pas toujours etre rattache a un client. Une
   entreprise peut creer un forfait disponible, meme si aucun client ne l a
   encore achete.
3. Les packs imbriques ne sont pas autorises en V1. Un pack ne peut pas contenir
   un autre pack pour le moment.
4. Les options facultatives ne sont pas incluses en V1.
5. La consommation se fait en unite entiere uniquement.
6. L expiration doit etre optionnelle. Recommandation: si une date d expiration
   est configuree, le forfait expire automatiquement a cette date; si aucune
   date n est configuree, il reste actif tant qu il reste du solde ou jusqu a
   annulation manuelle.
7. Le solde peut devenir negatif avec une permission admin.
8. Les credits non utilises ne sont pas rembourses par defaut. La regle produit
   doit preciser qu une fois achete, un pack ou forfait doit etre consomme et
   n est pas remboursable.
9. Les packs doivent pouvoir avoir une page publique partageable pour les mettre
   en avant et vendre.
10. Tous les secteurs doivent pouvoir creer des packs ou forfaits. La
    fonctionnalite ne doit pas etre limitee a beaute, services, restauration,
    evenementiel ou agence.

Decisions complementaires:

1. Comme les packs imbriques sont exclus en V1, il n y a aucun niveau de pack
   imbrique a gerer pour le moment.
2. La page publique doit pouvoir autoriser une vente en V1, pas seulement une
   demande de devis.
3. Seul un admin peut autoriser ou enregistrer un solde negatif.
4. Une annulation exceptionnelle sans remboursement doit exister pour fermer
   proprement un forfait client.
5. La vente publique V1 cree une facture a payer, au lieu d encaisser
   directement sans facture.
6. Les moyens de paiement V1 sont: carte via Stripe ou cash en magasin.
7. Une annulation exceptionnelle doit demander une raison obligatoire.
8. Une annulation exceptionnelle doit notifier le client.

Questions encore a trancher:

1. Est-ce que la facture publique doit expirer automatiquement si elle n est pas
   payee apres un certain delai?
2. Est-ce que le cash en magasin doit reserver le pack/forfait avant paiement,
   ou seulement creer une facture en attente?
3. Est-ce qu une notification client doit etre envoyee par email uniquement ou
   aussi par SMS/WhatsApp plus tard?

## 17. Recommandation de decision

Pour une premiere mise en place, la meilleure approche semble etre:

- une seule entite catalogue `OfferPackage`
- un champ `type` pour distinguer `pack` et `forfait`
- prix fixe en V1
- lignes incluses referencees vers `Product`
- pas de packs imbriques en V1
- snapshot au moment de la vente
- vente publique possible, avec page partageable pour les packs/forfaits actifs
- la page publique peut accepter une vente en V1 en creant une facture a payer
- paiement V1 par carte Stripe ou cash en magasin
- integration devis/facture avant reservation
- consommation manuelle avant automatisation
- consommation en unites entieres uniquement
- expiration optionnelle, automatique seulement lorsqu une date est configuree
- solde negatif reserve aux admins
- annulation exceptionnelle sans remboursement possible, avec raison obligatoire
  et notification client

Cette approche donne une vraie valeur rapidement sans bloquer le futur.

## 18. Definition of done

### 18.1 Definition of done V1 - Catalogue, vente simple et suivi manuel

La V1 est terminee quand:

- une entreprise peut creer un pack actif
- une entreprise peut creer un forfait actif
- une entreprise peut modifier, dupliquer, archiver et reactiver une offre
- une entreprise peut ajouter des produits/services existants dans une offre
- une offre peut avoir un prix fixe et une devise
- une offre peut avoir une image, une description et un statut public/prive
- les packs imbriques sont explicitement bloques
- les options facultatives sont explicitement non supportees
- une page publique partageable existe pour une offre active et publique
- la page publique peut creer une facture a payer
- la facture publique supporte les modes de paiement V1: Stripe card ou cash en magasin
- une entreprise peut ajouter une offre a un devis
- une entreprise peut ajouter une offre a une facture
- les lignes incluses sont conservees en snapshot au moment de la vente
- les anciennes ventes restent stables si l offre catalogue est modifiee
- une entreprise peut attribuer un forfait a un client
- une entreprise peut consulter le solde d un forfait client
- une entreprise peut consommer manuellement une unite entiere
- la consommation partielle est explicitement bloquee
- un admin peut autoriser un solde negatif
- les autres roles ne peuvent pas creer de solde negatif
- une annulation exceptionnelle sans remboursement est possible
- l annulation exceptionnelle demande une raison obligatoire
- l annulation exceptionnelle notifie le client
- la regle non remboursable est affichee clairement avant achat public
- les permissions de base sont respectees
- les tests couvrent creation, edition, vente publique, devis, facture, attribution, consommation, solde negatif admin et annulation exceptionnelle

### 18.2 Definition of done V2 - Credits, reservations et automatisations legeres

La V2 est terminee quand:

- un forfait client peut etre rattache a une reservation compatible
- seuls les forfaits compatibles avec le service reserve sont proposes
- une reservation peut consommer automatiquement une unite entiere du forfait
- l annulation d une reservation peut restaurer la consommation selon une regle claire
- les forfaits avec date d expiration expirent automatiquement
- les forfaits sans date d expiration restent actifs tant qu ils ne sont pas consommes ou annules
- une commande planifiee marque les forfaits expires
- un forfait expire ne peut plus etre consomme sans action admin explicite
- le solde bas peut declencher une alerte interne
- le seuil de solde bas est configurable au niveau de l offre ou du compte
- le client peut recevoir une notification quand son forfait approche de l expiration
- le client peut recevoir une notification quand son solde devient faible
- l historique des consommations affiche la source: manuel, reservation, correction admin
- une correction admin peut ajuster le solde avec une raison obligatoire
- la fiche client separe clairement les forfaits actifs, consommes, expires et annules
- les rapports internes peuvent lister les forfaits vendus, consommes, restants et expires
- les tests couvrent consommation via reservation, restauration, expiration, alertes, corrections admin et historique

### 18.3 Definition of done V3 - Recurrence, portail client et marketing

La V3 est terminee quand:

- une entreprise peut creer un forfait recurrent
- la recurrence peut etre mensuelle, trimestrielle ou annuelle
- un forfait recurrent peut generer une facture de renouvellement
- un paiement recurrent peut etre tente via Stripe lorsque le compte est configure
- un echec de paiement place le forfait dans un statut lisible: paiement en retard ou suspension
- une entreprise peut annuler un forfait recurrent
- une entreprise peut renouveler manuellement un forfait client
- une entreprise peut proposer un upgrade ou downgrade de forfait recurrent
- le portail client affiche les forfaits actifs, soldes, expirations et consommations
- le portail client permet de voir les factures liees au forfait
- le portail client permet de demander un renouvellement ou une annulation selon les regles de l entreprise
- les evenements forfait peuvent alimenter les campagnes marketing
- les declencheurs marketing minimum existent: forfait achete, solde bas, expiration proche, forfait expire, forfait renouvele
- les emails ou notifications marketing respectent les preferences client
- les rapports V3 distinguent vente ponctuelle, forfait consommable et forfait recurrent
- les tests couvrent recurrence, renouvellement, echec paiement, portail client, upgrade/downgrade et declencheurs marketing

## 19. Phases de livraison par version

### 19.1 Phases V1 - Catalogue, vente publique et suivi manuel

Objectif V1:

Livrer une premiere version vendable qui permet aux entreprises de creer des
packs/forfaits, les vendre via facture, et suivre manuellement les forfaits
clients.

#### Phase V1.1 - Socle donnees et contrats metier

But:

- creer les primitives stables avant l UI

Livrables:

- migrations `offer_packages`
- migrations `offer_package_items`
- migrations `customer_packages`
- migrations `customer_package_usages`
- modeles Eloquent et relations
- enums ou constantes de statuts
- regles de validation centrales
- blocage explicite des packs imbriques
- blocage explicite des options facultatives

Sortie attendue:

- le domaine pack/forfait existe en base
- les tests modeles/verifications metier passent

#### Phase V1.2 - Gestion catalogue interne

But:

- permettre a l entreprise de configurer ses offres

Livrables:

- liste packs/forfaits
- creation pack
- creation forfait
- edition
- duplication
- archivage/reactivation
- ajout de produits/services existants
- prix fixe et devise
- image, description, statut public/prive

Sortie attendue:

- un owner/admin peut gerer le catalogue complet des offres

#### Phase V1.3 - Vente interne via devis et factures

But:

- connecter les offres aux flux commerciaux existants

Livrables:

- ajout d une offre a un devis
- ajout d une offre a une facture
- snapshot des lignes incluses
- affichage clair du detail inclus
- conservation historique si l offre catalogue change
- creation/attribution d un forfait client depuis facture validee ou action manuelle

Sortie attendue:

- une offre peut etre vendue sans perdre son contexte historique

#### Phase V1.4 - Page publique et facture a payer

But:

- rendre les packs/forfaits vendables publiquement

Livrables:

- page publique partageable pour offre active et publique
- affichage prix, description, inclusions et regle non remboursable
- formulaire achat public
- creation d une facture a payer
- choix paiement: Stripe card ou cash en magasin
- statut clair pour facture en attente

Sortie attendue:

- un client peut demarrer l achat d un pack/forfait depuis une page publique

#### Phase V1.5 - Suivi manuel des forfaits client

But:

- suivre l utilisation sans automatisation prematuree

Livrables:

- fiche client avec forfaits actifs
- solde initial, consomme, restant
- consommation manuelle en unite entiere
- blocage de consommation partielle
- historique des consommations
- solde negatif reserve aux admins
- annulation exceptionnelle sans remboursement
- raison obligatoire d annulation
- notification client sur annulation

Sortie attendue:

- l equipe peut suivre et ajuster les forfaits clients en production

#### Phase V1.6 - Permissions, QA et stabilisation

But:

- verrouiller la qualite avant livraison V1

Livrables:

- permissions `packages.view/manage/sell/consume/adjust`
- tests feature complets V1
- tests permissions
- tests snapshots devis/factures
- tests vente publique
- tests solde negatif admin
- tests annulation exceptionnelle
- verification UX mobile/desktop
- mise a jour documentation utilisateur interne

Sortie attendue:

- tous les criteres DoD V1 sont couverts et testables

### 19.2 Phases V2 - Reservations, expiration et alertes

Objectif V2:

Connecter les forfaits aux operations quotidiennes: reservations, expiration,
alertes et reporting.

#### Phase V2.1 - Compatibilite services et reservation

But:

- preparer la consommation depuis les reservations

Livrables:

- regles de compatibilite entre forfait et service
- filtre des forfaits compatibles dans une reservation
- selection d un forfait client depuis une reservation
- validations solde/statut/expiration

Sortie attendue:

- une reservation sait quels forfaits peuvent la couvrir

#### Phase V2.2 - Consommation automatique reservation

But:

- deduire le forfait quand une prestation est realisee

Livrables:

- consommation automatique a confirmation ou completion selon regle choisie
- historique source `reservation`
- restauration si reservation annulee selon regle
- protection contre double consommation

Sortie attendue:

- une reservation peut consommer/restaurer une unite proprement

#### Phase V2.3 - Expiration automatique

But:

- rendre les dates d expiration fiables

Livrables:

- commande planifiee d expiration
- statut `expired`
- blocage consommation si expire
- action admin explicite pour exception
- historique de changement de statut

Sortie attendue:

- les forfaits avec expiration se ferment automatiquement

#### Phase V2.4 - Alertes solde bas et expiration proche

But:

- aider l entreprise a relancer au bon moment

Livrables:

- seuil solde bas configurable
- alerte interne solde bas
- alerte expiration proche
- notification client solde bas
- notification client expiration proche
- preferences de notification minimales

Sortie attendue:

- l entreprise et le client sont prevenus avant blocage ou fin de forfait

#### Phase V2.5 - Corrections admin et audit

But:

- gerer les cas reels sans perdre la tracabilite

Livrables:

- correction admin de solde
- raison obligatoire
- historique source `correction_admin`
- separation actifs/consommes/expires/annules dans la fiche client
- journal d audit minimal

Sortie attendue:

- les exceptions sont possibles mais traçables

#### Phase V2.6 - Reporting V2 et QA

But:

- donner de la visibilite operationnelle

Livrables:

- rapport forfaits vendus
- rapport consommations
- rapport soldes restants
- rapport expirations
- tests reservations/expiration/alertes/corrections
- verification non regression V1

Sortie attendue:

- tous les criteres DoD V2 sont couverts et testables

### 19.3 Phases V3 - Recurrence, portail client et marketing

Objectif V3:

Transformer les forfaits en vrais produits recurrents et exploitables dans le
portail client et les campagnes.

#### Phase V3.1 - Modele recurrent et cycle de renouvellement

But:

- ajouter la recurrence sans casser les forfaits V1/V2

Livrables:

- type ou mode recurrent
- frequence mensuelle/trimestrielle/annuelle
- date prochain renouvellement
- statut recurrent: actif, paiement en retard, suspendu, annule
- generation de periode de forfait
- renouvellement manuel

Sortie attendue:

- un forfait recurrent peut exister et avancer de periode

#### Phase V3.2 - Facturation recurrente et Stripe

But:

- connecter la recurrence au paiement

Livrables:

- generation facture de renouvellement
- tentative paiement Stripe si configure
- fallback facture a payer
- gestion echec paiement
- suspension ou statut paiement en retard
- journal des tentatives

Sortie attendue:

- un forfait recurrent peut etre facture automatiquement ou mis en attente

#### Phase V3.3 - Upgrade, downgrade et annulation recurrente

But:

- permettre les changements de forfait

Livrables:

- upgrade manuel
- downgrade manuel
- annulation fin de periode
- annulation immediate admin
- historique des changements
- regles simples sans prorata en premiere version

Sortie attendue:

- l entreprise peut faire evoluer le forfait d un client

#### Phase V3.4 - Portail client

But:

- donner de la transparence au client final

Livrables:

- liste forfaits actifs
- soldes et expirations
- historique consommations
- factures liees
- demande de renouvellement
- demande d annulation selon regles entreprise

Sortie attendue:

- le client peut comprendre et suivre ses forfaits sans contacter l entreprise

#### Phase V3.5 - Declencheurs marketing

But:

- transformer les forfaits en leviers de retention

Livrables:

- events: forfait achete
- events: solde bas
- events: expiration proche
- events: forfait expire
- events: forfait renouvele
- segments clients bases sur forfaits
- integration campagnes/automations
- respect preferences client

Sortie attendue:

- les forfaits peuvent declencher des relances et campagnes ciblees

#### Phase V3.6 - Reporting V3 et QA finale

But:

- stabiliser les flux recurrents

Livrables:

- rapports vente ponctuelle vs forfait consommable vs recurrent
- tests recurrence
- tests renouvellement
- tests echec paiement
- tests portail client
- tests upgrade/downgrade
- tests declencheurs marketing
- verification non regression V1/V2

Sortie attendue:

- tous les criteres DoD V3 sont couverts et testables
