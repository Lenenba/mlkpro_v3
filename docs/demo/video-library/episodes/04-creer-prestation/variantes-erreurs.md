# G04 — Variantes, erreurs et décisions

Dernière mise à jour : 2026-08-11

Ce fichier prépare les branches qui ne sont pas enregistrées dans le parcours Consultation couleur.

## Arbre de décision

```text
La prestation existe-t-elle déjà ?
├── Oui → Ouvrir la fiche existante; ne pas créer un doublon
└── Non → Ouvrir Ajouter service

La catégorie existe-t-elle et est-elle active ?
├── Oui → La sélectionner explicitement
└── Non → Créer une catégorie en ligne, puis vérifier sa sélection

Comment le prix est-il exprimé ?
├── Par prestation → Pièce
├── Par heure → Heure
├── Par surface → m2
└── Autre unité → Autre

La prestation doit-elle être réservable maintenant ?
├── Oui → Actif
└── Non → Inactif/Archivé; elle ne figurera pas dans les nouveaux sélecteurs

Des consommables doivent-ils être propagés vers les travaux compatibles ?
├── Non → Laisser Matériaux vide
└── Oui → Produit du compte ou libellé personnalisé, quantité et facturabilité contrôlées
```

## Variante — catégorie manquante

1. Cliquer **Ajouter catégorie**.
2. Saisir un nom métier précis, par exemple `Diagnostic`.
3. Cliquer **Créer**.
4. Vérifier que la nouvelle catégorie est ajoutée et sélectionnée.
5. Revenir à Coloration pour le scénario canonique ou fermer la modale sans soumettre.

Ne pas créer `Coloration 2` pour contourner une catégorie archivée ou introuvable. Vérifier d'abord la page Catégories et l'état de la catégorie existante.

## Variante — matériau existant

Exemple non soumis dans G04 :

| Champ | Valeur fictive | Effet attendu |
| --- | --- | --- |
| Produit | Shampoing réparateur 250 ml | Préremplit le libellé et, si disponibles, unité et prix. |
| Quantité | 0.10 | Représente un dixième d'unité dans un gabarit opérationnel. |
| Prix unitaire | Valeur préremplie du produit | Reste distinct du prix 35 CAD de la prestation. |
| Facturable | Non | Le consommable n'est pas annoncé comme supplément automatique. |
| Description | Produit de démonstration; ne pas déstocker dans G04. | Empêche une fausse promesse de stock. |

Ce matériau n'a pas de rôle aval dans Réservations. Les matériaux sont repris par certains flux de travaux, notamment via `WorkScheduleService`, mais Salon Éclat n'active ni Jobs ni Tâches.

## Variante — matériau personnalisé

Si aucun produit de catalogue ne convient, laisser Produit sur **Personnalisé** et renseigner un libellé. Sans produit ni libellé, la ligne disparaît lors de la normalisation et n'est pas enregistrée.

| Champ | Exemple | Règle |
| --- | --- | --- |
| Libellé | Dose test couleur | 255 caractères max. |
| Quantité | 1 | Zéro ou plus. |
| Prix unitaire | 0 | Zéro ou plus. |
| Unité | dose | 50 caractères max. |
| Facturable | Non | Décision explicite. |
| Description | Échantillon utilisé pendant le diagnostic. | 2 000 caractères max. |

## Erreurs utiles à montrer ou à connaître

| Symptôme | Cause réelle | Correction | Message pédagogique |
| --- | --- | --- | --- |
| Erreur générale sur les champs requis | Nom vide, catégorie absente ou prix négatif | Corriger la valeur signalée | Le contrôle initial se fait dans la modale avant l'appel serveur. |
| Nom refusé | Vide ou plus de 255 caractères | Utiliser un nom court | Un nom identique à une autre prestation n'est en revanche pas refusé. |
| Catégorie refusée | Catégorie inexistante ou hors du périmètre autorisé | Choisir ou créer une catégorie du compte | Ne pas envoyer un identifiant copié depuis un autre workspace. |
| Prix refusé | Non numérique ou inférieur à 0 | Saisir 0 ou plus | Un prix nul est techniquement valide mais doit être volontaire. |
| Taxe refusée | Non numérique, négative ou supérieure à 100 | Saisir un pourcentage de 0 à 100 | Vérifier le contexte fiscal avant la prise. |
| Image refusée | Format non accepté ou fichier de plus de 5 000 Ko | Utiliser JPG/JPEG/PNG/WEBP plus léger, ou aucune image | Le visuel de remplacement est acceptable. |
| Matériau absent après sauvegarde | Ligne sans produit et sans libellé | Ajouter un libellé ou choisir un produit | Une ligne décorative vide est filtrée. |
| Prestation absente de Réservations | Statut inactif | Réactiver si la prestation doit être proposée | La liste de réservation filtre les services actifs. |
| Création refusée malgré un formulaire valide | Limite de services du plan atteinte | Utiliser un plan ou clone compatible | Ne pas contourner la limite en supprimant des données partagées. |
| Accès 403 | Compte membre d'équipe | Se connecter comme propriétaire | Une permission catalogue ne suffit pas actuellement. |

## Erreur choisie pour le master

Le master montre le **nom obligatoire manquant** :

1. remplir tous les champs canoniques ;
2. vider temporairement Nom ;
3. cliquer **Créer service** ;
4. cadrer l'erreur de formulaire ;
5. restaurer `Consultation couleur` ;
6. vérifier que catégorie, prix, taxe, description et statut n'ont pas été perdus ;
7. soumettre de nouveau.

Cette erreur est reproductible sans fichier dangereux, donnée réelle ni dépendance à un élément déjà présent.

## Choix d'unité — formulations sûres

| Besoin | Choix | À éviter dans la narration |
| --- | --- | --- |
| Consultation vendue une fois | Pièce | « Pièce crée un créneau de 30 minutes. » |
| Intervention facturée au temps | Heure | « Heure bloque automatiquement une heure. » |
| Nettoyage par surface | m2 | « Le système calcule la surface. » |
| Unité non standard | Autre | « On peut saisir librement le libellé de l'unité. » |

Le sélecteur contient des valeurs fixes. La durée et la quantité réellement réservée sont gérées ailleurs.

## Plans de secours de production

| Incident | Reprise sûre |
| --- | --- |
| Consultation couleur existe déjà | Reprovisionner un clone propre; ne pas supprimer la prestation d'un workspace partagé. |
| Coloration manque | Vérifier les catégories du clone; créer une catégorie en ligne seulement si le scénario est mis à jour. |
| Taux de taxe différent | Utiliser la valeur réelle et synchroniser toutes les mentions. |
| Note de devise différente de CAD | Arrêter la prise; le scénario financier doit être adapté à la devise visible. |
| Modal masquée par le bandeau cookies | Fermer le bandeau avant de recommencer le plan. |
| Filtre de liste lent | Attendre la fin du chargement; ne pas capturer le squelette. |
| Prestation absente de Réservations | Vérifier le statut actif et recharger `/app/reservations`; ne pas prétendre que la propagation est instantanée sans preuve. |

## Limites produit à ne pas transformer en fonctions

- Le nom n'est pas unique.
- La durée n'existe pas dans ce formulaire.
- La devise n'est pas sélectionnable ici.
- L'unité `service` des données provisionnées n'est pas un choix de création visible.
- Les matériaux ne modifient pas automatiquement le prix de base dans cette modale.
- Un matériau Salon Éclat n'est pas consommé automatiquement par une réservation.
- La synchronisation externe du catalogue n'est pas une preuve visible à promettre dans G04.
