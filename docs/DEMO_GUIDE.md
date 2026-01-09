# Demo guide (web only)

## Objectif
Ce guide explique comment activer la demo, la lancer, et comprendre ce qui se passe (seed, tour guide, reset).

## Activation rapide
Dans `.env`, activer la demo:

```
DEMO_ENABLED=true
DEMO_ALLOW_RESET=true
DEMO_ACCOUNTS_EMAIL_DOMAIN=example.test
```

Si la config est cachee:

```
php artisan config:clear
```

## Lancer la demo
1) Seed les comptes demo (idempotent, mais ne reseed pas si des devis existent; utilisez le reset pour repartir propre):

```
php artisan demo:seed service
php artisan demo:seed product
php artisan demo:seed guided
```

2) Ouvrir l ecran web `/demo` pour choisir:
   - Service demo
   - Product demo
   - Guided demo

3) Ou se connecter directement:

```
Email: service-demo@<DEMO_ACCOUNTS_EMAIL_DOMAIN>
Email: product-demo@<DEMO_ACCOUNTS_EMAIL_DOMAIN>
Email: guided-demo@<DEMO_ACCOUNTS_EMAIL_DOMAIN>
Password: password
```

## Ce qui est seed (resume)
Service demo:
- 10 clients + adresses
- 5 services
- 5 devis (draft/sent/accepted)
- 3 jobs issus de devis
- taches associees
- 2 factures (draft + paid)

Product demo:
- 6 clients
- 21 produits (categories + stock)
- 4 devis avec lignes produits
- 2 factures

Guided demo:
- 1 client + 1 propriete
- 3 services
- 1 membre d equipe (technicien)
- 20 etapes de tour guide seed dans `demo_tour_steps`

## Guided demo (tour interactif)
Le compte GUIDED_DEMO lance un tour web multi-pages:
- Surbrillance + bulle de texte + bouton Next/Back/Skip
- Progression sauvegardee en base (`demo_tour_progress`)
- Fallback localStorage si l API echoue
- Un ecran checklist: `/demo/checklist`

Le tour se base sur des `data-testid` stables et des events front (ex: `demo:customer_created`).

Etapes principales (extraits):
- Dashboard overview
- Creer un client
- Creer un devis + ajouter des lignes
- Envoyer / accepter / convertir un devis en job
- Planifier une recurrence + voir le calendrier
- Creer une tache + la marquer done
- Generer une facture + enregistrer un paiement
- Parcourir le catalogue
- Modifier un setting
- Utiliser la recherche et l activite

## Safe mode (demo)
Le middleware `demo.safe` bloque certaines actions sensibles:
- Interdit: billing settings, api tokens, exports/imports, superadmin, profile update/destroy
- Autorise: CRUD client/devis/jobs/taches/factures/produits/services

## Reset
Reset web (banner "Demo mode"):
- Bouton "Reset demo" -> POST `/demo/reset`

Reset CLI:
```
php artisan demo:reset
php artisan demo:reset --tenant_id=123
```

Le reset supprime les donnees du tenant demo et re-seed (et reset le tour).

## Troubleshooting rapide
- Message "DEMO_ENABLED is false": activer `DEMO_ENABLED=true` dans `.env` puis `php artisan config:clear`.
- Tour ne demarre pas: verifiez que vous etes connecte en guided demo et que `demo:seed guided` a tourne.
- Etape bloquee: certains steps attendent un event (ex: creation client/devis).

