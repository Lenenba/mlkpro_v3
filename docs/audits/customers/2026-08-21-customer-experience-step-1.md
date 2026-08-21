# Module Clients — étape 1/3 : audit et contrat d'implémentation

Date : 2026-08-21  
Branche auditée : `develop`  
Statut du document : complet  
Statut du chantier : étape 2 livrée; étape 3 à livrer

## Résultat exécutif

Le socle actuel doit être amélioré progressivement, pas remplacé. La liste Inertia, la pagination, les vues tableau/cartes, les sélections en masse, les segments sauvegardés et plusieurs résumés opérationnels sont réutilisables. Les principaux écarts sont structurels : un seul filtre rapide peut être actif, les KPI ont des définitions ambiguës, les filtres avancés s'appliquent immédiatement dans une grille dépliée et l'historique n'est pas une chronologie métier complète.

Le chantier est figé en trois livraisons :

1. **Étape 1 — audit et contrat UX/données** : le présent document.
2. **Étape 2 — index Clients** : KPI, filtres combinables, dialogue avancé, liste, états et accessibilité.
3. **Étape 3 — fiche Client** : historique unifié, filtres de période/type, journalisation manquante et validation responsive finale.

Aucun comportement produit n'est modifié par cette première étape.

## 1. Architecture actuelle

### Backend

- Les routes web et API utilisent le même `CustomerController` : `routes/web.php`, `routes/api.php` et `app/Http/Controllers/CustomerController.php`.
- La liste s'appuie sur `Customer::scopeFilter()` et `BuildCustomerOperationalIndexData`.
- La fiche s'appuie sur `BuildCustomerDetailViewData`.
- Les segments sauvegardés reconstruisent leurs propres critères dans `CustomerSegmentResolver`; tout nouveau contrat de filtre doit donc être partagé avec ce résolveur.
- Les relations utiles existent déjà sur `Customer` : réservations, factures, paiements, ventes, forfaits, niveau VIP et campagnes.
- La pagination utilise déjà `withQueryString()`. Le nouveau format pourra donc être conservé entre les pages sans changer le mécanisme de pagination.

### Frontend

- `resources/js/Pages/Customer/Index.vue` orchestre les KPI et la liste.
- `resources/js/Components/UI/CustomerStats.vue` rend les KPI actuels.
- `resources/js/Pages/Customer/UI/CustomerTable.vue` concentre recherche, filtres, tri, tableau/cartes, pagination, sélection et actions.
- `resources/js/Pages/Customer/Show.vue` et `resources/js/Components/CRM/SalesActivityPanel.vue` rendent l'activité actuelle de la fiche.
- L'interface est en Vue 3/Inertia/Tailwind; aucun composant Twig ou Livewire n'est impliqué.

## 2. Limites confirmées

### KPI

- Les KPI actuels sont limités à `total`, `new`, `active` et, selon le profil, devis/travaux.
- Ils sont calculés sur la requête déjà filtrée. Le « total » correspond donc au résultat courant et non au portefeuille complet.
- `new` signifie actuellement 30 jours glissants, pas le mois civil courant.
- `active` signifie activité relationnelle récente, pas `customers.is_active`.
- Les cartes sont statiques : elles ne sont ni cliquables ni dotées d'un état accessible actif.

### Filtres et URL

- `operational_filter` est scalaire; la sélection d'un filtre rapide remplace le précédent.
- Aucun groupe ET/OU, résumé, badge supprimable ou compteur de critères n'existe.
- Les filtres avancés sont affichés dans une grille dépliée et chaque changement provoque une requête après 300 ms; il n'existe pas d'état brouillon avec Annuler/Appliquer.
- L'URL Inertia est déjà synchronisée pour les champs actuels, valeurs vides omises, avec `replace: true`.
- Les segments sauvegardés ne conservent qu'un filtre opérationnel scalaire.

### Liste et états

- La variante « rendez-vous » montre déjà statut/VIP, dernière visite, prochain rendez-vous, membre habituel, forfait, valeur et impayé.
- La variante générique est moins informative et n'expose pas tags, VIP, valeur ou rendez-vous.
- Le même état vide est utilisé pour un compte sans client et une recherche sans résultat. La proposition de création n'est pas toujours conditionnée par la capacité utilisateur.
- Les skeletons tableau/cartes existent; il manque un état d'erreur récupérable, `aria-busy` et une annonce vivante du nombre de résultats.

