# WORKFLOW - ENTREPRISES DE PRODUITS

Derniere mise a jour: 2026-01-29

Ce document decrit le workflow principal pour les entreprises de produits (retail).

## 1. Onboarding et roles
- Creer le compte utilisateur.
- Completer l onboarding et creer l entreprise.
- Definir le proprietaire et inviter les membres d equipe.
- Roles: Proprietaire, Admin, Membre.

## 2. Catalogue produits
1. Creer categories.
2. Creer produits (nom, sku, code-barres, prix, stock, taxe, unite).
3. Renseigner fournisseur (nom + email) pour demandes de stock.
4. Televerser images (cover + galerie).
5. Parametrer suivi: standard / lot / serial.

## 3. Inventaire et entrepots
- Stock par entrepot (on_hand).
- Reserve calculee depuis commandes pending.
- Avaries et mouvements de stock via ajustements.
- Seuils minimum pour alertes low stock.

## 4. Promotions
- Definir rabais (%) + dates (debut / fin).
- Le prix promo est visible en boutique.

## 5. Boutique publique (store)
1. Page boutique publique par entreprise.
2. Recherche + filtres + categories.
3. Sections: promotions, arrivages, meilleurs vendeurs.
4. Produit vedette (si active).

## 6. Panier et commande client
1. Client ajoute au panier.
2. Choix livraison ou retrait selon preferences entreprise.
3. Validation de commande.
4. Creation vente (sale) en status pending.
5. Stock reserve pour les items pending.

## 7. Statuts de vente
- draft, pending, paid, canceled.
- Fulfillment: pending, preparing, out_for_delivery, ready_for_pickup, completed, confirmed.
- A la confirmation de paiement / fin de fulfillment: stock sortant applique.

## 8. Reservations
- Reservee = somme des quantites sur ventes pending.
- Le badge reserve affiche les commandes en attente.
- Quand la vente passe paid et/ou fulfillment complete, la reserve est liberee.

## 9. POS vs commandes portail
- POS: vente creee et geree par l equipe.
- Portail client: commande public_store/portal.
- Meme logique de reservations et d inventaire.

## 10. Paiements
- Stripe (checkout) ou manuel (cash/card).
- Paiement complet met le statut a paid.

## 11. Demande stock fournisseur
- Depuis fiche produit ou dashboard, bouton "Demander stock fournisseur".
- Utilise le champ email fournisseur du produit.

## 12. Notifications
- Notifications internes pour nouvelles commandes.
- Notifications stock bas si seuil atteint.

## 13. Tests rapides (demo)
- Utiliser LaunchSeeder.
- Verifier commandes, stocks reserves, et factures.
