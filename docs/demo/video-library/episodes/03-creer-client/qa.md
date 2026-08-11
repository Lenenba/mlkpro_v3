# G03 — Checklist de validation

Dernière mise à jour : 2026-08-11

Un épisode n'est pas « prêt à publier » parce que son script existe. G03 est validé seulement lorsque les données, les captures, le comportement fonctionnel, la narration et les sous-titres racontent exactement le même parcours.

## Statuts indépendants

| Lot | État actuel | Condition pour passer à validé |
| --- | --- | --- |
| Règles produit | Audité | Refaire l'audit si le formulaire change. |
| Données Nora | Prêtes | Confirmer leur absence dans le clone juste avant la prise. |
| Script master | Prêt | Lecture à voix haute et minutage final. |
| Captures G03-S01 à S16 | À produire | PNG présents, lisibles et passés à `validee`. |
| Galerie Markdown | En attente | Images réellement intégrées avec légende. |
| Vidéo master | À produire | Export regardé intégralement. |
| Coupe rapide | À produire | Dérivée du master sans contradiction. |
| Sous-titres | À produire | Synchronisés et relus. |

## Prévol fonctionnel

- [ ] La prise se fait dans un clone jetable de Salon Éclat, pas dans un workspace partagé marqué comme envoyé.
- [ ] Le compte possède la permission `customers.create`.
- [ ] Nora Bouchard et `nora.bouchard@example.test` sont absentes de Clients et Utilisateurs.
- [ ] Julie Nadeau existe et son courriel exact, suffixe dynamique compris, est relevé sur le clone si cette fiche sert à l'erreur contrôlée.
- [ ] Le module Factures est actif ; Devis, Jobs et Tâches sont absents du preset Salon.
- [ ] Le français, le thème clair, le zoom 100 % et le viewport 1920 × 1080 sont fixés.
- [ ] L'autoremplissage du navigateur, les notifications et les onglets personnels sont désactivés.
- [ ] Le mécanisme de remise à zéro du clone est connu avant toute création.

## QA du parcours principal

- [ ] La recherche initiale ne montre aucun doublon Nora.
- [ ] Le type Particulier est visible avant la saisie.
- [ ] Prénom, nom et courriel sont remplis avec les valeurs canoniques.
- [ ] La date de naissance est passée et entièrement fictive.
- [ ] L'accès portail est désactivé avant la soumission finale.
- [ ] Seule l'auto-validation Factures apparaît dans Salon Éclat.
- [ ] La remise fidélité vaut 0.
- [ ] L'adresse utilise des données fictives et contient Montréal dans Ville.
- [ ] La case Adresse de facturation identique, désactivée par défaut, est cochée avant la soumission et son rôle est expliqué sans promettre une deuxième adresse.
- [ ] L'erreur de courriel est réelle, lisible et corrigée.
- [ ] Le bouton final utilisé est Enregistrer client.
- [ ] La redirection réelle vers `/customer` est visible.
- [ ] Le message de succès et la ligne Nora sont visibles au moins deux secondes.
- [ ] La fiche Nora est ouverte depuis la liste.
- [ ] Nora apparaît dans le sélecteur d'une réservation sans que cette réservation soit enregistrée.

## QA des captures

Pour chaque ligne G03 du [plan global](../../capture-plan.csv) et de la [shot-list locale](shot-list.csv) :

- [ ] Le fichier existe sous `captures/G03/desktop/` avec son nom canonique.
- [ ] L'ID, la route et l'état correspondent au CSV.
- [ ] Le viewport est 1920 × 1080, sans étirement.
- [ ] Le titre de page ou le contexte de navigation permet de comprendre l'écran.
- [ ] Le pointeur ne masque ni le libellé ni l'erreur.
- [ ] Aucun mot de passe, cookie, jeton, URL signée ou donnée réelle n'est visible.
- [ ] Les textes importants restent lisibles à la résolution d'export.
- [ ] La capture G03-S16 indique clairement qu'elle vient d'un workspace de référence.
- [ ] Le fichier annoté, s'il existe, est séparé de l'original.
- [ ] Le statut n'est passé à `validee` qu'après une revue humaine.

## QA de cohérence des données

- [ ] Nora porte le même prénom, nom, courriel, téléphone et date sur tous les plans.
- [ ] Le téléphone est `+1 514 555-0147`.
- [ ] La date est `1993-04-18`.
- [ ] L'adresse est `245 rue Démonstration, Montréal, Québec, H2X 3K4, Canada`.
- [ ] La description est identique dans le formulaire et la fiche.
- [ ] Les sous-titres n'emploient pas Julie lorsque l'écran montre Nora.
- [ ] La civilité n'est jamais présentée comme un champ du formulaire.
- [ ] Aucun tag, niveau fidélité ou multi-site n'est prétendu créé dans cet écran.

## QA narration et accessibilité

- [ ] Débit moyen entre 125 et 145 mots par minute.
- [ ] Les libellés lus correspondent à la version française visible.
- [ ] La conséquence du portail est expliquée avant le clic.
- [ ] Les notions obligatoire, optionnel et conditionnel sont distinguées.
- [ ] Les incrustations ne reposent pas uniquement sur une couleur.
- [ ] Les zooms de montage conservent suffisamment de contexte.
- [ ] Les sous-titres sont synchronisés, ponctués et relus.
- [ ] La coupe courte renvoie vers le master pour les variantes.

## QA après export

- [ ] Regarder le master de bout en bout sans accélération.
- [ ] Vérifier qu'aucun frame ne révèle une donnée personnelle lors des transitions.
- [ ] Tester les liens vers G02, G05, le guide des champs et la description publiée.
- [ ] Vérifier que la miniature dit « Créer un client » sans promettre le portail ou la fidélité.
- [ ] Inscrire l'URL finale dans `series-catalog.csv`.
- [ ] Passer les captures réellement publiées à `publiee` dans `capture-plan.csv`.

## Critère de sortie

Le lot est livrable lorsque :

1. G03-S01 à G03-S14 sont validées ;
2. G03-S15 et S16 sont validées ou clairement marquées comme annexes non publiées ;
3. la liste, la fiche et le sélecteur Réservation montrent la même Nora ;
4. aucune invitation réelle n'a été déclenchée ;
5. la QA fonctionnelle, visuelle, confidentialité et sous-titres est verte.
