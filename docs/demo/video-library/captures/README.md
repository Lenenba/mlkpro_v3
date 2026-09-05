# Captures validées de la bibliothèque vidéo

Dernière mise à jour : 2026-08-11

Ce dossier reçoit uniquement les captures sélectionnées pour les scripts et le montage. Les cibles actuelles, leur route, leur état attendu, leur viewport et leur nom canonique sont définis dans [`capture-plan.csv`](../capture-plan.csv).

## Workflow

1. Provisionner le workspace indiqué dans la fiche de l'épisode.
2. Produire la capture avec le viewport demandé.
3. Enregistrer le fichier ici avec le nom exact de `canonical_filename`.
4. Passer le statut de `a_produire` à `capturee` dans le CSV.
5. Vérifier cadrage, données, confidentialité et cohérence avec la narration.
6. Passer le statut à `validee` après revue.

Les reprises utilisent `v02`, `v03`, etc. Une version déjà utilisée dans une vidéo publiée n'est jamais écrasée silencieusement.

## État initial

Les emplacements et noms sont prêts, mais aucun PNG produit n'est encore déclaré comme validé. La [galerie G03](G03/README.md) distingue explicitement les images attendues des fichiers disponibles. Les anciennes images `tmp-home*.png` et `tmp-sales-crm*.png` à la racine du dépôt appartiennent à d'autres audits ; elles ne doivent pas être recopiées dans cette bibliothèque.
