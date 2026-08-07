# Runbook d'audit global - visibilite et coherence des modules

## Objectif

Ce guide sert a verifier, module par module, qu'une fonctionnalite desactivee ne laisse aucune fuite dans la plateforme.

Une fuite peut etre :

- un lien, un bouton, un onglet ou un sous-menu vide ;
- un compteur, une statistique, un filtre ou une colonne devenue hors contexte ;
- un terme metier inadapte, par exemple `chantier` dans un parcours salon ;
- une route ou une mutation encore accessible directement ;
- une donnee chargee ou exposee alors que son module est desactive ;
- un comportement different entre owner, membre d'equipe et client ;
- un preset de demonstration ou un seeder qui reactive le module ;
- une action visible mais sans parcours fonctionnel derriere.

Le runbook est durable. Les resultats d'un audit date doivent etre conserves dans :

`docs/audits/module-visibility/YYYY-MM-DD-<scope>.md`

## Invariants obligatoires

Pour chaque module :

1. Si le module est actif, son parcours normal reste fonctionnel.
2. Si le module est inactif, aucun element qui lui appartient ne doit etre visible ou actionnable, sauf exception archivale, legale ou contractuelle explicitement documentee, limitee a la lecture seule et testee.
3. Une URL, une requete API ou un payload forge ne doit pas contourner la desactivation.
4. Les filtres, tris, statistiques, compteurs et relations du module ne doivent pas influencer un ecran ou ils sont caches.
5. Les menus, cartes, panneaux et etats vides ne doivent pas rester affiches sans action ou contenu pertinent.
6. Le owner, l'admin, l'employe et le client doivent utiliser les fonctionnalites effectives du meme compte.
7. Les interfaces Web, portail client, API/mobile, emails, PDF et exports doivent etre coherents.
8. Les demos, seeds, templates et resets doivent produire les memes fonctionnalites effectives que l'application.
9. Un module absent du payload frontend est considere desactive. L'interface doit echouer de facon fermee.

## Quand executer cet audit

Executer au minimum l'audit cible lorsqu'un changement touche :

- un plan, un abonnement ou une limite ;
- `company_features`, les defaults de secteur ou les dependances de modules ;
- l'onboarding ou le changement de type d'entreprise ;
- un menu, un dashboard, un DataTable, une fiche ou un formulaire partage ;
- les permissions owner/admin/employe/client ;
- une route, un controller, une API ou une tache asynchrone ;
- un workspace de demonstration, un template ou un seeder ;
- une traduction ou un vocabulaire metier transversal ;
- un nouveau module ou une nouvelle integration entre modules.

Faire un audit global des 21 modules avant une livraison majeure ou apres une refonte des plans.

## Sources de verite

| Sujet | Source principale | Regle |
| --- | --- | --- |
| Resolution serveur | `app/Services/CompanyFeatureService.php` | Toujours utiliser la fonctionnalite effective du proprietaire du compte. |
| Contexte owner charge manuellement | `app/Support/Database/UserSelects.php` | Utiliser `companyFeatureContext()` si les fonctionnalites doivent etre resolues. |
| Payload Inertia partage | `app/Http/Middleware/HandleInertiaRequests.php` | `auth.account.features` contient les fonctionnalites actives du compte. |
| Lecture frontend | `resources/js/Composables/useAccountFeatures.js` | Utiliser `hasFeature()` et `visibleFeaturePayload()`. |
| Comportement fail-closed | `resources/js/utils/features.js` | Une cle absente ou un objet invalide vaut `false`. |
| Plans administrables | `app/Services/SuperAdminPlatformSettingsService.php` | La liste doit rester alignee avec le registre principal. |
| Demos | `app/Services/Demo/DemoWorkspaceCatalog.php` | Les modules selectionnes deviennent des overrides explicites. |
| Provisionnement demo | `app/Services/Demo/DemoWorkspaceProvisioner.php` | Les donnees generees doivent fonctionner sans modules exclus. |

### Ordre de resolution

L'ordre logique actuel est :

1. defaults du plan ;
2. defaults du secteur ;
3. overrides explicites du compte ;
4. restrictions particulieres du plan ;
5. dependances entre modules.

Ne pas deduire la visibilite uniquement depuis `company_type`, `company_sector` ou `company_features`. Le resultat de `CompanyFeatureService` est la source de verite.

