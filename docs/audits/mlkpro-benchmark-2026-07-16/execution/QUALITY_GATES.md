# Protocole obligatoire de tests et de non-régression

Dernière mise à jour : 2026-07-16
Statut : **applicable à tous les tickets du programme**

## Règle de décision

Chaque changement est un lot atomique. Il doit posséder une preuve **avant**, une preuve **après** et un rollback.

Un ticket reste ouvert si l’un des éléments suivants est vrai :

- le comportement modifié ne possède pas de test ciblé ;
- un test ciblé ou une gate applicable échoue ;
- un workflow protégé produit un résultat différent sans décision approuvée ;
- la mesure après changement n’utilise pas la même méthode que la baseline ;
- le rollback n’est plus possible ou n’est pas documenté ;
- une preuve contient un secret ou une donnée client directe.

## Cycle obligatoire par lot

1. Identifier le comportement, les contrats et les workflows affectés.
2. Exécuter le test ciblé avant changement et enregistrer son résultat.
3. Ajouter d’abord un test de caractérisation si le comportement n’est pas couvert.
4. Appliquer un seul changement cohérent.
5. Relancer le test ciblé.
6. Exécuter la non-régression du module.
7. Exécuter les gates transversales applicables.
8. Comparer avant/après avec la même méthode.
9. Effectuer la vérification humaine ou le canari si le changement touche un workflow externe.
10. Enregistrer les preuves et le verdict dans `VALIDATION_LOG.md`.

Une correction de bug peut commencer par un test rouge qui reproduit le défaut. Dans ce cas, la baseline attendue est l’échec précis de ce test, pas un échec général de la suite.

## Gates communes

Pour tout changement PHP ou de configuration Laravel :

```powershell
composer qa:format
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php -d memory_limit=512M vendor/bin/pest tests/chemin/TestCible.php --compact
php -d memory_limit=512M vendor/bin/pest --compact
git diff --check
```

Pour tout changement frontend ou de dépendance JavaScript :

```powershell
npm run qa:build
npx playwright test tests/e2e/scenario-concerne.spec.js
npm run qa:e2e
git diff --check
```

Le build seul ne valide pas un parcours utilisateur. Le test navigateur ciblé est requis lorsqu’un écran, une navigation, une modale, un formulaire ou un composant interactif change.

## Matrice par type de changement

| Type | Test ciblé minimal | Non-régression | Contrôle complémentaire |
|---|---|---|---|
| Secret ou fournisseur externe | Client HTTP faké, configuration absente, succès et rejet | Module consommateur puis Pest complet | Canari contrôlé avant/après, journaux expurgés |
| Dépendance PHP | Tests des domaines utilisant le paquet | PHPStan, Pest complet, build | `composer audit`, installation depuis le lock, MySQL si données |
| Dépendance JavaScript | Build et scénario Playwright concerné | Playwright complet | `npm audit`, `npm ci`, comparaison des bundles |
| Queue ou job | Routage, payload, backoff, retry et échec terminal | Tests async et Pest complet | `queue:health`, worker prêt, job canari |
| Requête, modèle ou migration | Résultat métier et isolation tenant | SQLite complet et MySQL ciblé/complet | comparaison SQL, volume, rollback migration |
| API, route ou props Inertia | Schéma, statut, permissions et données | Tests Feature du module et Pest complet | OpenAPI/contrat consommateur si applicable |
| Interface ou design | Composant et scénario utilisateur | Build et Playwright complet | mobile, accessibilité, captures avant/après |
| Performance interne | Égalité fonctionnelle sur mêmes entrées | Suite du domaine et globale | p50/p95/p99, requêtes, mémoire, charge comparable |
| Documentation analytique | liens, formats structurés et secret scan | validation de l’artefact rendu | cohérence entre sources et rapport |

## Données et MySQL

Tout changement de migration, SQL, pagination, agrégation, verrou ou transaction doit être testé sur SQLite et MySQL.

```powershell
composer qa:test:mysql -- tests/Feature/TestConcerne.php
composer qa:test:mysql
```

La commande `composer test:safe` n’est pas utilisée comme contrôle courant, car elle manipule la base configurée dans `.env`. La suite MySQL isolée est privilégiée.

## Twilio et autres canaris externes

- Les tests automatisés utilisent exclusivement `Http::fake` et des identifiants fictifs.
- Les variables fournisseur sont forcées à vide dans PHPUnit afin qu’un test ne puisse pas utiliser accidentellement le compte réel.
- Un canari réel exige un environnement et un destinataire contrôlés.
- Aucun numéro complet, jeton ou en-tête d’authentification n’est copié dans les preuves.
- Un succès HTTP ne suffit pas : le statut de livraison doit être vérifié côté fournisseur lorsqu’il est disponible.
- Après rotation, les processus persistants et workers sont redémarrés avant le canari final.

## Preuve minimale dans le journal

Chaque entrée de validation indique :

- commit et environnement ;
- commande ciblée avant et résultat ;
- changement exact ;
- commande ciblée après et résultat ;
- suites de non-régression et résultats ;
- mesure avant/après si performance ;
- vérification métier ou canari ;
- rollback ;
- risque restant ;
- verdict `validé`, `refusé` ou `bloqué`.

Les journaux complets restent dans la CI ou un stockage contrôlé. Le journal versionné ne conserve que des résultats synthétiques expurgés.
