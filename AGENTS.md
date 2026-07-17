# Règles de travail du dépôt

## Politique Git — règle impérative

- Tous les travaux de développement doivent être effectués uniquement sur la branche `develop` ou sur une branche de travail créée à partir de `develop`.
- Ne jamais travailler directement sur la branche `main`.
- Ne jamais créer de commit sur `main`.
- Ne jamais pousser (`git push`) vers `main`.
- Ne jamais fusionner une branche dans `main`.
- Toute pull request créée par un agent doit cibler `develop`, jamais `main`.
- Seul le propriétaire du dépôt, Jules Roger Sombangnen, est autorisé à pousser ou à fusionner sur `main`.
- Avant toute modification, vérifier la branche active. Si elle est `main`, arrêter le travail et basculer sur `develop` avant de modifier un fichier.
- Si une demande semble nécessiter une action sur `main`, l’agent doit s’arrêter à une branche ou une pull request vers `develop`; Jules Roger Sombangnen réalisera lui-même toute opération ultérieure sur `main`.

Ces règles sont permanentes et prioritaires pour tous les agents et collaborateurs automatisés travaillant dans ce dépôt.
