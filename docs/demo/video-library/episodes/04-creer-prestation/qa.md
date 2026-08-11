# G04 — Checklist de validation

Dernière mise à jour : 2026-08-11

G04 est validé seulement lorsque la prestation, la liste, la modale d'édition et le sélecteur Réservations montrent les mêmes données.

## Statuts indépendants

| Lot | État actuel | Condition pour passer à validé |
| --- | --- | --- |
| Règles produit | Audité | Refaire l'audit si `ServiceForm.vue` ou `ServiceRequest.php` change. |
| Données Consultation couleur | Prêtes | Confirmer l'absence dans le clone juste avant la prise. |
| Script master | Prêt | Lecture à voix haute et minutage final. |
| Captures G04-S01 à S14 | À produire | PNG présents, lisibles et revus. |
| Galerie Markdown | En attente | Images réelles intégrées avec légende. |
| Vidéo master | À produire | Export regardé intégralement. |
| Coupe rapide | À produire | Dérivée du master sans contradiction. |
| Sous-titres | À produire | Synchronisés et relus. |

## Prévol fonctionnel

- [ ] La prise se déroule dans un clone jetable de `salon_eclat_complete`.
- [ ] Le compte connecté est le propriétaire, pas un membre d'équipe.
- [ ] Le module Services est actif et `/service` est accessible.
- [ ] Consultation couleur est absente avant la prise.
- [ ] Coloration existe et n'est pas archivée.
- [ ] La devise affichée est CAD.
- [ ] Le taux prévu est 14.975 ou toute différence a été répercutée partout.
- [ ] Le navigateur est en français, thème clair, zoom 100 %, viewport 1920 × 1080.
- [ ] Le bandeau cookies, les notifications et l'autoremplissage ne masquent pas la modale.
- [ ] La limite de prestations du clone permet une création supplémentaire.

## QA du parcours principal

- [ ] La recherche initiale montre l'absence du nom exact.
- [ ] Le titre visible est `Nouveau service` et la route reste `/service`.
- [ ] La catégorie Coloration est choisie explicitement.
- [ ] L'unité Pièce est visible sans être appelée durée.
- [ ] Le prix vaut 35.00 et la devise visible vaut CAD.
- [ ] Le taux de taxe vaut 14.975.
- [ ] La prestation reste active.
- [ ] La description est exactement `Diagnostic couleur et recommandation personnalisée.`
- [ ] Aucune image ni aucun matériau n'est ajouté au parcours principal.
- [ ] L'erreur de nom requis est réelle et corrigée.
- [ ] Le bouton final utilisé est `Créer service`.
- [ ] La modale se ferme et la liste `/service` reste affichée.
- [ ] La nouvelle ligne montre nom, prix, catégorie et Actif.
- [ ] La réouverture en édition confirme unité, taxe et description.
- [ ] Consultation couleur apparaît dans une nouvelle réservation non enregistrée.

## QA des captures

Pour chaque ligne de la [shot-list locale](shot-list.csv) :

- [ ] Le fichier existe sous `captures/G04/desktop/` avec son nom canonique.
- [ ] L'ID, la route et l'état correspondent au CSV.
- [ ] Le viewport est 1920 × 1080 sans étirement.
- [ ] Le titre de la modale ou le contexte de liste est visible.
- [ ] Le curseur ne masque aucun montant, libellé ni erreur.
- [ ] Aucune donnée personnelle, clé, cookie ou notification n'est visible.
- [ ] Le texte anglais de la devise est capturé tel qu'il existe, sans retouche trompeuse.
- [ ] G04-S14 porte clairement la mention Annexe ou Variante.
- [ ] Toute version annotée reste séparée de l'original.
- [ ] Le statut n'est validé qu'après une seconde revue humaine.

## QA de cohérence des données

- [ ] Le nom est toujours Consultation couleur.
- [ ] La catégorie est toujours Coloration.
- [ ] Le prix est toujours 35 CAD.
- [ ] Le taux de taxe est identique dans saisie, édition et sous-titres.
- [ ] L'unité est Pièce et n'est jamais traduite en durée.
- [ ] La durée de 30 minutes n'est montrée que dans le contexte Réservations.
- [ ] La description est identique dans toutes les prises.
- [ ] Le statut reste actif jusqu'à la preuve aval.
- [ ] Aucun matériau d'annexe n'est enregistré par erreur.

## QA narration et accessibilité

- [ ] Le débit reste entre 125 et 145 mots par minute.
- [ ] Les termes visibles `Services`, `Ajouter service` et `Créer service` sont respectés.
- [ ] Une incrustation explique `Unité ≠ durée` sans dépendre uniquement d'une couleur.
- [ ] La devise héritée du compte est expliquée avant le prix final.
- [ ] Les notions requis, optionnel et variante sont distinguées.
- [ ] La version courte renvoie au master pour catégories, matériaux et erreurs.
- [ ] Les sous-titres n'ajoutent aucune fonction absente du formulaire.

## QA après export

- [ ] Regarder le master intégralement à vitesse normale.
- [ ] Vérifier l'absence de donnée sensible pendant l'ouverture des menus.
- [ ] Vérifier les liens vers G02, G05, G06 et le guide des champs.
- [ ] Vérifier que la miniature promet « Créer une prestation », pas « Régler sa durée ».
- [ ] Inscrire l'URL finale dans le catalogue global lors de l'intégration parent.
- [ ] Ne passer les captures à `publiee` qu'après publication réelle.

## Critère de sortie

Le lot est livrable lorsque :

1. G04-S01 à G04-S13 sont validées ;
2. G04-S14 est validée ou exclue explicitement du montage ;
3. la liste, l'édition et Réservations montrent la même prestation ;
4. aucune durée ni consommation de matériau n'est attribuée à tort à la création ;
5. la QA fonctionnelle, visuelle, financière et confidentialité est verte.
