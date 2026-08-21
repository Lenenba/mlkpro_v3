# Module Clients — étape 2/3 : livraison de l'index

Date : 2026-08-21  
Branche : `develop`  
Statut du document : complet  
Statut de la livraison : terminée  
Suite du chantier : étape 3 — historique unifié

## Résultat

L'index Clients conserve ses fonctions existantes et reçoit la nouvelle expérience prévue à l'étape 2 : KPI globaux, filtres rapides combinables, dialogue de filtres avancés, résumé des critères, URL et segments compatibles, liste plus lisible et états d'interface accessibles.

La fiche Client et sa chronologie ne sont pas modifiées. Elles restent le périmètre exclusif de l'étape 3.

## Fonctionnalités livrées

### KPI

- Grille compacte et responsive, avec six indicateurs prioritaires au maximum selon les capacités du compte.
- Indicateurs secondaires repliables : inactifs, annulations, no-shows, taux de retour, valeur moyenne et rendez-vous moyens.
- Conservation des indicateurs historiques devis/travaux pour les profils concernés.
- Valeurs globales et stables pendant le filtrage; le nombre de résultats courant reste séparé.
- Cartes filtrantes avec état `aria-pressed` lorsque l'indicateur possède un critère exact.
- Montants limités à la devise du compte et masqués lorsque l'acteur n'a pas accès à la facturation.

### Filtres rapides et navigation

- Sélection multiple via `quick_filters[]`.
- Modes `quick_filter_mode=all` (ET, valeur par défaut) et `quick_filter_mode=any` (OU dans un groupe SQL isolé).
- Badges supprimables, compteur de critères, compteur de résultats et action de remise à zéro.
- Visites explicites enregistrées dans l'historique navigateur; recherche temporisée avec remplacement de l'entrée courante.
- Resynchronisation de l'état Vue depuis les props Inertia pour le retour et l'avance navigateur.
- Pagination basée uniquement sur les filtres normalisés et autorisés.
- Compatibilité de lecture de l'ancien alias scalaire `operational_filter`; le tableau canonique prend toujours la priorité.

### Filtres avancés

- Dialogue sur ordinateur et plein écran sur mobile.
- État brouillon : aucune requête pendant la saisie; Annuler abandonne les changements, Réinitialiser vide le brouillon et Appliquer effectue une seule visite.
- Sections profil, rendez-vous, forfaits, facturation et périodes.
- Options locataire pour les sources d'acquisition, tags et niveaux VIP.
- Pied fixe, compte de critères du brouillon, titre et description ARIA, fermeture Échap, focus initial et restauration du focus déclencheur.
- Les filtres existants ville, pays, devis, travaux et forfaits restent accessibles.

### Liste et états

- Statut, VIP et deux tags maximum visibles sans surcharger les lignes et les cartes.
- Présentation opérationnelle rendez-vous conservée : dernière visite, prochain rendez-vous, membre habituel, forfait, valeur et impayé selon les capacités.
- États distincts : chargement filtré, compte vide, aucun résultat, erreur récupérable, aucun rendez-vous et aucun impayé.
- CTA de création soumis à la capacité de l'acteur.
- `aria-busy`, annonce vivante des résultats, tris `aria-sort`, noms accessibles des cases et cibles tactiles adaptées.

## Contrat backend livré

Le même normaliseur est utilisé par l'index et les segments sauvegardés. Les entrées canoniques sont :

```text
quick_filters[]
quick_filter_mode = all|any
status / client_type / is_vip / vip_tier_id
acquisition_source / tags[]
has_upcoming_appointment
last_appointment_from / last_appointment_to
next_appointment_from / next_appointment_to
appointments_min / appointments_max / cancellations_min / no_shows_min
has_outstanding_balance / outstanding_min / outstanding_max
total_invoiced_min / total_invoiced_max
last_invoice_from / last_invoice_to / payment_statuses[]
created_from / created_to
city / country / has_quotes / has_works
has_active_package / package_*
sort / direction / per_page
```

La réponse distingue :

- `stats` : métriques historiques filtrées conservées pour compatibilité;
- `kpis` : aperçu global stable et soumis aux capacités;
- `filterMeta` : résultat courant, critères actifs, mode et filtres rapides disponibles;
- `filterOptions` : options avancées, évaluées paresseusement par Inertia;
- `filters` : état normalisé, autorisé et sérialisable dans l'URL.

Les bornes civiles sont calculées dans le fuseau de l'entreprise, puis converties dans le fuseau de l'application avec une borne haute exclusive. Les statuts actifs d'une réservation proviennent de `Reservation::ACTIVE_STATUSES`.

## Sécurité, cohérence et performance

- Le scope locataire est appliqué avant le groupe OU et chaque sous-requête relationnelle conserve l'identifiant de compte effectif.
- Les KPI et options utilisent le même répertoire Clients effectif que la liste, y compris pour un membre d'équipe.
- Les filtres et KPI financiers sont retirés lorsque `InvoicePolicy::viewAny` refuse l'accès.
- Les soldes excluent les factures supprimées et les statuts fermés; seuls les paiements réglés du bon locataire et de la bonne devise réduisent le solde.
- Les tableaux, dates, nombres, booléens et identifiants issus d'anciens segments sont normalisés de manière totale; une valeur mal formée est ignorée.
- Les agrégats globaux et les options coûteuses sont des props Inertia paresseuses et ne sont pas recalculés pendant la recherche live.
- La collecte de tags est bornée et les tags sélectionnés utilisent une sémantique ET portable via `whereJsonContains`.

## Fichiers structurants

### Backend

- `app/Http/Requests/CustomerIndexRequest.php`
- `app/Queries/Customers/CustomerIndexFilters.php`
- `app/Queries/Customers/BuildCustomerIndexStats.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Services/Segments/Resolvers/CustomerSegmentResolver.php`

### Frontend

- `resources/js/Components/Customer/CustomerKpiGrid.vue`
- `resources/js/Components/Customer/CustomerAdvancedFiltersDialog.vue`
- `resources/js/Components/Customer/CustomerFilterSummary.vue`
- `resources/js/utils/customerFilters.js`
- `resources/js/Components/UI/CustomerStats.vue`
- `resources/js/Pages/Customer/UI/CustomerTable.vue`
- `resources/js/Components/Modal.vue`
- traductions Clients françaises, anglaises et espagnoles

### Tests

- `tests/Feature/CustomerIndexExperienceTest.php`
- `tests/Node/CustomerIndexExperienceTest.mjs`
- suites de régression Clients, forfaits et segments existantes

## Validation

- Feature sous SQLite en mémoire : 27 tests, 286 assertions réussies.
- Node : 100 tests réussis, dont les nouveaux contrats de normalisation, navigation, dialogue et traductions.
- Build Vite de production : réussi, 2 632 modules transformés.
- PHPStan : réussi, aucune erreur.
- Gate PHP obligatoire `composer qa:format` : réussi sur les 6 fichiers PHP modifiés.
- `git diff --check` : réussi.
- Index documentaire : généré et contrôlé.

La vérification visuelle interactive desktop/mobile n'a pas pu être exécutée dans le navigateur intégré, car son outil de contrôle n'était pas exposé dans la session. Le build, les contrats responsive/accessibilité et les tests automatisés sont verts; cette limite d'environnement est consignée sans la présenter comme une validation visuelle.

## Décision de passage à l'étape 3

L'étape 2 est close. L'étape 3 peut démarrer séparément pour construire la chronologie métier, les périodes/types, la pagination d'activité et la journalisation des changements manquants.
