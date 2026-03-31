# Demo guide

## Statut
Le workflow demo legacy base sur `demo:seed`, `demo:reset` et le vieux bootstrap de tenants n est plus le chemin recommande.

Le provisioning de demos doit maintenant passer par:
- `Super Admin > Demo Workspaces`
- les templates de demo
- le provisioning, clone, reset baseline et purge du module demo

## Baseline locale
Pour remettre l application a plat en local sans recreer d entreprises de demo:

```bash
php artisan app:launch-reset --force
```

Ce reset cree seulement:
- le socle plateforme
- `superadmin@example.com`
- `platform.admin@example.com`
- les settings/menu de base

Il ne cree plus:
- aucun tenant demo legacy
- aucune entreprise services/products/salon
- aucun compte `is_demo`

## Provisionner une demo
1. Connectez-vous en `superadmin@example.com` ou `platform.admin@example.com`
2. Ouvrez `Super Admin > Demo Workspaces`
3. Creez une demo a partir du template/secteur voulu
4. Lancez le provisioning
5. Utilisez ensuite le clone, reset baseline, purge et l envoi d email depuis ce module

## Commandes legacy
Les commandes suivantes sont volontairement desactivees:

```bash
php artisan demo:seed
php artisan demo:reset
```

Elles renvoient maintenant vers `Demo Workspaces` pour eviter de recreer des tenants hors du module demo.

## Notes
- Le vieux parcours web `/demo` peut encore exister pour compatibilite interne, mais il ne doit plus etre le workflow principal pour provisionner des environnements de demonstration.
- Si vous avez besoin d un dataset de demo metier, creez-le depuis `Demo Workspaces` plutot que via un seeder global.
