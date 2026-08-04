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

## Gate PHP obligatoire avant livraison

- Avant tout commit qui ajoute, modifie ou supprime un fichier PHP, terminer l’indexation (`git add`), puis exécuter `composer qa:format`; le contrôle doit refuser tout fichier PHP partiellement indexé ou toute suppression PHP non indexée. Relancer ce contrôle immédiatement avant le push ou la livraison.
- Après toute correction de format, relancer `composer qa:format` et vérifier `git diff --check`.
- Un lot PHP ne doit jamais être présenté comme prêt à fusionner tant que le check de format n’est pas vert.
- Si PHP, Composer ou Pint ne peut pas être exécuté localement, déclarer le lot bloqué côté validation et attendre une CI verte avant toute fusion ; l’impossibilité locale ne vaut jamais validation.
- Dans le compte rendu de livraison, consigner explicitement le résultat du check PHP et, en cas de CI, l’exécution qui le prouve.