Attention aux membres d'equipe et aux clients : si le proprietaire est recharge avec un `select(...)` partiel, ce select doit contenir le contrat `UserSelects::companyFeatureContext()`.

Regles metier actuellement encodees a conserver dans les tests :

- salon et restaurant activent `reservations` par defaut ;
- salon et restaurant desactivent par defaut `requests`, `products`, `quotes`, `plan_scans`, `jobs` et `tasks` ;
- les plans owner-only forcent `performance`, `presence` et `team_members` a `false` ;
- les overrides owner-only exceptionnellement permis sont `assistant`, `sales` et `promotions` ;
- les overrides explicites du compte ont priorite sur les defaults sectoriels, avant l'application des restrictions de plan et des dependances.

## Registre des modules

Le registre de reference se trouve dans `CompanyFeatureService`. Les termes servent a la recherche semantique ; ils ne remplacent pas la cle technique. Verifier aussi les cles inconnues ou orphelines : le registre actuel guide l'audit, mais ne constitue pas a lui seul une whitelist de tous les overrides persistables.

| Cle | Termes et domaines a rechercher | Risques particuliers |
| --- | --- | --- |
| `quotes` | quote, quotes, devis, estimation, presupuesto, cotizacion, cotización | conversion de demande, compteurs client, relances |
| `requests` | request, requests, demande, prospect, lead, solicitud, cliente potencial | conversion en devis, inbox CRM |
| `reservations` | reservation, booking, rendez-vous, queue, waitlist, kiosk, reserva, cita, lista de espera | planning, disponibilites, portail client |
| `plan_scans` | plan scan, scan, analyse de plan, escaneo de plano | generation de devis, quotas IA |
| `invoices` | invoice, invoices, facture, payment, receipt, recu, factura, pago, recibo | paiement, Stripe, PDF, email, portail |
| `jobs` | job, jobs, work, works, chantier, trabajo, obra | le modele backend historique utilise souvent `Work` |
| `products` | product, products, produit, catalogue, stock, producto, inventario | peut coexister avec des services selon les overrides |
| `performance` | performance, KPI, metrics, rapports, rendimiento, metricas, métricas | donnees agregees et permissions manager |
| `presence` | presence, attendance, pointage, check-in, presencia, asistencia, fichaje | equipe, planning, disponibilite |
| `planning` | planning, schedule, calendrier, planificacion, planificación, horario | ventes, services, equipe |
| `sales` | sale, sales, order, commande, POS, caisse, venta, pedido, caja | comptes produits et roles vendeur |
| `promotions` | promotion, discount, remise, coupon, promocion, promoción, descuento, cupon, cupón | catalogue, ventes, campagnes |
| `expenses` | expense, expenses, depense, petty cash, gasto | pre-requis de la comptabilite |
| `accounting` | accounting, comptabilite, ledger, journal, contabilidad, libro mayor | depend actuellement de `expenses` |
| `services` | service, services, prestation, servicio, prestacion, prestación | reservations, catalogue de prestations |
| `tasks` | task, tasks, tache, tarea | chantier, planning, facturation par tache |
| `team_members` | team, member, employee, equipe, employe, equipo, miembro, empleado | owner-only, invitations, permissions |
| `assistant` | assistant, AI, IA, copilot, asistente | quotas, suggestions, actions automatiques |
| `campaigns` | campaign, campaigns, campagne, audience, campana, campaña, audiencia | clients, prospects, emails, playbooks |
| `social` | social, post, publication, editorial, publicacion, publicación | campagnes, promotions, catalogue |
| `loyalty` | loyalty, fidelite, points, VIP, reward, fidelidad, puntos, recompensa | portail client, ventes, reservations |

### Domaines transversaux sans cle propre

Ces domaines consomment plusieurs modules et doivent etre controles pendant chaque audit pertinent :

- clients et CRM ;
- paiements, taxes, pourboires et recus ;
- recherche globale et assistant ;
- notifications, emails et SMS ;
- documents PDF et exports ;
- portail client et API/mobile ;
- dashboard, statistiques et rapports ;
- workspaces de demonstration et donnees de lancement.

## Methode d'audit module par module

### 1. Definir le scenario

Avant la recherche, noter :

