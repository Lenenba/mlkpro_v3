# G06 — Checklist de validation

Dernière mise à jour : 2026-08-11

G06 est validé seulement lorsque la bibliothèque, la modale d'édition et Pulse réutilisent exactement la même promotion, et lorsqu'aucune scène ne prétend montrer une remise financière sur Consultation couleur.

## Statuts indépendants

| Lot | État actuel | Condition pour passer à validé |
| --- | --- | --- |
| Règles produit | Audité | Refaire l'audit si l'interface, la requête ou le moteur change. |
| Données Bienvenue couleur | Prêtes | Confirmer code et prestation dans le clone. |
| Fenêtre de dates | À fixer à la prise | Remplacer J/J+30 par les dates réelles. |
| Script master | Prêt | Lecture à voix haute et minutage final. |
| Captures G06-S01 à S15 | À produire | PNG présents, lisibles et revus. |
| Galerie Markdown | En attente | Images réelles intégrées avec légende. |
| Vidéo master | À produire | Export regardé intégralement. |
| Coupe rapide | À produire | Dérivée sans contradiction. |
| Sous-titres | À produire | Synchronisés et relus. |

## Prévol fonctionnel

- [ ] Le clone `salon_eclat_complete` est jetable et réservé au tournage.
- [ ] Promotions, Services et Social sont actifs.
- [ ] Le compte connecté peut gérer Promotions et ouvrir Pulse.
- [ ] BIENVENUE10 est absent de la bibliothèque.
- [ ] RENTREE20 existe et peut servir à l'erreur contrôlée.
- [ ] Consultation couleur existe, est active et apparaît dans les cibles Service.
- [ ] J et J+30 ont été calculés avec le jour réel de la prise.
- [ ] La date système et le fuseau permettent le badge Valide maintenant.
- [ ] Le français, le thème clair, le zoom 100 % et le viewport 1920 × 1080 sont fixés.
- [ ] Aucun compte social réel ne sera publié ou contacté.

## QA du parcours principal

- [ ] La bibliothèque initiale montre RENTREE20 sans le modifier.
- [ ] L'absence de BIENVENUE10 est vérifiée sans inventer un champ Recherche.
- [ ] La modale initiale montre Global, Pourcentage, aujourd'hui et Active.
- [ ] Nom et code correspondent aux valeurs canoniques.
- [ ] Service spécifique fait apparaître le champ Cible.
- [ ] Consultation couleur est la cible exacte.
- [ ] La remise vaut 10 %, jamais 0,10 % ni 10 CAD.
- [ ] La date de fin est égale ou postérieure au début et la fenêtre couvre aujourd'hui.
- [ ] Le statut est Active.
- [ ] La limite vaut 50 et le minimum reste vide.
- [ ] L'erreur RENTREE20 est réelle, lisible et corrigée.
- [ ] La modale se ferme après Enregistrer la promotion.
- [ ] La ligne finale montre code, cible, remise, dates, 0/50, Active et Valide maintenant.
- [ ] La réouverture en édition confirme toutes les valeurs.
- [ ] Pulse reprend les données sans qu'aucun post soit enregistré ou publié.

## QA des captures

Pour chaque ligne de la [shot-list locale](shot-list.csv) :

- [ ] Le fichier existe sous `captures/G06/desktop/` avec son nom canonique.
- [ ] L'ID, la route et l'état correspondent au CSV.
- [ ] Le viewport est 1920 × 1080 sans étirement.
- [ ] Le contexte Promotions ou Pulse est identifiable.
- [ ] Aucun curseur ne masque code, cible, date, compteur ou erreur.
- [ ] Aucun jeton, connexion sociale, donnée personnelle ou brouillon réel n'est visible.
- [ ] Le badge Valide maintenant est authentique, pas ajouté en annotation.
- [ ] G06-S13 indique clairement `aucune publication`.
- [ ] G06-S14 et S15 sont marquées comme annexes non soumises.
- [ ] Les originaux et versions annotées restent séparés.

## QA de cohérence des données

- [ ] Le nom reste Bienvenue couleur.
- [ ] Le code reste BIENVENUE10 après l'erreur.
- [ ] La cible reste Service spécifique / Consultation couleur.
- [ ] Le type et la valeur restent Pourcentage / 10.
- [ ] Les mêmes dates apparaissent dans formulaire, liste, édition et Pulse.
- [ ] Le statut reste Active.
- [ ] La limite reste 50 et le compteur initial 0/50.
- [ ] Le minimum reste vide et n'est pas annoncé comme zéro imposé.
- [ ] RENTREE20 n'est jamais présenté comme le code final.

## QA d'honnêteté fonctionnelle

- [ ] La narration explique qu'un code présent doit être demandé avec ce code.
- [ ] Le badge Valide maintenant n'est pas présenté comme une preuve d'éligibilité complète.
- [ ] La limite est décrite comme globale, non par cliente.
- [ ] La différence entre minimum total et remise sur ligne ciblée est exacte.
- [ ] Pulse est présenté comme une preuve marketing, pas financière.
- [ ] Aucune capture de `/sales/create` ne prétend vendre Consultation couleur.
- [ ] Aucun checkout Réservations n'est présenté comme appliquant BIENVENUE10.
- [ ] Aucune utilisation n'est consommée pendant G06.

## QA narration et accessibilité

- [ ] Le débit reste entre 125 et 145 mots par minute.
- [ ] Les libellés français visibles correspondent à la narration.
- [ ] Les dates sont lues en langage durable J/J+30 ou ont été mises à jour partout.
- [ ] Les notions code, cible, fenêtre, limite et minimum sont distinguées.
- [ ] Les incrustations ne reposent pas uniquement sur une couleur.
- [ ] La coupe courte renvoie au master pour erreurs, variantes et limite financière.

## QA après export

- [ ] Regarder le master intégralement à vitesse normale.
- [ ] Vérifier qu'aucun identifiant social réel n'apparaît pendant la transition Pulse.
- [ ] Vérifier les liens vers G04, le guide des champs et la galerie.
- [ ] Vérifier que la miniature promet « Créer une promotion », pas « Encaisser avec BIENVENUE10 ».
- [ ] Inscrire l'URL finale dans le catalogue global lors de l'intégration parent.
- [ ] Ne passer les captures à `publiee` qu'après publication réelle.

## Critère de sortie

Le lot est livrable lorsque :

1. G06-S01 à G06-S13 sont validées, ou S13 est explicitement exclue si Pulse n'est pas accessible ;
2. G06-S14 et S15 sont validées ou exclues du montage ;
3. bibliothèque, édition et Pulse montrent les mêmes données ;
4. aucune publication, vente ou utilisation n'a été créée ;
5. la limite d'application financière est dite clairement ;
6. la QA fonctionnelle, visuelle, financière et confidentialité est verte.
