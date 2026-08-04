# Runner HTTP P0-006

Ce runner Node 20 sans dépendance externe exécute un seul scénario du manifeste produit par `capacity:plan --json`. Il ne remplace ni l’approbation de campagne, ni les canaris P0-005, ni les marqueurs `capacity:scenario:start` et `capacity:scenario:stop`.

## Garanties fail-closed

- le plan doit être `ready_for_approved_harness`, son préflight doit être prêt et toutes ses listes d’incidents doivent être vides ;
- le SHA-256 du fichier [p0-006-runner.mjs](../../../../scripts/capacity/p0-006-runner.mjs) doit correspondre à `CAPACITY_BASELINE_RUNNER_HASH`, au contexte approuvé et au plan ;
- le SHA-256 des octets exacts de la fixture v2 fermée doit correspondre à `CAPACITY_BASELINE_FIXTURE_HASH`, au contexte approuvé et au plan ; aucun champ de politique ou champ inconnu n’y est accepté ;
- le runner recalcule le `manifest_hash` canonique. Ce manifeste couvre aussi le bloqueur formel et le profil complet, dont la cadence et le timeout ;
- le runner recalcule `baseline_fingerprint`. Cette empreinte lie le run, la fenêtre, le mode, les approbations, les canaris, l’isolation, le runner, la fixture et les origines autorisées ;
- les origines autorisées viennent uniquement de `CAPACITY_BASELINE_ALLOWED_ORIGINS` et du plan signé. Une fixture ne peut ni s’autoriser elle-même, ni redéfinir un timeout ;
- HTTPS est obligatoire. Il n’existe aucun argument, variable d’environnement ou mode de test public permettant d’autoriser HTTP ;
- la méthode, le nom de route et l’URI doivent correspondre au manifeste. Les traversées de chemin, en-têtes de routage, redirections et substitutions de méthode sont refusés ;
- `production_read_only` refuse tout scénario `controlled_write`. Une écriture staging exige un tenant isolé explicitement attesté ;
- chaque réponse est consommée avec une limite ferme de 1 Mio. Une erreur de transport, un statut inattendu ou une assertion métier invalide échoue fermé ;
- la cadence est à délai fixe après chaque réponse : une réponse lente ne crée jamais de rafale de rattrapage ;
- les cookies, jetons CSRF, corps, paramètres signés, réponses et URL cibles ne sont jamais écrits dans le résultat ou les diagnostics ;
- le résultat utilise le schéma fermé `schema_version: 3`. Il expose l’empreinte de l’origine, jamais l’origine brute, et il est créé sans écraser un fichier existant ;
- à l’import, Laravel recalcule les identités depuis sa configuration courante, relance le préflight, refuse un bloqueur actif et exige un cycle `started` puis `stopped` contenant exactement la fenêtre du runner.

Ces empreintes prouvent la cohérence des fichiers et de la configuration approuvée ; elles ne constituent pas une signature cryptographique d’un poste d’exécution hostile. L’accès au serveur, au cache d’observabilité, au répertoire d’import et aux variables du baseline reste réservé aux opérateurs autorisés, avec conservation des journaux de campagne.

## 1. Préparer la fixture privée

Créer les répertoires contrôlés :

```powershell
New-Item -ItemType Directory -Force storage\app\capacity-fixtures | Out-Null
New-Item -ItemType Directory -Force storage\app\capacity-plans | Out-Null
New-Item -ItemType Directory -Force storage\app\capacity-imports | Out-Null
```

Copier [capacity-runner-fixtures.example.json](capacity-runner-fixtures.example.json) vers `storage/app/capacity-fixtures/p0-006.json`, puis remplacer tous les marqueurs `REPLACE_…`. Le runner les refuse récursivement.

La fixture doit utiliser exclusivement un tenant et des données synthétiques. Les intégrations sortantes des scénarios d’écriture doivent pointer vers leurs bacs à sable ou être neutralisées selon l’approbation de campagne. Ne jamais placer une fixture réelle dans `docs`, `scripts`, un artefact CI public ou un répertoire versionné.

## 2. Calculer les empreintes exactes

Calculer les empreintes des fichiers qui seront réellement exécutés :

```powershell
$runnerHash = (Get-FileHash -Algorithm SHA256 scripts\capacity\p0-006-runner.mjs).Hash.ToLowerInvariant()
$fixtureHash = (Get-FileHash -Algorithm SHA256 storage\app\capacity-fixtures\p0-006.json).Hash.ToLowerInvariant()
$runnerHash
$fixtureHash
```

Toute modification ultérieure du runner ou de la fixture exige de recalculer l’empreinte, de renouveler l’approbation et de régénérer le plan.

## 3. Configurer le contexte approuvé

Configurer toutes les variables du baseline, notamment :

```dotenv
CAPACITY_BASELINE_RUNNER=node20-p0-006-v1
CAPACITY_BASELINE_RUNNER_HASH=<empreinte du runner>
CAPACITY_BASELINE_FIXTURE_HASH=<empreinte de la fixture privée>
CAPACITY_BASELINE_ALLOWED_ORIGINS=https://staging.example.ca
CAPACITY_RUNNER_DURATION_TOLERANCE_SECONDS=2
```

