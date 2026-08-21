# Module Clients — étape 3/3 : historique unifié

Date : 2026-08-21

Branche : `develop`

Statut du document : complet

Statut de la livraison : terminée

Chantier : clos — les trois étapes sont livrées

## Résultat

La fiche Client dispose maintenant d'une chronologie métier unifiée. Elle rassemble les rendez-vous, factures, paiements, notes, communications, campagnes et changements de profil dans un même flux filtrable et paginé. Les données envoyées dépendent des fonctionnalités, des politiques et de la portée opérationnelle de l'acteur.

L'ancien payload `activity` reste présent pour les consommateurs existants. Le nouvel écran s'appuie sur le contrat `customerActivity` et un endpoint JSON commun au web et à l'API.

## Expérience livrée

- Onglet Historique affiché en premier dans l'espace d'activité de la fiche Client.
- Présélections de période : 7, 30 et 90 jours, 6 mois, année en cours, année précédente, historique complet et période personnalisée.
- Filtres combinables par type : rendez-vous, factures, paiements, notes, communications et profil.
- Regroupement par jour dans le fuseau de l'entreprise, tri déterministe et action « Charger plus ».
- Montants et liens rendus uniquement lorsqu'ils existent dans le payload serveur autorisé.
- États distincts de chargement, chargement additionnel, erreur avec nouvelle tentative, historique vide et aucun résultat.
- Requêtes annulables et protégées contre les réponses concurrentes obsolètes.
- Interface responsive, cibles tactiles de 44 px, `aria-busy`, annonces vivantes, états `aria-pressed`, focus visible et animations neutralisées avec `prefers-reduced-motion`.
- Le formulaire CRM existant est conservé sans dupliquer son ancien flux; une nouvelle activité déclenche le rafraîchissement de la chronologie.

## Contrat backend

Routes :

```text
GET /customer/{customer}/activity        customer.activity_index
GET /api/v1/customer/{customer}/activity api.customer.activity_index
```

Paramètres normalisés :

```text
period = last_7_days|last_30_days|last_90_days|last_6_months|current_year|previous_year|all|custom
from / to
types[] = appointments|invoices|payments|notes|communications|profile_changes
cursor / per_page
```

Réponse :

```json
{
  "data": [
    {
      "id": "reservation:123",
      "occurred_at": "2026-08-21T14:30:00Z",
      "type": "appointments",
      "status": "completed",
      "title": "Appointment completed",
      "description": "Coupe signature · Nadia",
      "amount": null,
      "resource": {
        "type": "reservation",
        "id": 123,
        "href": "/reservation?reservation_id=123"
      },
      "icon_key": "calendar-check",
      "actor": {
        "id": 45,
        "name": "Nadia"
      },
      "metadata": {}
    }
  ],
  "meta": {
    "period": "last_90_days",
    "from": "2026-05-24",
    "to": null,
    "types": [],
    "available_types": ["appointments", "invoices", "payments", "notes", "communications", "profile_changes"],
    "timezone": "America/Toronto",
    "per_page": 20,
    "has_more": false,
    "next_cursor": null
  },
  "links": {
    "next": null
  }
}
```

Le curseur est chiffré et encode la clé stable `(occurred_at, source, id)`. Un curseur invalide ou altéré renvoie une erreur 422. Les périodes personnalisées utilisent une borne basse incluse et une borne haute locale exclusive après conversion UTC, y compris lors des changements d'heure. Les présélections glissantes gardent les rendez-vous à venir visibles.

## Sources et règles de calcul

- Rendez-vous : date de début, ou date d'annulation lorsqu'elle existe; statuts actifs issus du modèle Réservation.
- Factures : date de création; factures supprimées exclues.
- Paiements : `paid_at`, sinon date de création; paiements en attente, réglés, échoués, remboursés et renversés distingués; remboursements et renversements affichés en négatif.
- Notes et communications CRM : `ActivityLog` du client, de ses demandes et de ses devis reliés.
- Campagnes : événements directs de campagne, datés avec `occurred_at`.
- Profil : création, mise à jour, tags, préférences d'auto-validation, VIP, accès portail, archivage et restauration.
- Tous les montants sont limités à la devise du compte.

## Sécurité et autorisations

