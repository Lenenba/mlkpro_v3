# Dette Technique & Ameliorations - Malikia Pro

> Audit complet realise le 2026-04-02
> Base de code : Laravel 11 / Vue 3 / Inertia.js
> Etat : ~140 modeles, 70+ controleurs, 60+ services, 264 composants Vue

---

## Table des matieres

1. [Resume executif](#1-resume-executif)
2. [Dettes critiques](#2-dettes-critiques)
3. [Dettes elevees](#3-dettes-elevees)
4. [Dettes moyennes](#4-dettes-moyennes)
5. [Dettes faibles](#5-dettes-faibles)
6. [Ameliorations architecturales](#6-ameliorations-architecturales)
7. [Ameliorations performance](#7-ameliorations-performance)
8. [Ameliorations securite](#8-ameliorations-securite)
9. [Ameliorations frontend](#9-ameliorations-frontend)
10. [Tests et qualite](#10-tests-et-qualite)
11. [DevOps et observabilite](#11-devops-et-observabilite)
12. [Nouvelles fonctionnalites recommandees](#12-nouvelles-fonctionnalites-recommandees)
13. [Plan de remediation par phases](#13-plan-de-remediation-par-phases)

---

## 1. Resume executif

Malikia Pro est une plateforme SaaS metier ambitieuse et bien architecturee dans les grandes lignes.
La fondation est solide (Sanctum, feature flags, service layer, actions DDD), mais la croissance rapide
a genere des dettes structurelles qui pourraient bloquer la scalabilite.

**Score global de sante technique : 6.5/10**

| Categorie | Score |
|-----------|-------|
| Architecture | 7/10 |
| Securite | 6/10 |
| Performance | 5/10 |
| Qualite du code | 6/10 |
| Tests | 4/10 |
| Frontend | 6/10 |
| DevOps | 5/10 |

---

## 2. Dettes critiques

> A corriger immediatement, avant tout nouveau deploiement en production.

---

### DT-001 - Validation des webhooks non centralisee

**Fichier** : `routes/api.php` lignes 87-89
**Severite** : CRITIQUE
**Impact** : Securite - injection de faux evenements Stripe/SMS/email

**Probleme** :
Les routes webhook sont exposees sans middleware de validation de signature centralise.
Un attaquant peut forger des appels POST simulant un paiement Stripe reussi.

```php
// Etat actuel : routes exposees sans validation globale
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
Route::post('/webhooks/campaigns/sms', [SmsWebhookController::class, 'handle']);
Route::post('/webhooks/campaigns/email', [EmailWebhookController::class, 'handle']);
```

**Correction recommandee** :
```php
// Creer app/Http/Middleware/ValidateWebhookSignature.php
Route::middleware('webhook.signature:stripe')->post('/stripe/webhook', ...);
Route::middleware('webhook.signature:twilio')->post('/webhooks/campaigns/sms', ...);
Route::middleware('webhook.signature:mailgun')->post('/webhooks/campaigns/email', ...);
```

---

### DT-002 - Absence de gestion d'erreur sur les API externes

**Fichiers** : `app/Services/StripeSaleService.php`, `app/Services/TwilioCampaignService.php`, etc.
**Severite** : CRITIQUE
**Impact** : Crash silencieux si Stripe, Twilio, OpenAI sont indisponibles

**Probleme** :
Les appels aux API externes (Stripe, Twilio, Mailgun, OpenAI) ne sont pas proteges
par des try/catch. Une interruption de service externe fait planter l'app entiere.

```php
// Probleme : pas de protection sur les appels Stripe
$intent = $stripe->paymentIntents->create([...]);  // crash si Stripe down
```

**Correction recommandee** :
```php
// Wrapper avec circuit breaker
try {
    $intent = $stripe->paymentIntents->create([...]);
} catch (\Stripe\Exception\ApiConnectionException $e) {
    Log::error('Stripe indisponible', ['error' => $e->getMessage()]);
    return response()->json(['message' => 'Service de paiement temporairement indisponible'], 503);
} catch (\Stripe\Exception\InvalidRequestException $e) {
    Log::error('Stripe requete invalide', ['error' => $e->getMessage()]);
    return response()->json(['message' => 'Erreur de paiement'], 422);
}
```

---

### DT-003 - Soft Deletes manquants sur les entites metier critiques

**Fichiers** : `app/Models/Quote.php`, `app/Models/Invoice.php`, `app/Models/Customer.php`, etc.
**Severite** : CRITIQUE
**Impact** : Suppression permanente et irreversible des donnees metier

**Probleme** :
La migration `2025_12_22_150100_add_archived_at_to_quotes_table.php` ajoute un champ
`archived_at` sur les devis, mais le modele `Quote.php` n'utilise pas le trait `SoftDeletes`.
Sur 140 modeles, seuls 2 ont le soft delete (Campaign, DemoWorkspace).

**Modeles a corriger en priorite** :
- `Quote` - devis client (revenus directs)
- `Invoice` - factures (obligations legales de retention)
- `Work` - travaux/prestations
- `Customer` - clients (CRM)
- `Sale` - commandes
- `Task` - taches
- `Product` - catalogue
- `Payment` - paiements (audit financier)

**Correction** :
```php
// Dans chaque modele concerne
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use SoftDeletes;
    // ...
}
```
```php
// Dans les migrations correspondantes, verifier que deleted_at existe
$table->softDeletes(); // si pas encore present
```

---

## 3. Dettes elevees

> A traiter dans le prochain sprint, risque pour la stabilite.

---

### DT-004 - Modele User monolithique (God Object)

**Fichier** : `app/Models/User.php`
**Severite** : ELEVEE
**Impact** : Maintenabilite, lisibilite, risque de regression

**Probleme** :
Le modele `User` porte a la fois :
- L'identite de l'utilisateur (nom, email, mot de passe)
- Le profil de l'entreprise (company_name, company_logo, company_address...)
- La configuration abonnement (plan, billing_provider, stripe_id, paddle_id...)
- Les feature flags (`company_features[]`, `company_limits{}`)
- Les methodes de paiement (`payment_methods[]`)
- Les preferences marketing (`notification_settings{}`)
- La hierarchie des roles (isOwner, isAdmin, isEmployee, isClient...)

Avec 79 attributs `fillable`, ce modele devient impossible a tester unitairement
et chaque modification risque d'avoir des effets de bord.

**Refactorisation recommandee** :
```
User (identite pure)
├── Company (profil entreprise) → 1:1 avec User
├── Subscription (abonnement) → 1:1 avec User
├── CompanySettings (preferences) → 1:1 avec User
└── Role (role systeme) → via role_id existant
```

**Etapes** :
1. Creer le modele `Company` avec migration `companies` (colonnes company_*)
2. Relation `User hasOne Company`
3. Migrer les donnees existantes
4. Creer `CompanySettings` pour les JSON imbriques
5. Deprecier les colonnes depuis `users` progressivement

---

### DT-005 - Scoping multi-tenant incoherent (user_id vs account_id)

**Fichier** : `app/Models/User.php` lignes 146-189
**Severite** : ELEVEE
**Impact** : Risque de fuite de donnees entre tenants

**Probleme** :
Certains modeles utilisent `user_id` comme cle de tenant, d'autres `account_id`.
Il n'y a pas de convention claire ni de scope global.

```php
// app/Models/User.php - exemples d'incoherence
$this->hasMany(Product::class);          // implicite user_id
$this->hasMany(Reservation::class, 'account_id');  // explicite account_id
$this->hasMany(Campaign::class);         // implicite user_id
```

**Risque concret** : Un employe connecte pourrait potentiellement acceder
aux donnees d'un autre tenant si le filtre n'est pas applique au niveau controleur.

**Correction recommandee** :
```php
// 1. Choisir UNE convention : account_id (plus semantique pour multi-tenant)
// 2. Creer un GlobalScope sur tous les modeles tenant-scoped
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('account_id', auth()->id() ?? auth('sanctum')->id());
    }
}

// 3. Appliquer via trait HasTenantScope
trait HasTenantScope
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
```

---

### DT-006 - Controleurs trop larges (Fat Controllers)

**Fichiers** : `app/Http/Controllers/CustomerController.php` (890 lignes)
**Severite** : ELEVEE
**Impact** : Maintenabilite, testabilite

**Probleme** :
`CustomerController::index()` execute 15+ requetes SQL pour une seule vue :
```php
// lignes 65-96 : 7 requetes clonees pour les stats
$baseQuery->clone()->withCount(['quotes']);
$baseQuery->clone()->withCount(['works']);
$baseQuery->clone()->withCount(['invoices']);
$baseQuery->clone()->sum('total');
$baseQuery->clone()->whereHas('quotes', ...);
// etc.
```

`CustomerController::store()` fait tout en une methode :
- Cree le client
- Cree l'utilisateur portail
- Cree les proprietes (adresses)
- Envoie un email de bienvenue
- Dispatch une notification

**Refactorisation** :
```
app/
├── Actions/
│   └── Customers/
│       ├── CreateCustomerAction.php    // creation pure
│       ├── AttachPortalUserAction.php  // creation compte portail
│       └── BuildCustomerStatsQuery.php // agregats pour index
├── Services/
│   └── CustomerService.php            // orchestration
```

---

### DT-007 - Transactions DB insuffisantes

**Fichiers** : Multiples controleurs et services
**Severite** : ELEVEE
**Impact** : Donnees incoherentes en cas d'erreur partielle

**Probleme** :
Seulement ~9 controleurs utilisent `DB::transaction()`. Les operations multi-etapes
(creer un devis + ses lignes + ses taxes + notifier) ne sont pas atomiques.

**Exemples sans transaction** :
- Creation d'une facture avec ses lignes
- Conversion devis → travail → facture
- Enregistrement d'un paiement + mise a jour solde + notification

**Correction** :
```php
// Tout operations multi-etapes doivent etre wrappees
DB::transaction(function () use ($data) {
    $quote = Quote::create($data['quote']);
    $quote->products()->createMany($data['products']);
    $quote->taxes()->createMany($data['taxes']);
    // Si une etape echoue, tout est annule
});
```

---

### DT-008 - Problemes N+1 sur les pages de liste

**Fichier** : `app/Http/Controllers/CustomerController.php` lignes 65-117
**Severite** : ELEVEE
**Impact** : Performance - temps de reponse degrade lineairement avec le volume

**Probleme concret** :
```php
// 15+ requetes SQL pour charger la page clients
$customers = Customer::query()
    ->withCount(['quotes'])     // requete 1
    ->withCount(['works'])      // requete 2
    ->withCount(['invoices'])   // requete 3
    ->get()
    ->each(function($c) {
        $c->total_paid;         // requete N pour chaque client !
    });
```

**Correction** :
```php
// Une seule requete avec sous-requetes aggregees
$customers = Customer::query()
    ->withCount(['quotes', 'works', 'invoices'])
    ->withSum('payments', 'amount')
    ->with(['properties:id,customer_id,city'])
    ->paginate(20);
```

---

### DT-009 - Rate limiting absent sur les endpoints couteux

**Fichiers** : `routes/api.php`
**Severite** : ELEVEE
**Impact** : Securite, couts API (OpenAI, Twilio, Stripe)

**Endpoints vulnerables** :
- `POST /api/plan-scan` - Appel OpenAI Vision (0.50$/image)
- `POST /api/campaigns/{campaign}/estimate` - Estimation IA
- `POST /api/product/import/csv` - Import batch lourd
- `POST /api/marketing/segments/preview-count` - Requete full-scan
- `POST /api/auth/login` - Attaque brute force

**Correction** :
```php
// Dans routes/api.php ou RouteServiceProvider
Route::middleware(['auth:sanctum', 'throttle:plan-scan'])->group(function () {
    Route::post('/plan-scan', ...);
});

// Dans AppServiceProvider
RateLimiter::for('plan-scan', function (Request $request) {
    return Limit::perHour(10)->by($request->user()->id);
});
```

---

### DT-010 - Variables d'environnement non validees au demarrage

**Fichier** : `app/Providers/AppServiceProvider.php`
**Severite** : ELEVEE
**Impact** : Echecs silencieux en production si une cle API manque

**Probleme** :
Si `STRIPE_SECRET` ou `OPENAI_API_KEY` ne sont pas configures, l'app demarre
normalement mais crash uniquement lors du premier appel. Difficile a diagnostiquer.

**Correction** :
```php
// app/Providers/AppServiceProvider.php - methode boot()
public function boot(): void
{
    if ($this->app->isProduction()) {
        $required = [
            'STRIPE_SECRET' => config('services.stripe.secret'),
            'MAILGUN_SECRET' => config('services.mailgun.secret'),
            'TWILIO_SID'    => config('services.twilio.sid'),
        ];

        foreach ($required as $name => $value) {
            if (empty($value)) {
                throw new \RuntimeException("Variable d'environnement manquante : {$name}");
            }
        }
    }
}
```

---

## 4. Dettes moyennes

> A planifier dans le roadmap technique, impact progressif.

---

### DT-011 - Colonnes JSON a normaliser

**Fichier** : `app/Models/User.php` et migrations
**Severite** : MOYENNE

**Colonnes JSON problematiques** :
```
company_features         → devrait etre une table feature_subscriptions
company_limits           → devrait etre une table company_limits
payment_methods          → devrait etre une table payment_methods
company_supplier_preferences → table supplier_preferences
notification_settings    → table notification_preferences
```

**Probleme** : Impossible de faire des requetes type `WHERE JSON_CONTAINS(company_features, 'reservations')`.
En SQL, ces colonnes sont des boites noires non indexables.

**Migration progressive** :
```php
// 1. Creer les tables normalisees
// 2. Dupliquer les donnees JSON vers les nouvelles tables
// 3. Garder la colonne JSON en lecture seule le temps de la transition
// 4. Supprimer les colonnes JSON apres validation
```

---

### DT-012 - Pas de versioning des API

**Fichier** : `routes/api.php`
**Severite** : MOYENNE

**Probleme** : Toutes les routes sont sous `/api/*` sans version.
Un breaking change casse tous les clients (app mobile, integrateurs tiers).

**Correction** :
```php
// routes/api.php
Route::prefix('v1')->group(base_path('routes/api/v1.php'));
// A terme : Route::prefix('v2')->group(base_path('routes/api/v2.php'));
```

---

### DT-013 - Service SalePaymentService trop large (SRP viole)

**Fichier** : `app/Services/SalePaymentService.php` (387 lignes)
**Severite** : MOYENNE

**Responsabilites actuelles** (violations Single Responsibility) :
1. Enregistrement des paiements
2. Gestion de l'inventaire
3. Gestion des reservations
4. Transitions de statut de vente
5. Envoi de notifications

**Decomposition proposee** :
```
app/Services/
├── Payments/
│   ├── PaymentRecordingService.php     // enregistrement uniquement
│   └── PaymentNotificationService.php  // notifications paiement
├── Inventory/
│   └── InventoryAllocationService.php  // stock apres vente
├── Sales/
│   └── SaleStatusTransitionService.php // machine d'etat vente
└── Reservations/
    └── ReservationFulfillmentService.php // reservations liees aux ventes
```

---

### DT-014 - Contraintes de base de donnees manquantes

**Fichiers** : Migrations diverses
**Severite** : MOYENNE

**Problemes identifies** :
- Champs `status` (devis, travail, vente) stockes en VARCHAR sans contrainte CHECK
- Champs `role` non contraints aux valeurs valides
- Pas de contrainte d'unicite sur `(user_id, email)` pour les clients
- Factures pouvant exister sans client reference

**Corrections** :
```php
// Dans les migrations, ajouter des contraintes CHECK (MySQL 8+)
$table->enum('status', ['draft', 'sent', 'accepted', 'declined', 'expired']);
// Ou via contrainte CHECK explicite
DB::statement("ALTER TABLE quotes ADD CONSTRAINT chk_status CHECK (status IN ('draft','sent','accepted','declined','expired'))");

// Unicite client par tenant
$table->unique(['account_id', 'email']);
```

---

### DT-015 - Doublons de routes (portail vs staff)

**Fichier** : `routes/api.php` lignes 139-148 vs 241-251
**Severite** : MOYENNE

**Probleme** : Les routes `reservations`, `notifications`, `quotes` sont dupliquees
pour le portail client et pour le staff avec une logique similaire.

**Avant** :
```php
// Portail
Route::get('/portal/reservations', ...);
// Staff
Route::get('/reservations', ...);
```

**Apres** :
```php
// Route unique avec middleware qui adapte le scope
Route::get('/reservations', [ReservationController::class, 'index'])
    ->middleware('scope.by.role'); // filtre selon client ou staff
```

---

### DT-016 - Gestion des jobs sans monitoring

**Fichiers** : `app/Jobs/`
**Severite** : MOYENNE

**Jobs identifies sans monitoring** :
- `DispatchCampaignRunJob` - Lance des campagnes (critique)
- `SendCampaignRecipientJob` - Envoie emails/SMS (facturation Twilio)
- `AnalyzePlanScanJob` - Appel OpenAI (couteux)
- `ComputeInterestScoresJob` - Calcul lourd
- `ReconcileDeliveryReportsJob` - Reconciliation financiere

**Problemes** :
- Pas de dead letter queue
- Pas d'alerte sur echec
- Pas de timeout documenté
- Idempotence non verifiee

**Ameliorations** :
```php
class DispatchCampaignRunJob implements ShouldQueue
{
    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 60;

    public function failed(\Throwable $e): void
    {
        Log::error('CampaignRunJob failed', [
            'campaign_id' => $this->campaign->id,
            'error' => $e->getMessage(),
        ]);
        // Notifier l'owner de la campagne
        $this->campaign->user->notify(new CampaignJobFailedNotification($this->campaign));
    }
}
```

---

### DT-017 - Contraintes de versions de dependances floues

**Fichier** : `composer.json`
**Severite** : MOYENNE

**Problemes** :
```json
"twilio/sdk": "*"          // DANGER : accepte toute version, meme breaking
"cashier-paddle": "2.6"    // Pin exact, ne recoit pas les patches securite
```

**Securite ignoree** :
```json
"audit": {
    "ignore": [
        "PKSA-8qx3-n5y5-vvnd",
        "PKSA-rqc2-tcc6-nc79",
        "PKSA-365x-2zjk-pt47"
    ]
}
```
**Ces 3 vulnerabilites ignorees doivent etre documentees et planifiees en remediation.**

**Corrections** :
```json
"twilio/sdk": "^8.0",
"laravel/cashier-paddle": "^2.6"
```

---

### DT-018 - Frontend : gestion d'erreur incoherente

**Fichiers** : `resources/js/Pages/` (264 composants)
**Severite** : MOYENNE

**Probleme** : Certaines pages utilisent un toast d'erreur, d'autres une modale,
d'autres ignorent silencieusement les erreurs API. Pas de standard.

**Standard propose** :
```js
// resources/js/composables/useApi.js
export function useApi() {
    const isLoading = ref(false);
    const error = ref(null);

    async function call(fn) {
        isLoading.value = true;
        error.value = null;
        try {
            return await fn();
        } catch (e) {
            error.value = e.response?.data?.message ?? 'Une erreur est survenue';
            toast.error(error.value);
            // Log vers backend si severite elevee
        } finally {
            isLoading.value = false;
        }
    }

    return { isLoading, error, call };
}
```

---

## 5. Dettes faibles

> Ameliorations de confort, a faire progressivement.

---

### DT-019 - Nommage incoherent dans les routes

**Exemples** :
- `portal/orders` vs `sales` (meme entite, deux noms)
- `requests` vs `leads` (selon l'endroit)
- `quote/{quote}/send-email` vs `quotes/{quote}/send-email`

**Action** : Creer un guide de nommage des routes et corriger au fur et a mesure.

---

### DT-020 - Bibliotheques calendrier en doublon

**Fichier** : `package.json`
**Probleme** : `@fullcalendar/vue3` ET `vue-full-calendar` tous les deux installes.
**Action** : Supprimer `vue-full-calendar`, conserver `@fullcalendar/vue3`.

---

### DT-021 - Pas d'ESLint ni TypeScript sur le frontend

**Impact** : Bugs de type silencieux, pas de coherence de style
**Action** :
```bash
npm install -D eslint @vue/eslint-config-prettier typescript vue-tsc
```

---

### DT-022 - Valeurs magiques dans le code

**Exemples** :
- `config/services.php:84` → modele OpenAI hardcode `'gpt-4o-mini'`
- `config/services.php:58` → `connect_fee_percent` vaut `1.5` en dur
- Pagination hardcodee a `10` ou `12` selon les controleurs

**Action** : Centraliser dans `config/app.php` ou variables `.env`.

---

### DT-023 - Migrations sans down() correctes

**Impact** : Rollback impossible en production
**Action** : Auditer les migrations post-2025 et ajouter les down() manquants.

---

## 6. Ameliorations architecturales

---

### ARCH-001 - Introduire l'architecture evenementielle pour les cascades

**Objectif** : Decoupler les effets de bord (paiement recu → mettre a jour statut → notifier → mettre a jour inventaire)

**Proposition** :
```php
// Emettre un evenement
event(new PaymentReceived($payment));

// Listeners independants
class UpdateSaleStatusOnPayment implements ShouldQueue { ... }
class AllocateInventoryOnPayment implements ShouldQueue { ... }
class NotifyCustomerOnPayment implements ShouldQueue { ... }
class UpdateLoyaltyPointsOnPayment implements ShouldQueue { ... }
```

**Benefices** : Chaque effet de bord est testable independamment, les echecs
partiels ne bloquent pas les autres, on peut ajouter des effets sans toucher
au service de paiement.

---

### ARCH-002 - Creer un module Company distinct du User

**Voir DT-004** - Decomposer le modele User en :
- `User` : identite, email, mot de passe, role
- `Company` : nom, logo, adresse, SIRET, settings
- `Subscription` : plan, provider, statut, limites
- `CompanyFeature` : table pivot pour les feature flags

---

### ARCH-003 - Standardiser les Query Objects

**Objectif** : Centraliser les requetes complexes et les rendre reutilisables

**Proposition** :
```php
// app/Queries/CustomerIndexQuery.php
class CustomerIndexQuery
{
    public function build(User $account, array $filters = []): Builder
    {
        return Customer::query()
            ->forAccount($account)
            ->withCount(['quotes', 'works', 'invoices'])
            ->withSum('payments', 'amount')
            ->when($filters['search'] ?? null, fn($q, $s) => $q->search($s))
            ->when($filters['tag'] ?? null, fn($q, $t) => $q->whereJsonContains('tags', $t))
            ->latest();
    }
}
```

---

### ARCH-004 - Implémenter un systeme de feature flags en base

**Remplacement de** : `company_features` JSON dans `users`

**Nouveau schema** :
```sql
CREATE TABLE plan_features (
    id BIGINT PRIMARY KEY,
    plan_id VARCHAR(50),      -- 'starter', 'pro', 'enterprise'
    feature_key VARCHAR(100), -- 'campaigns', 'reservations', 'ai_assistant'
    is_active BOOLEAN DEFAULT true
);

CREATE TABLE company_feature_overrides (
    id BIGINT PRIMARY KEY,
    account_id BIGINT FK users,
    feature_key VARCHAR(100),
    is_enabled BOOLEAN,
    expires_at TIMESTAMP NULL
);
```

**Benefices** : Requetable, loggable, avec expiration automatique, sans migration pour activer un feature.

---

### ARCH-005 - Circuit Breaker pour les services externes

**Objectif** : Empecher une API externe down de rendre toute l'app indisponible

```php
// app/Support/CircuitBreaker.php
class CircuitBreaker
{
    public function call(string $service, callable $fn, callable $fallback): mixed
    {
        if ($this->isOpen($service)) {
            return $fallback();
        }
        try {
            $result = $fn();
            $this->recordSuccess($service);
            return $result;
        } catch (\Exception $e) {
            $this->recordFailure($service);
            if ($this->shouldOpen($service)) {
                $this->open($service);
            }
            return $fallback();
        }
    }
}

// Utilisation
$circuitBreaker->call('stripe', fn() => $stripe->charges->create([...]), fn() => throw new ServiceUnavailableException('Paiement temporairement indisponible'));
```

---

## 7. Ameliorations performance

---

### PERF-001 - Migrer le cache vers Redis

**Fichier** : `config/cache.php`
**Etat actuel** : Cache base de donnees (driver `database`)
**Probleme** : Le cache SQL est moins performant que Redis, et surcharge la DB.

**Actions** :
```bash
# Installation
composer require predis/predis
# .env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis  # bonus : meilleure gestion des jobs
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

**Impact attendu** : Reduction de 40-60% des requetes DB sur les pages chargees.

---

### PERF-002 - Mettre en cache les dashboards et statistiques

**Fichier** : `app/Http/Controllers/DashboardController.php`
**Probleme** : Le dashboard recalcule tout a chaque chargement.

```php
// Cacher les stats 5 minutes par utilisateur
$stats = Cache::remember("dashboard.{$user->id}", 300, function () use ($user) {
    return $this->dashboardService->computeStats($user);
});
```

---

### PERF-003 - Pagination obligatoire sur tous les endpoints de liste

**Probleme** : Certains endpoints peuvent retourner des milliers d'enregistrements.

**Standard a appliquer** :
```php
// Toujours paginer, jamais de ->get() sans limit sur les listes publiques
->paginate(request('per_page', 20))
// Maximum autorise
->paginate(min(request('per_page', 20), 100))
```

---

### PERF-004 - Index composites manquants

**Indexes critiques a ajouter** :

```php
// Migration a creer : add_critical_composite_indexes
Schema::table('quotes', function (Blueprint $table) {
    $table->index(['account_id', 'status', 'created_at'], 'quotes_tenant_status_date');
});
Schema::table('works', function (Blueprint $table) {
    $table->index(['account_id', 'scheduled_at', 'status'], 'works_tenant_schedule_status');
});
Schema::table('invoices', function (Blueprint $table) {
    $table->index(['account_id', 'status', 'due_date'], 'invoices_tenant_status_due');
});
Schema::table('campaign_prospects', function (Blueprint $table) {
    $table->index(['campaign_id', 'status'], 'prospects_campaign_status');
});
Schema::table('payments', function (Blueprint $table) {
    $table->index(['account_id', 'created_at'], 'payments_tenant_date');
});
```

---

### PERF-005 - Lazy loading detecteur en tests

**Ajout dans** `AppServiceProvider::boot()` pour l'environnement de test/dev :
```php
if ($this->app->isLocal() || $this->app->runningUnitTests()) {
    Model::preventLazyLoading();
}
```
Cela leve une exception a chaque N+1 non intentionnel pendant le developpement.

---

## 8. Ameliorations securite

---

### SEC-001 - Audit des vulnerabilites ignorees dans composer.json

**Action immediate** :
```bash
composer audit
```
Investiguer et documenter pourquoi `PKSA-8qx3-n5y5-vvnd`, `PKSA-rqc2-tcc6-nc79`,
`PKSA-365x-2zjk-pt47` sont ignores. Si les CVE sont faibles, ajouter un commentaire
explicatif. Si elles sont moyennes/elevees, planifier la remediation.

---

### SEC-002 - Politique de rotation des tokens Sanctum

**Probleme** : Les tokens API Sanctum n'expirent pas par defaut.
Si un token est compromise, il reste valide indefiniment.

**Correction** :
```php
// config/sanctum.php
'expiration' => 60 * 24 * 30, // 30 jours
'token_prefix' => 'mlk_',     // prefix distinctif pour les tokens

// Ajouter dans AppServiceProvider
Sanctum::pruneExpiredTokens();
```

---

### SEC-003 - Headers de securite HTTP

**Fichier** : `app/Http/Middleware/SecurityHeaders.php` (si existant)
**Verifier la presence de** :
```
Content-Security-Policy
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Strict-Transport-Security: max-age=31536000
Referrer-Policy: strict-origin-when-cross-origin
```

---

### SEC-004 - Protection CSRF sur les webhooks

**Attention** : Les routes webhook DOIVENT etre exclues du CSRF middleware
(elles le sont probablement), mais verifier que c'est fait explicitement et pas
en laissant le middleware desactive globalement.

---

### SEC-005 - Logs d'acces sensibles

**Ajouter des logs sur** :
- Connexions 2FA
- Changements de mot de passe
- Exports de donnees clients
- Acces au panneau superadmin
- Modifications des methodes de paiement

```php
// Dans les controlleurs concernes
activity('security')
    ->causedBy($user)
    ->withProperties(['ip' => request()->ip(), 'action' => 'password_changed'])
    ->log('Mot de passe modifie');
```

---

## 9. Ameliorations frontend

---

### FRONT-001 - Composable useApi() standardise

**Voir DT-018** - Creer un composable unique pour tous les appels API :
- Gestion du loading state
- Gestion des erreurs standardisee
- Retry automatique sur 503
- Logging des erreurs cote client

---

### FRONT-002 - TypeScript progressif

**Etapes** :
1. Ajouter `tsconfig.json` avec `strict: false` (migration douce)
2. Convertir les composables en `.ts`
3. Typer les props des composants critiques
4. Progressivement activer `strict: true`

**Benefice** : Moins de bugs de type en runtime, meilleure autocompletion IDE.

---

### FRONT-003 - Skeleton screens sur les pages de liste

**Remplacement des spinners** par des squelettes de contenu :
```vue
<!-- Avant -->
<div v-if="loading" class="spinner"></div>
<div v-else>...</div>

<!-- Apres : perception de vitesse meilleure -->
<template v-if="loading">
    <div v-for="i in 5" :key="i" class="skeleton-row animate-pulse">
        <div class="h-4 bg-gray-200 rounded w-3/4"></div>
    </div>
</template>
<template v-else>...</template>
```

---

### FRONT-004 - Optimistic UI sur les actions frequentes

**Actions candidates** :
- Cocher/decocher une tache
- Changer le statut d'un travail
- Ajouter un tag client

```js
// Mise a jour immediate de l'UI, annulation si l'API echoue
async function toggleTask(task) {
    task.status = task.status === 'done' ? 'todo' : 'done'; // optimiste
    try {
        await api.patch(`/tasks/${task.id}`, { status: task.status });
    } catch {
        task.status = task.status === 'done' ? 'todo' : 'done'; // rollback
        toast.error('Erreur lors de la mise a jour');
    }
}
```

---

### FRONT-005 - PWA / Application installable

**Proposition** : Rendre l'app installable sur mobile (sans app store)

**Benefice pour les employes terrain** : Acces rapide depuis l'ecran d'accueil,
mode offline basique pour la visualisation des travaux du jour.

```bash
npm install -D vite-plugin-pwa
```

---

## 10. Tests et qualite

---

### TEST-001 - Configurer la couverture de code

**Fichier** : `phpunit.xml`
```xml
<coverage>
    <include>
        <directory suffix=".php">./app</directory>
    </include>
    <report>
        <html outputDirectory="build/coverage"/>
        <text outputFile="build/coverage.txt"/>
    </report>
</coverage>
```

**Objectif** : 80% sur les services, 60% sur les controleurs.

---

### TEST-002 - Tests des chemins d'erreur

**Manquants identifies** :
- Que se passe-t-il si Stripe retourne une erreur lors d'un paiement ?
- Que se passe-t-il si Twilio est down lors de l'envoi d'une campagne SMS ?
- Que se passe-t-il si la queue est saturee ?
- Un employe peut-il acceder aux donnees d'un autre tenant ?

```php
// Exemple test de securite multi-tenant
it('prevents employee from accessing other tenant data', function () {
    $owner1 = User::factory()->create();
    $owner2 = User::factory()->create();
    $customer = Customer::factory()->for($owner2, 'account')->create();

    actingAs($owner1)->getJson("/api/customers/{$customer->id}")
        ->assertStatus(403);
});
```

---

### TEST-003 - Tests contre une vraie base MySQL

**Fichier** : `phpunit.xml` - Ajouter un profil CI
```xml
<!-- phpunit.mysql.xml -->
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="mlkpro_test"/>
```

**Pourquoi** : SQLite ne valide pas les check constraints, les JSON queries
MySQL-specifiques, ou les transactions InnoDB. Les bugs de migration ne seront
pas detectes en SQLite.

---

### TEST-004 - Tests d'integration pour les webhooks

```php
it('handles stripe payment_intent.succeeded webhook', function () {
    $payload = StripeWebhookFactory::paymentIntentSucceeded($invoice);
    $signature = Stripe\WebhookSignature::generate($payload, config('services.stripe.webhook_secret'));

    postJson('/api/stripe/webhook', $payload, ['Stripe-Signature' => $signature])
        ->assertOk();

    expect($invoice->fresh()->status)->toBe('paid');
});
```

---

### TEST-005 - Tests de performance (regression)

```php
// Detecter les regressions N+1 automatiquement
it('loads customer index with acceptable query count', function () {
    Customer::factory()->count(20)->create();

    $queryCount = 0;
    DB::listen(fn() => $queryCount++);

    getJson('/api/customers')->assertOk();

    expect($queryCount)->toBeLessThan(10); // pas plus de 10 requetes
});
```

---

## 11. DevOps et observabilite

---

### OPS-001 - Monitoring des erreurs (Sentry)

**Actuellement** : Logs fichiers uniquement
**Proposition** :
```bash
composer require sentry/sentry-laravel
```
```env
SENTRY_LARAVEL_DSN=https://...@sentry.io/...
```

Benefices : Alertes en temps reel, groupement des erreurs, suivi des performances.

---

### OPS-002 - Health Check endpoint

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getPdo() ? 'ok' : 'error',
        'cache' => Cache::store()->has('health_check') || Cache::store()->put('health_check', true, 60) ? 'ok' : 'error',
        'queue' => Queue::size() < 1000 ? 'ok' : 'warning',
        'timestamp' => now()->toIso8601String(),
    ]);
});
```

---

### OPS-003 - Dashboard Horizon pour les queues

```bash
composer require laravel/horizon
php artisan horizon:install
```

Benefices : Visualiser les jobs en temps reel, configurer les workers par type de job,
alerter si une queue depasse un seuil.

---

### OPS-004 - Pipeline CI/CD

**Fichier a creer** : `.github/workflows/ci.yml`
```yaml
jobs:
  test:
    steps:
      - run: composer install
      - run: php artisan migrate --env=testing
      - run: php artisan test --coverage --min=60
      - run: ./vendor/bin/pint --test
      - run: ./vendor/bin/phpstan analyse
  
  deploy:
    needs: test
    if: github.ref == 'refs/heads/main'
    steps:
      - run: php artisan down
      - run: php artisan migrate --force
      - run: php artisan up
```

---

### OPS-005 - Backup automatique de la base de donnees

```bash
composer require spatie/laravel-backup
```
```php
// config/backup.php - backup quotidien vers S3
'destination' => ['disks' => ['s3']],
'schedule' => '0 2 * * *', // 2h du matin
'retention' => '30 days',
```

---

## 12. Nouvelles fonctionnalites recommandees

---

### FEAT-001 - Onboarding par magic link (deja dans amelioration.md)

Lors de la creation d'un membre d'equipe ou d'un client portail, envoyer
un email avec un lien magique pour que la personne cree son mot de passe.
**Priorite haute** - deja identifie dans `docs/amelioration.md`.

---

### FEAT-002 - Audit trail ameliore

**Actuellement** : `activity_logs` basique
**Proposition** : Intégrer `spatie/laravel-activitylog` pour logguer automatiquement
tous les CRUD sur les entites metier, avec diff avant/apres.

```php
// Configuration automatique sur les modeles cles
class Quote extends Model
{
    use LogsActivity;
    protected static $logAttributes = ['status', 'total', 'notes'];
    protected static $logOnlyDirty = true;
}
```

---

### FEAT-003 - API Webhooks sortants (pour intégrations)

Permettre aux clients Pro de recevoir des webhooks sur leurs propres endpoints
(integration avec leurs ERP, Zapier, Make, etc.).

```
Evenements : quote.accepted, invoice.paid, work.completed, customer.created
```

---

### FEAT-004 - Export/Import de donnees

- Export CSV/Excel des clients, factures, devis
- Import de clients depuis CSV (avec validation)
- Export PDF des rapports de performance

---

### FEAT-005 - Multi-currency par devis/facture

**Actuellement** : Une devise par compte
**Proposition** : Permettre de selectionner la devise a la creation d'un devis
pour les clients internationaux, avec taux de change configurable.

---

### FEAT-006 - Application mobile (PWA ou React Native)

**Pour les employes terrain** :
- Voir les travaux du jour
- Marquer un travail comme termine
- Pointer les presences
- Scanner un code QR client

---

### FEAT-007 - Notifications push ameliorees

**Actuellement** : Push tokens stockes, envoi basique
**Proposition** :
- Preferences par type de notification
- Regroupement des notifications (batch digest)
- Notification de relance automatique (devis non repondu apres X jours)

---

## 13. Plan de remediation par phases

---

### Phase 1 - Securite et stabilite (Semaine 1-2)
**Must do avant toute mise en production**

- [ ] DT-001 : Centraliser la validation des signatures webhook
- [ ] DT-002 : Try/catch sur tous les appels API externes (Stripe, Twilio, OpenAI)
- [ ] DT-003 : Ajouter SoftDeletes sur Quote, Invoice, Customer, Work, Payment
- [ ] DT-010 : Valider les variables d'environnement au demarrage
- [ ] SEC-001 : Auditer et documenter les vulnerabilites ignorees dans composer.json
- [ ] SEC-002 : Configurer l'expiration des tokens Sanctum
- [ ] DT-009 : Rate limiting sur plan-scan, import CSV, login

---

### Phase 2 - Integrite des donnees (Semaine 3-4)

- [ ] DT-005 : Standardiser le scoping multi-tenant (audit + migration vers account_id)
- [ ] DT-007 : Auditer et wrapper les operations multi-etapes en transactions
- [ ] DT-014 : Ajouter contraintes CHECK sur les champs status/enum
- [ ] PERF-004 : Ajouter les indexes composites manquants
- [ ] PERF-005 : Activer Model::preventLazyLoading() en local/test

---

### Phase 3 - Performance (Semaine 5-6)

- [ ] PERF-001 : Migrer vers Redis (cache + sessions + queue)
- [ ] DT-008 : Corriger les N+1 sur CustomerController et QuoteController
- [ ] PERF-002 : Cache des statistiques dashboard
- [ ] PERF-003 : Pagination stricte sur tous les endpoints de liste
- [ ] OPS-001 : Integrer Sentry pour le monitoring des erreurs
- [ ] OPS-003 : Installer Laravel Horizon

---

### Phase 4 - Qualite du code (Semaine 7-8)

- [ ] DT-006 : Refactoriser CustomerController (extraire actions et services)
- [ ] DT-013 : Decomposer SalePaymentService
- [ ] DT-015 : Consolider les routes dupliquees portail/staff
- [ ] DT-018 : Creer le composable useApi() standardise
- [ ] FRONT-001 : Skeleton screens sur les listes
- [ ] DT-021 : Configurer ESLint + TypeScript basique

---

### Phase 5 - Tests et CI (Semaine 9-10)

- [ ] TEST-001 : Configurer la couverture de code dans phpunit.xml
- [ ] TEST-002 : Ecrire les tests des chemins d'erreur
- [ ] TEST-003 : Tests contre MySQL en CI
- [ ] TEST-004 : Tests d'integration pour les webhooks Stripe
- [ ] TEST-005 : Tests de regression de performance (detection N+1)
- [ ] OPS-004 : Pipeline CI/CD GitHub Actions

---

### Phase 6 - Architecture long terme (Mois 3+)

- [ ] ARCH-001 : Architecture evenementielle pour les cascades
- [ ] ARCH-002 : Separer Company du modele User
- [ ] ARCH-004 : Feature flags en base de donnees
- [ ] DT-011 : Normaliser les colonnes JSON (company_features, etc.)
- [ ] DT-012 : Versioning de l'API (/api/v1/)
- [ ] ARCH-005 : Circuit Breaker pour les services externes
- [ ] OPS-005 : Backup automatique configure

---

### Phase 7 - Nouvelles fonctionnalites (Mois 4+)

- [ ] FEAT-001 : Magic link onboarding (haute priorite)
- [ ] FEAT-002 : Audit trail ameliore avec spatie/activitylog
- [ ] FEAT-003 : Webhooks sortants pour les integrateurs
- [ ] FEAT-004 : Export CSV/Excel des donnees
- [ ] FEAT-005 : PWA pour les employes terrain
- [ ] FEAT-006 : Notifications push intelligentes

---

## Recapitulatif des dettes par fichier

| Fichier | Dette(s) | Severite |
|---------|----------|----------|
| `app/Models/User.php` | DT-004, DT-005 | Elevee |
| `app/Http/Controllers/CustomerController.php` | DT-006, DT-008 | Elevee |
| `app/Services/SalePaymentService.php` | DT-013 | Moyenne |
| `routes/api.php` | DT-001, DT-009, DT-012, DT-015 | Critique/Elevee |
| `app/Services/StripeSaleService.php` | DT-002 | Critique |
| `database/migrations/` | DT-003, DT-014, PERF-004 | Critique/Moyenne |
| `composer.json` | DT-017, SEC-001 | Moyenne |
| `phpunit.xml` | TEST-001 | Moyenne |
| `config/services.php` | DT-022 | Faible |
| `resources/js/Pages/` | DT-018, FRONT-001 | Moyenne |
| `app/Jobs/` | DT-016 | Moyenne |
| `config/cache.php` | PERF-001 | Moyenne |

---

*Document genere le 2026-04-02 - A mettre a jour apres chaque sprint*