### Historique

- `show()` lit `name`, `status` et `month`, mais ces valeurs ne filtrent pas l'activité.
- L'activité est limitée à 12 `ActivityLog`, sans pagination, période ou filtre de type.
- Les réservations, annulations et no-shows ne sont pas collectés directement.
- Factures et paiements sont chargés séparément, mais ne sont pas fusionnés dans la chronologie.
- Le contrat actuel ne garantit pas type, statut, titre, montant, icône ni lien.
- Les archives/restaurations en masse ne produisent pas de journal d'état; les mises à jour de profil ne stockent pas de diff avant/après.

## 3. Risques à traiter pendant l'implémentation

1. **Autorisations financières** : la fiche peut charger des données financières avec un contrôle de fonctionnalité plus large que la politique des factures. Chaque projection de KPI et d'historique devra être filtrée par capacité et politique.
2. **Isolation locataire avec le mode OU** : toutes les branches `orWhereHas` devront rester dans un groupe parent appliqué après le scope du compte; aucune branche OU ne doit pouvoir contourner `byUser()`.
3. **Cohérence financière** : la fiche additionne aujourd'hui des paiements de statuts différents. Les nouveaux montants réutiliseront une définition canonique basée sur la devise du compte et les statuts réglés.
4. **Performance** : une requête indépendante par carte KPI produirait trop d'allers-retours. Les agrégats seront regroupés et les index ne seront ajoutés qu'après mesure avec `EXPLAIN` sur MySQL.
5. **Fuseau horaire** : les fenêtres mensuelles et les présélections devront partir des bornes du fuseau de l'entreprise, puis être converties en UTC.
6. **Compatibilité** : `operational_filter` et les segments déjà enregistrés resteront acceptés pendant la migration vers les tableaux.
7. **Taille des composants** : `CustomerTable.vue`, `Show.vue` et `CustomerController.php` sont déjà volumineux. Les nouvelles responsabilités seront extraites au lieu d'allonger ces fichiers.

## 4. Contrat UX de l'index Clients

### Ordre de la page

1. titre et actions existantes;
2. grille compacte des KPI prioritaires;
3. recherche, filtres rapides et bouton Filtres avancés;
4. contrôle « Tous les critères / Au moins un critère »;
5. résumé des filtres appliqués, badges supprimables et nombre de résultats;
6. tableau ou cartes existants;
7. pagination existante.

### KPI prioritaires

Six indicateurs peuvent être visibles sans interaction lorsque la capacité associée existe :

- total Clients;
- nouveaux ce mois-ci;
- actifs;
- VIP;
- sans prochain rendez-vous;
- impayés, sous la forme « nombre de clients + montant ».

Les indicateurs secondaires sont rangés derrière « Voir tous les indicateurs » ou dans une seconde rangée responsive :

- inactifs;
- annulation récente;
- no-show récent;
- taux de retour;
- valeur moyenne par client;
- rendez-vous terminés moyens par client.

Les cartes qui correspondent à un critère exact agissent comme des boutons de filtre. Elles exposent `aria-pressed`, un focus visible et le même état que la puce associée. Les cartes analytiques non filtrables restent non interactives.

Les valeurs de la grille sont **globales au compte et stables**, soumises aux capacités de l'utilisateur mais pas aux filtres courants. Le nombre de résultats filtrés est affiché séparément. Ce choix empêche une carte de changer de dénominateur au moment où elle est activée.

### Définitions métier retenues

| Indicateur | Définition retenue |
| --- | --- |
| Total | Clients du compte visibles par l'utilisateur. |
| Nouveaux ce mois | `created_at` entre le début du mois civil local et maintenant. |
| Actifs / inactifs | Valeur de lifecycle `customers.is_active`; l'activité récente reste une notion distincte. |
| VIP | `customers.is_vip = true`, uniquement lorsque la capacité VIP/campagnes est exposée. |
| Sans prochain rendez-vous | Client actif sans réservation future dans un statut actif (`pending`, `confirmed` ou `rescheduled`). |
| Avec prochain rendez-vous | Au moins une réservation future dans un statut actif (`pending`, `confirmed` ou `rescheduled`). |
| Impayé | Somme des factures ouvertes moins paiements réglés, dans la devise du compte, strictement supérieure à zéro. |
| Annulation récente | `cancelled_at` dans les 30 derniers jours locaux. |
| No-show récent | Rendez-vous au statut `no_show` dont `starts_at` est dans les 30 derniers jours locaux. |
| Taux de retour | Clients avec au moins deux rendez-vous terminés / clients avec au moins un rendez-vous terminé. |
| Valeur moyenne | Total réglé dans la devise du compte, net des pourboires annulés, divisé par tous les clients du compte. |
| Rendez-vous moyens | Nombre de rendez-vous terminés / tous les clients du compte. |

