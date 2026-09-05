# G06 — Variantes, erreurs et décisions

Dernière mise à jour : 2026-08-11

## Arbre de décision

```text
L'offre doit-elle être demandée avec un code ?
├── Oui → Renseigner un code unique → application sur demande
└── Non → Laisser le code vide → candidate automatique si admissible

Quelle est la portée ?
├── Tout le panier admissible → Tous les clients
├── Une personne → Client spécifique
├── Un produit exact → Produit spécifique
└── Une prestation exacte → Service spécifique

Comment calculer la remise ?
├── Proportion du montant admissible → Pourcentage, > 0 et <= 100
└── Somme précise → Montant fixe, > 0 et plafonné au montant admissible

Faut-il limiter l'offre ?
├── Dans le temps → Dates requises dans tous les cas
├── En volume → Limite globale, entier >= 1
├── Par montant d'achat → Minimum >= 0
└── Suspendre sans supprimer → Statut Inactive
```

## Variante — promotion globale automatique

| Champ | Exemple | Conséquence |
| --- | --- | --- |
| Code | Vide | Le moteur peut l'évaluer automatiquement. |
| Cible | Tous les clients | Toutes les lignes admissibles sont concernées. |
| Remise | 5 % | Calculée sur le sous-total admissible. |
| Dates | J à J+7 | Fenêtre courte. |
| Statut | Active | Candidate seulement pendant la fenêtre. |

Si plusieurs promotions automatiques sont admissibles, le moteur ne les cumule pas : il choisit celle produisant la plus grande remise, puis la cible la plus spécifique, puis l'identifiant le plus ancien en dernier recours.

## Variante — client spécifique

La cible Client spécifique exige un client du même compte. Une fois le bon client sélectionné, la promotion couvre ses lignes admissibles; un autre client reçoit une erreur d'éligibilité avec le même code.

Cette variante ne signifie pas « une utilisation par client ». La limite d'utilisation reste globale.

## Variante — produit spécifique

Cette cible est la plus simple pour une future démonstration financière dans `/sales/create` sur un workspace de type Produits, car le contrôleur Vente refuse les entreprises de type Services et cette page ne charge que les articles Produit. Une promotion Produit spécifique ne s'applique pas à une prestation portant un nom similaire.

## Variante — montant fixe

| Valeur | Sous-total admissible | Remise réelle maximale |
| --- | --- | --- |
| 15 CAD | 100 CAD | 15 CAD |
| 150 CAD | 100 CAD | 100 CAD |

Le moteur plafonne une remise fixe au montant admissible et recalcule ensuite les taxes. La vidéo ne doit jamais annoncer un total négatif.

## Variante — minimum de commande

Pour une promotion Service spécifique à 10 % :

- panier total : 100 CAD ;
- ligne Consultation couleur : 35 CAD ;
- minimum : 75 CAD.

Le minimum est atteint avec le panier total de 100 CAD, mais la remise reste 3,50 CAD, soit 10 % de la seule ligne Consultation couleur.

## Erreurs utiles à montrer ou à connaître

| Symptôme | Cause | Correction | Message pédagogique |
| --- | --- | --- | --- |
| Nom signalé | Vide ou plus de 150 caractères | Donner un nom interne concis | Le nom accompagne la remise en aval. |
| Code déjà utilisé | Code présent dans le même compte après normalisation majuscule | Ouvrir la promotion existante ou choisir un code unique | Changer seulement la casse ne crée pas un nouveau code. |
| Cible demandée | Type Client, Produit ou Service sans identifiant | Choisir une cible du bon type | Global est le seul type sans cible. |
| Cible refusée | Élément absent, du mauvais type ou d'un autre compte | Choisir dans la liste du workspace | Un identifiant copié ne contourne pas l'isolation. |
| Valeur remise refusée | Vide, zéro ou négative | Saisir une valeur supérieure à 0 | Le `min=0` visuel n'autorise pas zéro côté serveur. |
| Pourcentage refusé | Valeur supérieure à 100 | Saisir 100 ou moins | Un montant fixe n'a pas cette limite, mais reste plafonné à l'application. |
| Date de fin refusée | Antérieure au début | Corriger la fenêtre | Une campagne ne peut pas finir avant de commencer. |
| Aucun badge Valide maintenant | Inactive ou aujourd'hui hors fenêtre | Vérifier statut, dates et fuseau | Le badge ne mesure pas l'éligibilité d'un panier. |
| Limite refusée | 0, valeur négative ou décimale | Laisser vide ou saisir un entier >= 1 | Vide signifie illimité. |
| Minimum refusé | Valeur négative | Laisser vide ou saisir 0 ou plus | Zéro et vide ont une validation proche mais une représentation différente. |
| Code refusé à l'utilisation | Inactif, hors dates, cible incorrecte, minimum non atteint ou limite épuisée | Vérifier chaque condition | « Actif » seul ne garantit pas l'application. |

## Erreur choisie pour le master

Le master utilise le code existant `RENTREE20` :

1. confirmer qu'il apparaît dans la bibliothèque avant la prise ;
2. remplir Bienvenue couleur avec ses valeurs canoniques ;
3. remplacer temporairement BIENVENUE10 par RENTREE20 ;
4. soumettre et cadrer l'erreur du champ Code ;
5. restaurer BIENVENUE10 ;
6. vérifier que cible, remise, dates, statut, limite et minimum sont restés corrects ;
7. enregistrer.

Si RENTREE20 est absent, ne pas annoncer une erreur d'unicité. Utiliser un code réellement présent ou montrer la date de fin antérieure au début comme erreur de secours.

## Dates durables

La documentation utilise :

- `J` : jour de tournage ;
- `J+30` : trente jours plus tard ;
- exemple préparé le 2026-08-11 : `2026-08-11` à `2026-09-10`.

Avant une nouvelle prise :

1. recalculer les dates ;
2. mettre à jour la fiche de tournage et les sous-titres si les dates sont lues ;
3. vérifier le badge Valide maintenant après sauvegarde ;
4. ne jamais retoucher graphiquement une date dans un PNG.

## Plans de secours de production

| Incident | Reprise sûre |
| --- | --- |
| BIENVENUE10 existe | Repartir d'un clone propre; ne pas remplacer le code en cours de tournage sans mise à jour globale. |
| Consultation couleur manque | Refaire ou vérifier G04; ne pas cibler une prestation approchante. |
| RENTREE20 manque | Utiliser une erreur de dates ou un autre code réellement présent. |
| Badge Valide maintenant absent | Contrôler statut, dates, date système et fuseau. |
| Pulse absent | Vérifier Social et l'accès; exclure le plan plutôt que simuler un préremplissage. |
| Pulse contient une donnée inattendue | Annuler, auditer la promotion et refaire la prise; ne pas publier. |
| La personne demande une preuve de remise sur Consultation couleur | Expliquer la limite actuelle et planifier une capsule compatible; ne pas utiliser le POS produits comme faux checkout service. |

## Limites produit à ne pas transformer en instructions

- La bibliothèque n'a ni recherche, ni filtre, ni pagination visible.
- Le badge Valide maintenant ne contrôle pas limite, minimum ou cible.
- La limite est globale, sans fréquence ni quota par cliente.
- Une catégorie de services ne peut pas être ciblée; seul un service exact le peut.
- Les promotions ne sont pas cumulées par le moteur audité.
- Le point de vente actuel ne propose pas les prestations.
- Le checkout de réservation n'applique pas ce moteur Promotions.
- Publier avec Pulse est une réutilisation marketing, pas une preuve financière.