- Le client doit appartenir au compte effectif; les routes web et API refusent un client d'un autre locataire.
- Chaque requête source conserve le scope du compte et du client. Les campagnes reliées, factures supprimées, devises étrangères et paiements attachés à une facture étrangère sont exclus.
- Les factures et paiements ne sont ni chargés ni projetés lorsque `InvoicePolicy::viewAny` refuse l'acteur, y compris dans le payload historique de compatibilité et le résumé de facturation de la fiche.
- Un employé limité à ses rendez-vous ne voit que les réservations associées à son `team_member_id`. Les rôles administrateur, `reservations.manage` ou `view_all_reservations` obtiennent la portée complète.
- Les notes et communications CRM exigent le propriétaire, un administrateur, `sales.manage` ou `sales.pos`; les campagnes restent soumises à `CampaignPolicy`.
- La création d'une activité CRM utilise désormais `CustomerPolicy::logActivity` côté interface et endpoint. Un lecteur seul reçoit 403.
- Le frontend ne reconstruit aucun lien ni montant : il rend uniquement les champs fournis par le serveur et n'accepte que les chemins internes relatifs.

## Journalisation fiabilisée

`CustomerActivityAudit` capture un instantané avant/après limité aux champs métier autorisés, calcule le diff réel et ne crée aucun journal lors d'une opération sans changement.

Sont couverts :

- mise à jour du profil;
- notes et tags;
- préférences d'auto-validation;
- statut et niveau VIP, manuels ou automatisés;
- accès portail en masse;
- archivage et restauration en masse.

Les actions en masse journalisent chaque client dans la transaction avec `source=bulk`. L'automatisation VIP conserve son contexte avec `source=automation`.

## Compatibilité

- La prop historique `activity` reste exposée par `CustomerController::show`.
- Les contrats CRM de messages, réunions, prochaines actions, demandes et devis restent inchangés.
- `SalesActivityPanel` conserve son formulaire et son flux lorsqu'il est utilisé ailleurs; la fiche Client masque seulement son doublon visuel avec `showFeed=false`.
- Les routes web et API partagent la même validation, les mêmes politiques et le même builder.

## Fichiers structurants

### Backend

- `app/Http/Requests/CustomerActivityRequest.php`
- `app/Queries/Customers/BuildCustomerTimelineData.php`
- `app/Queries/Customers/BuildCustomerDetailViewData.php`
- `app/Services/Customers/CustomerActivityAudit.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Policies/CustomerPolicy.php`
- `app/Http/Controllers/SalesActivityController.php`
- `app/Services/Campaigns/VipService.php`
- `routes/web.php` et `routes/api.php`

### Frontend

- `resources/js/Components/Customer/CustomerHistoryTimeline.vue`
- `resources/js/utils/customerActivity.js`
- `resources/js/Pages/Customer/Show.vue`
- `resources/js/Components/CRM/SalesActivityPanel.vue`
- traductions Clients françaises, anglaises et espagnoles

### Tests

- `tests/Feature/CustomerHistoryExperienceTest.php`
- `tests/Node/CustomerHistoryExperienceTest.mjs`
- suites de régression Clients, CRM, campagnes, forfaits, événements et API existantes

## Validation

- Feature dédié étape 3 sous SQLite en mémoire : 9 tests, 86 assertions réussies.
- Régressions API, actions en masse, Clients, forfaits, CRM et événements : 49 tests, 752 assertions réussies.
- Régression Campagnes/VIP isolée avec 512 Mo : 68 tests, 518 assertions réussies.
- Régressions index Clients des étapes précédentes : 24 tests, 286 assertions réussies.
- Régressions CRM détaillées : 21 tests, 447 assertions réussies.
- Node complet : 104 tests réussis.
- Build Vite de production : réussi, 2 634 modules transformés.
- PHPStan : réussi, aucune erreur sur 893 fichiers.
- Gate PHP obligatoire `composer qa:format` : réussi sur tous les fichiers PHP indexés de la livraison.
- `git diff --check` et index documentaire : réussis.

La vérification visuelle interactive desktop/mobile n'a pas pu être exécutée dans le navigateur intégré, car son outil de contrôle n'était pas exposé dans cette session. Le build, les contrats responsive/accessibilité et les tests automatisés sont verts; cette limite d'environnement est consignée sans être présentée comme une validation visuelle.

## Décision de clôture

L'étape 3 est close. Le chantier en trois étapes du module Clients est terminé : audit et contrat, index opérationnel, puis fiche Client avec historique unifié.