Les divisions par zéro retournent `0`, jamais `NaN`. Les KPI financiers ne sont ni calculés ni envoyés à un utilisateur sans capacité de consultation correspondante.

### Filtres rapides combinables

Le groupe canonique est un tableau ordonné et dédupliqué :

```text
quick_filters[]=vip
quick_filters[]=no_next_appointment
quick_filter_mode=all
```

Filtres rapides cibles :

- `vip`;
- `new` — compatibilité avec les 30 jours glissants existants;
- `new_this_month` — utilisé par le KPI mensuel;
- `no_next_appointment`;
- `upcoming_appointment`;
- `outstanding_balance`;
- `inactive`;
- filtres existants conservés : `follow_up_90`, `package_low`, `birthday_upcoming`.

Règles :

- `quick_filter_mode=all` est la valeur par défaut et applique tous les prédicats avec ET;
- `quick_filter_mode=any` place les prédicats dans un unique groupe parent avec OU;
- recherche, filtres avancés, restrictions d'autorisation et groupe de filtres rapides restent reliés entre eux par ET;
- les doublons et clés inconnues sont supprimés par validation;
- les filtres indisponibles pour le profil/capacité sont ignorés côté serveur, sans exposer de données;
- toute modification de filtre revient à la page 1;
- l'URL, la pagination, l'historique navigateur et les segments sauvegardés conservent le tableau et le mode;
- l'ancien `operational_filter` reste accepté comme alias scalaire pendant la transition.

Exemple logique :

```text
tenant
AND recherche
AND filtres_avances
AND (
    VIP AND sans_prochain_rendez_vous        # mode all
    ou
    VIP OR sans_prochain_rendez_vous         # mode any
)
```

### Dialogue des filtres avancés

Le bouton ouvre un dialogue natif basé sur `resources/js/Components/Modal.vue` :

- largeur de dialogue sur ordinateur;
- plein écran sous le breakpoint `md`;
- titre et description reliés par ARIA;
- focus initial déterministe, fermeture par Échap et restauration du focus sur le déclencheur;
- corps défilable et pied de page fixe.

À l'ouverture, une copie des filtres appliqués alimente un état brouillon. Modifier le brouillon ne recharge pas la liste.

- **Annuler** ferme et abandonne le brouillon;
- **Réinitialiser** vide le brouillon sans fermer;
- **Appliquer les filtres** valide, met à jour l'URL et recharge la page 1;
- le pied affiche un compte prospectif seulement si le calcul peut être réalisé sans dégrader les performances; la requête est alors temporisée et annulable.

Sections du dialogue :

- Profil : statut, type, VIP, niveau VIP, source d'acquisition, tags, date de création;
- Rendez-vous : avec/sans prochain rendez-vous, dernier/prochain rendez-vous, volumes, annulations, no-shows;
- Facturation : avec impayé, bornes de solde, total facturé, dernière facture, statuts de paiement;
- Période : bornes personnalisées des dates de création, rendez-vous et facture.

Après application, chaque filtre avancé apparaît dans le résumé sous forme de badge supprimable.

### Liste et états

La variante rendez-vous existante est conservée et rationalisée autour de : Client, statut/VIP/tags, dernière visite, prochain rendez-vous, valeur/solde et actions. Deux tags maximum sont visibles; les autres sont résumés par `+N`.

Sur mobile, le mode cartes devient la présentation prioritaire et reprend les informations essentielles. Le tableau reste disponible et scrollable sur les écrans qui le permettent.

États distincts :

- chargement initial avec squelette stable;
- rechargement filtré avec `aria-busy` et annonce discrète;
- compte vide, avec CTA uniquement si `canCreate`;
- aucun résultat, avec résumé des filtres et action « Tout effacer »;
- erreur récupérable avec action « Réessayer »;
- valeurs métier vides explicites : « Aucun rendez-vous à venir » et « Aucun solde impayé ».