- cle du module ;
- termes metier FR/EN/ES et alias techniques ;
- type d'entreprise et secteur ;
- plan et overrides ;
- dependances avec d'autres modules ;
- profils a tester : owner, admin, employe, vendeur, client ;
- canaux : Web, portail, API/mobile, notifications, PDF/export ;
- donnees historiques a conserver, avec la politique de visibilite ou d'archive en lecture seule attendue.

Tester au minimum deux comptes :

- module actif ;
- module inactif avec, si possible, d'anciennes donnees du module deja presentes.

Le second cas revele les fuites que ne montre pas une base vide.

### 2. Lancer la recherche globale

Exemple PowerShell pour le module devis :

```powershell
$AuditModule = 'quotes'
$AuditTerms = 'quote|quotes|devis|presupuesto|cotizacion|cotización'

rg -n -F $AuditModule app config database routes resources tests docs
rg -n -i $AuditTerms app config database routes resources tests docs
rg -n -F "hasFeature('$AuditModule')" resources/js app
rg -n -F "visibleFeaturePayload('$AuditModule'" resources/js
```

Pour une recherche plus large en ignorant les artefacts :

```powershell
rg -n -i $AuditTerms . -g '!vendor/**' -g '!node_modules/**' -g '!public/build/**' -g '!storage/**'
```

Adapter `$AuditTerms` aux alias du tableau. Pour `jobs`, rechercher obligatoirement `job|jobs|work|works|chantier`. Pour `invoices`, inclure aussi `payment|receipt|facture|recu`, puis trier les faux positifs.

Comparer les modules proteges cote serveur et utilises cote frontend :

```powershell
$AuditBackend = rg -o --no-filename 'company\.feature:[a-z_]+' routes app |
  ForEach-Object { $_ -replace '^company\.feature:', '' } |
  Sort-Object -Unique

$AuditFrontend = rg -o --no-filename "hasFeature\('[a-z_]+'\)" resources/js |
  ForEach-Object { $_ -replace "hasFeature\('", '' -replace "'\)", '' } |
  Sort-Object -Unique

Compare-Object $AuditBackend $AuditFrontend
```

La comparaison signale des ecarts de couverture ; elle ne prouve pas a elle seule qu'un module est incorrect.

### 3. Verifier la configuration effective

Rechercher la cle dans :

- `CompanyFeatureService` ;
- les plans et settings SuperAdmin ;
- les defaults par secteur ;
- les dependances ;
- les overrides de compte ;
- `DemoWorkspaceCatalog` et `featureMap()` ;
- les templates, seeders et snapshots de reset.

Questions obligatoires :

- la cle existe-t-elle dans tous les registres qui doivent la connaitre ?
- une liste dupliquee peut-elle diverger ?
- une cle inconnue peut-elle etre acceptee, persistee ou exposee par un endpoint administrateur ?
- un preset ou seeder transforme-t-il un default `false` en override `true` ?
- un reset de demo peut-il restaurer une ancienne configuration ?
- une dependance desactive-t-elle correctement son module dependant ?

### 4. Verifier le contexte d'authentification

Pour owner, membre d'equipe et client :

- le meme proprietaire de compte est-il resolu ?
- le modele owner contient-il `company_sector`, `company_features` et le contexte du plan ?
- `auth.account.features` contient-il uniquement les modules effectivement actifs ?
- une cle absente reste-t-elle traitee comme `false` ?
- les permissions sont-elles appliquees en plus du feature flag, et non a sa place ?

Regle : `feature actif` et `permission accordee` sont deux conditions distinctes. Une permission ne doit jamais reactiver un module desactive.

### 5. Auditer toutes les surfaces frontend

Pour chaque occurrence, verifier :

- navigation principale, mega menu, settings et recherche globale ;
- dashboard, KPI, cartes et compteurs ;
- liste, DataTable, colonnes, filtres, tris et segments sauvegardes ;
- fiche, onglets, timeline et activite recente ;
- formulaires complets et creation rapide ;
- boutons, menus d'actions, modales et raccourcis ;
- etats vides et appels a l'action ;
- portail client et vues publiques ;
- vocabulaire, labels et traductions FR/EN/ES ;
- responsive : tableau desktop et cartes mobile ;
- chargement, skeleton et `colspan` dynamique ;
- composants partages utilises depuis plusieurs modules.

Patterns recommandes :

```js
const { hasFeature } = useAccountFeatures();
const quotesFeatureEnabled = computed(() => hasFeature('quotes'));
```

```vue
<div v-if="quotesFeatureEnabled">
    <!-- contenu devis -->
</div>
```

