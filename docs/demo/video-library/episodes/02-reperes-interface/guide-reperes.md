# G02 — Guide des repères et conditions d'affichage

Dernière mise à jour : 2026-08-11

## Tableau de bord

La route commune est `/dashboard`, mais le composant rendu varie selon le type d'entreprise et le rôle : propriétaire de services, membre, propriétaire de produits, vendeur ou client portail. Les cartes suivent ensuite les fonctions et permissions disponibles.

Règle de narration : décrire ce qui est visible sur la prise. Ne pas promettre qu'un indicateur, une activité ou un panneau existe pour tous les comptes.

## En-tête

| Élément | Condition principale | Action |
| --- | --- | --- |
| Recherche globale | Utilisateur interne, pas client portail | Ouvre la palette. |
| Messages | Espace de travail interne, pas navigation plateforme, pas client | Affiche les alertes d'usage. |
| Notifications | Données de notification disponibles | Ouvre les notifications. |
| Réglages | Propriétaire, superadmin ou admin plateforme autorisé | Ouvre les réglages adaptés au contexte. |
| Compte | Utilisateur connecté | Menu de profil et session. |
| Menu mobile | Petit viewport | Ouvre/ferme la barre latérale. |

## Hubs de l'espace de travail

| Hub | Exemples de modules | Salon Éclat propriétaire |
| --- | --- | --- |
| Revenus | Clients, demandes, devis, ventes | Clients ; les entrées non activées restent absentes. |
| Croissance | Promotions, campagnes, social, fidélité, performance | Visible selon permissions du propriétaire. |
| Opérations | Réservations, planning, présence, équipe, jobs, tâches | Réservations, présence, équipe ; Jobs/Tâches exclus du preset. |
| Finance | Factures, dépenses, comptabilité, approbations, pourboires | Modules financiers du preset et droits propriétaire. |
| Catalogue | Prestations, catégories, produits, forfaits | Prestations, catégories, produits, forfaits. |
| Espace de travail | Entreprise, facturation, rôles, profil | Réglages propriétaire et profil. |

Un hub n'est visible que s'il contient au moins un module visible. La visibilité d'un module combine type d'entreprise, fonction active, rôle et permission.

## Recherche globale

| Comportement | Valeur réelle |
| --- | --- |
| Ouverture | Bouton ou `Ctrl/Cmd + K` |
| Fermeture | `Esc` ou clic sur l'arrière-plan |
| Seuil | 2 caractères |
| Délai après saisie | 250 ms |
| Groupes prévus | Clients, demandes, tâches, devis, employés |
| Client portail | Recherche masquée |
| Fermeture | Vide requête, résultats et erreur |

Une réponse réseau en erreur et une recherche sans résultat utilisent actuellement le même libellé français « Aucun résultat ». La vidéo ne doit donc pas diagnostiquer la cause uniquement à partir de ce texte.

## Actions rapides

| Action | Conditions simplifiées | Salon Éclat propriétaire |
| --- | --- | --- |
| Client | Propriétaire services, ou entreprise produits avec ventes autorisées | Oui |
| Prestation | Services actif, entreprise services, propriétaire | Oui |
| Produit | Produits actif, propriétaire | Oui |
| Demande | Demandes actif et gestion commerciale autorisée | Non dans ce preset |
| Devis | Devis actif, entreprise services, propriétaire | Non dans ce preset |

Les clients portail et les vendeurs ne reçoivent pas cette liste d'actions rapides. Les formulaires compacts ne montrent pas nécessairement toutes les options des pages complètes.

## Sources de vérité

- `resources/js/Layouts/UI/Sidebar.vue`
- `resources/js/Layouts/UI/Header.vue`
- `resources/js/Components/UI/GlobalSearch.vue`
- `resources/js/Components/QuickCreate/QuickCreateModals.vue`
- `resources/js/utils/workspaceHub.js`
- `app/Http/Controllers/DashboardController.php`
- `resources/js/i18n/modules/fr/global_search.json`
- `resources/js/i18n/modules/fr/quick_create.json`