## 5. Contrat de données et API pour l'étape 2

### Entrée index

Un `CustomerIndexRequest` validera et normalisera au minimum :

```text
name
quick_filters[]
quick_filter_mode = all|any
status
client_type
is_vip
vip_tier_id
acquisition_source
tags[]
has_upcoming_appointment
last_appointment_from / last_appointment_to
next_appointment_from / next_appointment_to
appointments_min / appointments_max
cancellations_min
no_shows_min
has_outstanding_balance
outstanding_min / outstanding_max
total_invoiced_min / total_invoiced_max
last_invoice_from / last_invoice_to
payment_statuses[]
created_from / created_to
sort / direction / per_page / page
```

Le même normaliseur/builder sera utilisé par l'index, le compte prospectif et `CustomerSegmentResolver`. Cela évite trois sémantiques de filtrage différentes.

### Sortie index

Le payload distinguera :

```text
kpis                         # aperçu global autorisé
filter_meta.matching_count   # résultat courant
filter_meta.active_filters
filter_meta.quick_filter_mode
filter_meta.available_filters
customers.data[].customer_summary
```

Les modèles Eloquent complets ne doivent pas devenir le contrat permanent de l'API. Un resource/DTO explicite stabilisera progressivement les propriétés envoyées.

Un endpoint de prévisualisation (`customer.filter_preview`) ne sera ajouté que si le comptage prospectif ne peut pas réutiliser efficacement une visite partielle Inertia. Il restera en lecture seule, tenant-scoped et soumis aux mêmes capacités.

### Performance et schéma

- Pas de nouvelle table requise pour l'étape 2.
- Agrégats conditionnels et sous-requêtes partagées plutôt qu'une requête par KPI.
- Mesure du nombre de requêtes et des plans MySQL avant d'ajouter des index.
- Index candidats à vérifier : clients `(user_id, is_active, created_at)`, clients `(user_id, client_type)`, factures `(user_id, customer_id, status, created_at)` et paiements `(user_id, invoice_id, status, currency_code)`.
- Les tags JSON ne recevront pas d'index générique sans preuve d'un besoin de performance et sans stratégie compatible SQLite/MySQL.

## 6. Contrat de l'historique pour l'étape 3

Un builder dédié, par exemple `BuildCustomerTimelineData`, fusionnera les sources autorisées après filtrage dans chaque source :

- réservations : passée, future, terminée, annulée, no-show;
- factures;
- paiements et statuts de remboursement/renversement;
- notes et communications CRM;
- événements de campagne;
- modifications de profil, statut et niveau VIP consignées dans `ActivityLog`.

Contrat normalisé :

```json
{
  "id": "reservation:123",
  "occurred_at": "2026-08-21T14:30:00Z",
  "type": "appointment",
  "status": "completed",
  "title": "Rendez-vous terminé",
  "description": "Coupe signature avec Nadia",
  "amount": { "value": 85.0, "currency_code": "CAD" },
  "resource": { "type": "reservation", "id": 123, "href": "/reservation/123" },
  "icon_key": "calendar-check",
  "actor": { "id": 45, "name": "Nadia" }
}
```

Le tri est stable par `(occurred_at, source, id)` et la pagination utilise un curseur ou un mécanisme « Charger plus »; la limite silencieuse de 12 disparaît.

Paramètres :

```text
period = last_7_days|last_30_days|last_90_days|last_6_months|current_year|previous_year|all|custom
from / to
types[] = appointments|invoices|payments|notes|communications|profile_changes
cursor / per_page
```

La route web suivra le nom `customer.activity_index`; l'API aura son équivalent. Les bornes sont inclusives dans le fuseau de l'entreprise.

Pour fiabiliser le futur historique, l'étape 3 ajoutera des propriétés avant/après aux changements de statut, profil et VIP, ainsi qu'une journalisation des archives/restaurations en masse. La création d'une table d'audit dédiée ne sera envisagée que si l'exactitude historique rétroactive ne peut pas être obtenue avec les sources existantes.

## 7. Composants à réutiliser et à extraire

### À réutiliser