Pour un menu, garder aussi le conteneur :

```js
const hasAvailableActions = computed(() => (
    quotesFeatureEnabled.value || invoicesFeatureEnabled.value
));
```

Ne pas seulement cacher les items. Le bouton qui ouvre un menu vide doit lui aussi disparaitre.

### 6. Auditer le serveur et les acces directs

Le backend doit etre la protection finale. Verifier :

- routes Web et API ;
- middleware, policies et permissions ;
- controller `index`, `show`, `store`, `update`, `destroy` et actions speciales ;
- jobs, listeners, commandes planifiees et webhooks ;
- services appeles depuis l'assistant ;
- endpoints de creation rapide et actions bulk ;
- exports, PDF, emails et SMS.

Pour une liste ou un DataTable, une fonctionnalite desactivee implique aussi :

- ignorer ou supprimer ses filtres dans la query string ;
- refuser ses tris ;
- ne pas calculer ses `withCount`, statistiques ou classements ;
- ne pas recharger ses relations lourdes ;
- neutraliser un segment sauvegarde contenant un ancien filtre ;
- retourner un fallback vide dans le payload si la relation n'est pas pertinente.

Pour une mutation :

- omettre le champ desactive cote frontend ;
- forcer une valeur sure cote serveur ;
- bloquer l'action directe avec la convention HTTP du module ;
- verifier qu'aucun effet de bord, notification ou paiement n'est cree.

Masquer l'interface sans proteger l'endpoint n'est jamais suffisant.

### 7. Auditer les donnees et la terminologie

Verifier les donnees historiques, meme si le module est maintenant desactive :

- aucun compteur ou lien ne doit les reveler par accident ;
- une timeline ne doit pas construire un lien vers une route desactivee ;
- les payloads API et Inertia ne doivent pas exposer inutilement les relations ;
- les anciennes donnees ne doivent pas etre supprimees automatiquement sans plan de migration ;
- un changement de secteur ne doit pas rendre une fiche incoherente.

Une exception archivale ou contractuelle, par exemple une facture deja emise, un recu ou une transaction en cours, doit avoir une politique explicite : acces en lecture seule, autorisation serveur, duree, canal autorise et tests de non-mutation. Elle ne doit pas reactiver le module complet.

Verifier aussi les concepts voisins. Exemple : si `jobs=false`, rechercher non seulement les liens chantier, mais aussi `fin de chantier`, facturation par tache, adresse du bien, segments de chantier et actions de conversion.

### 8. Auditer demos, seeds et documentation

Verifier :

- modules par defaut du preset ;
- `featureMap()` ou toute conversion en overrides ;
- donnees generees par le provisioner ;
- scenarios guides et selecteurs `data-testid` ;
- templates existants ;
- baseline et reset ;
- comptes deja provisionnes ;
- guides de demo et captures d'ecran.

Une demo ne doit pas dependre d'un module exclu. Par exemple, une facture de prestation doit pouvoir etre generee sans creer artificiellement un chantier.

Ne pas modifier en masse les comptes existants sans distinguer :

- ancien default automatique ;
- choix explicite d'un administrateur ;
- compte avec donnees historiques ;
- template reutilisable volontairement personnalise.

## Matrice de tests obligatoire

| Scenario | Module actif | Module inactif |
| --- | --- | --- |
| Owner | parcours complet fonctionnel | aucune fuite UI, route directe protegee |
| Admin/employe | permission respectee | feature du owner heritee, aucune reactivation locale |
| Client portail | contenu et actions autorises | liens, historique et actions absents |
| Liste/DataTable | filtres, tris et compteurs corrects | filtres ignores, colonnes et stats absentes |
| Fiche | onglets et actions fonctionnels | cartes, timeline et menus filtres |
| Creation rapide | champs et payload presents | champs omis, serveur force une valeur sure |
| API/mobile | payload et mutations fonctionnels | acces refuse ou payload neutralise |
| Donnees historiques | visibles et navigables | masquees, ou accessibles en lecture seule selon une exception documentee |
| Demo | parcours et donnees coherents | aucun override ou seed parasite |
| Regression | module voisin intact | module voisin intact |

Ajouter au minimum :

- un test feature serveur `module actif` ;
- un test feature serveur `module inactif` avec acces direct ;
- un test avec membre d'equipe ;
- un test avec donnees historiques ;
- un test du preset demo si le module est propose dans les demos ;
- un test frontend ou E2E pour les ecrans a fort risque.