`CAPACITY_BASELINE_ALLOWED_ORIGINS` accepte une ou plusieurs origines HTTPS exactes séparées par des virgules. Une origine ne contient ni chemin, ni paramètres, ni fragment, ni identifiants. Le timeout de chaque scénario se configure avec les variables `CAPACITY_*_REQUEST_TIMEOUT_MS` ; il doit être compris entre 500 et 60000 ms.

Compléter également le run, l’environnement, le commit, la fenêtre UTC, le trafic, les exclusions, le mode, les deux approbateurs distincts, l’attestation des canaris P0-005 et, si nécessaire, celle du tenant isolé. Après modification, redémarrer les processus persistants pour qu’ils chargent la même configuration.

## 4. Produire et contrôler le plan

```powershell
$planJson = php artisan capacity:plan --json
if ($LASTEXITCODE -ne 0) { throw 'capacity plan is not ready' }
$planJson | Set-Content -Encoding utf8 storage\app\capacity-plans\p0-006.json

$plan = Get-Content -Raw storage\app\capacity-plans\p0-006.json | ConvertFrom-Json
if ($plan.status -ne 'ready_for_approved_harness') { throw 'capacity plan status is invalid' }
if (-not $plan.preflight.ready) { throw 'capacity preflight is not ready' }
if ($plan.issues.Count -ne 0 -or $plan.configuration_issues.Count -ne 0) { throw 'capacity plan contains issues' }
```

Le plan est généré seulement après la préparation de la fixture, car il lie son empreinte et les origines approuvées. Le fichier du plan ne doit pas être modifié après sa génération.

## 5. Orchestration obligatoire

Pour chaque scénario, dans le run et la fenêtre approuvés :

```powershell
$scenarioKey = 'dashboard_usage'
$plan = Get-Content -Raw storage\app\capacity-plans\p0-006.json | ConvertFrom-Json
$safeRunId = ([string] $plan.run_id) -replace '[^A-Za-z0-9._-]', '_'
$runTimestamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$resultFile = "$safeRunId-$scenarioKey-$runTimestamp.json"
$resultPath = "storage\app\capacity-imports\$resultFile"
$runnerExit = 1
$stopExit = 1

php artisan capacity:scenario:start $scenarioKey
if ($LASTEXITCODE -ne 0) { throw 'capacity scenario start failed' }

try {
    node scripts\capacity\p0-006-runner.mjs `
        --plan storage\app\capacity-plans\p0-006.json `
        --fixtures storage\app\capacity-fixtures\p0-006.json `
        --scenario $scenarioKey `
        --output $resultPath
    $runnerExit = $LASTEXITCODE
}
finally {
    php artisan capacity:scenario:stop $scenarioKey
    $stopExit = $LASTEXITCODE
}

if ($stopExit -ne 0) { throw 'capacity scenario stop failed' }
if ($runnerExit -ne 0) {
    if (Test-Path -LiteralPath $resultPath) {
        New-Item -ItemType Directory -Force storage\app\capacity-failed | Out-Null
        Move-Item -LiteralPath $resultPath -Destination storage\app\capacity-failed
    }
    throw 'capacity runner failed closed; archive only and start a new approved run after remediation'
}

php artisan capacity:result:import $scenarioKey $resultFile --json
if ($LASTEXITCODE -ne 0) { throw 'capacity result import failed' }
```

Le `finally` rend le marqueur `stop` obligatoire même si Node échoue. Un agrégat contenant une erreur de transport ou d’assertion est archivé, jamais importé et jamais présenté comme accepté. Une clé ne pouvant être rejouée dans le même scope, toute correction après échec exige un nouveau run approuvé.

Exécuter les sept clés sans parallélisme :

1. `dashboard_usage` ;
2. `customer_detail_access` ;
3. `reservation_creation` ;
4. `sales_creation` ;
5. `public_request_submission` ;
6. `public_store_browse` ;
7. `public_store_checkout`.

Après les sept imports :

```powershell
$capacityReport = php artisan capacity:report --json --strict | ConvertFrom-Json
if ($LASTEXITCODE -ne 0 -or $capacityReport.status -ne 'healthy') { throw 'capacity report is not healthy' }

php artisan observability:report --json --strict
if ($LASTEXITCODE -ne 0) { throw 'observability report is not healthy' }
```

Le code de sortie `0` de `capacity:report --strict` accepte aussi `accepted_with_blockers`. La vérification explicite de `status == healthy` est donc obligatoire pour clôturer P0-006.

## Vérifications locales du runner

```powershell
npm run qa:capacity-runner
```

Les tests utilisent des réponses HTTPS simulées en mémoire, sans serveur HTTP ni bypass réseau. Ils couvrent le schéma v3, les empreintes, les origines, le préflight, les bloqueurs, les traversées de chemin, les substitutions de méthode, les en-têtes interdits, la sécurité staging/production, la cadence sans rattrapage, les corps bornés, les erreurs fermées, les collisions de fichiers et l’absence de fuite de secrets.