- `AdminDataTable`, `AdminDataTableToolbar`, `AdminPaginationLinks` et les actions existantes;
- `SavedSegmentBar`;
- `FloatingInput`, `FloatingSelect`, `DatePicker`;
- `resources/js/Components/Modal.vue` comme base du dialogue natif;
- `useCurrencyFormatter` et `crmButtonStyles`;
- motifs visuels des cartes compactes de `ReservationStats.vue`, `ProductStats.vue` et `InvoiceStats.vue`;
- chips accessibles de `CategoryChips.vue`;
- structure header/corps/footer des dialogues Réservations et Forfaits;
- présentation chronologique de `ProspectInteractionTimeline.vue` et fonctions de saisie de `SalesActivityPanel.vue`, sans réutiliser leurs contrats métier tels quels.

### À créer ou extraire

- `CustomerKpiGrid.vue` : configuration, mise en page compacte, états interactifs;
- `CustomerAdvancedFiltersDialog.vue` : état brouillon et sections;
- `CustomerFilterSummary.vue` : compteur, mode et badges supprimables;
- helpers JS purs de normalisation/sérialisation des filtres;
- `CustomerHistoryTimeline.vue` à l'étape 3;
- `CustomerIndexRequest`, un builder de filtres partagé et un builder d'agrégats;
- `BuildCustomerTimelineData` à l'étape 3.

`resources/js/Components/UI/Modal.vue` ne sera pas utilisé : il contient un libellé ARIA fixe et un contrat incohérent. Le composant natif `resources/js/Components/Modal.vue` est la base à renforcer de manière rétrocompatible.

## 8. Accessibilité et responsive — critères d'acceptation

- Tous les champs ont un libellé visible ou programmatique; aucun champ ne dépend uniquement d'un placeholder.
- Les filtres et KPI interactifs exposent `aria-pressed`.
- Le bouton du dialogue expose `aria-expanded` et `aria-controls`.
- Le dialogue possède titre/description, fermeture Échap, confinement et restauration du focus.
- Le compteur de résultats utilise `aria-live`; la liste expose `aria-busy` pendant les rechargements.
- Le tri expose `aria-sort`; les cases de sélection ont un nom accessible.
- Les cibles tactiles interactives atteignent au moins 44 × 44 px lorsque l'espace le permet.
- Les animations respectent `prefers-reduced-motion`.
- Desktop et mobile sont vérifiés sur une session authentifiée, y compris URL, retour navigateur, dialogue, états vides et timeline.

## 9. Plan de tests

### Étape 2

- Feature : KPI, zéros, devise, tenant, politiques et capacités.
- Feature : combinaisons ET/OU, doublons, clés invalides et absence de doublons de clients.
- Feature : compatibilité de `operational_filter` et des segments sauvegardés.
- Feature : sérialisation URL, pagination, tri, `per_page` et remise à la page 1.
- Feature : bornes de dates et fuseau horaire.
- Feature : isolation des agrégats réservations/factures/paiements/forfaits et budget de requêtes.
- Node : helpers de filtres, badges, reset, traductions FR/EN/ES et contrats ARIA.
- Playwright : carte KPI, ET/OU, URL/retour navigateur, Annuler/Appliquer/Réinitialiser, focus/Échap, desktop/mobile et états.

### Étape 3

- Feature : chaque source de chronologie, ordre stable, pagination et isolation locataire.
- Feature : périodes, dates personnalisées, filtres multi-types et permissions.
- Feature : montants/devise, paiements remboursés ou renversés et journalisation avant/après.
- Node : helpers de périodes/types et contrat de rendu.
- Playwright : timeline desktop/mobile, clavier, liens, chargement et historique vide.

## 10. Validation de l'étape 1

- Branche : `develop`.
- Audit backend, frontend et tests : lecture seule avant rédaction du présent document.
- Tests Node existants : `npm run qa:node` — 96 réussis.
- Baseline Feature ciblée sous SQLite en mémoire — 18 tests, 361 assertions réussies.
- Une exécution sans surcharge explicite tente la base MySQL locale; elle ne doit pas être utilisée avec `RefreshDatabase`. Les validations locales ciblées devront forcer SQLite ou utiliser une base MySQL de test isolée.
- Inspection visuelle runtime : reportée aux étapes 2 et 3, car le navigateur intégré n'était pas exposé dans cette session.
- Aucun fichier PHP modifié à cette étape; le gate `composer qa:format` ne s'applique pas à cette livraison documentaire.

## Décision de passage à l'étape 2

L'étape 2 peut commencer sur ce contrat. Elle devra livrer l'index Clients complet et ses tests sans entamer la chronologie détaillée, réservée à l'étape 3.