## Tableau de suivi global

Statuts autorises : `Conforme`, `Defaut`, `Non applicable`, `A verifier`.

| Module | Config | UI Web | Serveur | API/portail | Demo/seed | Tests | Statut | Preuve ou ticket |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `quotes` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `requests` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `reservations` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `plan_scans` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `invoices` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `jobs` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `products` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `performance` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `presence` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `planning` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `sales` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `promotions` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `expenses` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `accounting` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `services` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `tasks` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `team_members` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `assistant` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `campaigns` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `social` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |
| `loyalty` | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | A verifier | |

Copier ce tableau dans le rapport date. Ne pas transformer le runbook lui-meme en journal d'execution.

## Pieges connus

- cacher uniquement le lien de navigation ;
- laisser un bouton de menu qui ouvre un panneau vide ;
- cacher une action sans bloquer sa fonction JavaScript ;
- proteger le frontend sans proteger la route ;
- laisser un filtre cache dans l'URL ou un segment sauvegarde ;
- calculer des statistiques sur une relation desactivee ;
- charger le owner sans son contexte de fonctionnalites ;
- confondre permission et activation du module ;
- lire directement `company_features` au lieu des fonctionnalites effectives ;
- supposer qu'un module absent vaut `true` ;
- garder un libelle metier inadapte dans un composant partage ;
- reutiliser un preset demo qui ecrase les defaults de secteur ;
- tester uniquement une base vide ;
- oublier les cartes mobiles alors que le tableau desktop est correct ;
- afficher une action sans parcours reel derriere.

## Validation technique

Executer les controles proportionnes au changement :

```powershell
php artisan test tests/Unit/CompanyFeatureServiceTest.php tests/Feature/SectorModuleDefaultsTest.php tests/Feature/ModuleVisibilityConsistencyTest.php
npm run build
git diff --check
```

Si un fichier PHP est ajoute, modifie ou supprime :

1. indexer la version finale de tous les fichiers PHP ;
2. executer `composer qa:format` ;
3. reindexer toute correction de format ;
4. relancer `composer qa:format` immediatement avant le push ou la livraison ;
5. verifier `git diff --check`.

Pour les parcours critiques, completer par un smoke test navigateur avec les comptes `module actif` et `module inactif`.

## Definition de termine

Un module est `Conforme` seulement si :

- sa configuration effective est correcte pour plan, secteur et overrides ;
- tous les profils partagent la meme source de verite ;
- navigation, recherche, dashboard, listes et fiches sont propres ;
- formulaires, actions, modales et etats vides sont gardes ;
- filtres, tris, stats et payloads sont neutralises lorsqu'il est inactif ;
- routes et mutations directes sont protegees ;
- API/mobile, portail, notifications, PDF et exports sont coherents ;
- demos, seeds, templates et resets ne le reactivent pas ;
- le vocabulaire metier est adapte dans les trois langues ;
- les cas owner, membre d'equipe et client sont testes ;
- le cas avec donnees historiques est teste ;
- les tests cibles, le build et les quality gates sont verts ;
- la preuve est liee dans le rapport date.

## Ameliorations d'automatisation recommandees

Pour reduire davantage les audits manuels :

1. centraliser les listes de modules du plan, du SuperAdmin et des demos dans un registre unique ;
2. ajouter une commande `php artisan modules:audit` qui compare ces registres et les dependances ;
3. ajouter une matrice CI `module actif/inactif` pour les routes critiques ;
4. ajouter des tests de composants pour menus, DataTables et etats vides ;
5. ajouter des scenarios E2E owner/membre/client par type d'entreprise ;
6. ajouter une verification statique des routes et composants qui utilisent un terme de module sans garde associee ;
7. ajouter ce runbook comme case obligatoire dans le template de pull request pour tout changement de module.

## References

- `docs/BUSINESS_TYPES_USER_STORIES.md`
- `docs/APP_GUIDE.md`
- `docs/PRICING_STRUCTURE_REDESIGN_USER_STORY.md`
- `docs/DEMO_WORKSPACES_V2_USER_STORY.md`
- `app/Services/CompanyFeatureService.php`
- `app/Support/Database/UserSelects.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/Composables/useAccountFeatures.js`
- `resources/js/utils/features.js`
