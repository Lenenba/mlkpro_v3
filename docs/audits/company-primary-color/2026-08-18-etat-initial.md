# Couleur primaire entreprise — état initial de la couverture

Date de l'audit : 2026-08-18

Branche et référence : `develop` à partir du commit `49ba71ef`

Statut : état initial complet, remédiation en cours

## Résumé

Le socle technique est fonctionnel et validé : stockage, résolution du propriétaire, palette accessible, cycle Inertia, pages prioritaires, courriels et PDF. La migration visuelle n'est toutefois pas exhaustive dans les modules.

La première estimation manuelle comptait 4 839 occurrences. La garde M00 fournit désormais la baseline canonique, reproductible et plus large (`green`, `emerald`, `lime`, `teal` et hexadécimaux Malikia Pro) :

- 4 914 occurrences candidates;
- 1 664 occurrences à risque fort;
- 1 906 lignes concernées, dont 893 à risque fort;
- 259 fichiers concernés, dont 163 à risque fort;
- environ 236 signatures de CTA vert avec survol dans 104 fichiers;
- 456 lignes de focus vert.

Le scan étroit des seuls tokens Tailwind `green-*` et `emerald-*` dans `resources/js` relève 4 664 occurrences dans 242 fichiers.

Ces valeurs servent de baseline. Elles ne représentent pas 4 914 anomalies : chaque occurrence doit être classée avant correction ou conservation.

## Répartition initiale

Les volumes indiquent des lignes candidates et des fichiers distincts du scan large.

| Périmètre | Volume | Priorité | Observation |
| --- | ---: | --- | --- |
| Socle partagé | 440 / 63 | P0 | Formulaires, DataTable, pagination, Quick Create et helpers propagent encore du vert de marque. |
| Public et boutique | 71 / 15 | P0 | Header, panier, cartes, focus et sélections restent à certifier. |
| Tableaux de bord et hub | 116 / 14 | P1 | Distinguer décor de marque et tendances positives. |
| CRM, clients et demandes | 206 / 24 | P1 | Plusieurs CTA, liens, focus et sélections sont encore verts. |
| Catalogue | 96 / 10 | P1 | Actions de création/édition à migrer; stock et disponibilité à conserver. |
| Ventes et finance | 154 / 22 | P1 | Actions à migrer; payé, approuvé et succès à conserver. |
| Exécution et RH | 146 / 14 | P1 | CTA et onglets à migrer; terminé et calendrier à conserver. |
| Réservations | 79 / 7 | P1 | Onglets, actions, sélection et focus restent à certifier. |
| Paramètres, onboarding et support | 148 / 16 | P1 | Enregistrement, toggles, focus et cartes sélectionnées. |
| Assistant IA | 43 / 6 | P1 | Lanceur, envoi, bulles et champs de marque. |
| Campagnes et social | 140 / 29 | P2 | Préserver les palettes éditoriales et les succès métier. |
| Portail client | 36 / 6 | P1 | CTA prioritaires migrés, couverture exhaustive encore ouverte. |
| Plateforme hors tenant | 196 / 29 | Exclu tenant | Doit rester Malikia Pro et ne jamais recevoir la couleur tenant. |
| Autre | 1 / 1 | À qualifier | `ModuleUnavailable` doit être classé plateforme ou tenant. |

## Dette de marque certaine

Exemples confirmés lors de l'audit :

- `resources/js/utils/crmButtonStyles.js` conserve le bouton primaire et les segments actifs en vert;
- `resources/js/Components/QuickCreate/ServiceQuickForm.vue` contient encore des actions de marque vertes;
- `resources/js/Components/QuickCreate/RequestQuickForm.vue` conserve un CTA vert;
- `resources/js/Components/UI/QuoteList.vue` conserve un bouton principal vert;
- `resources/js/Components/UI/TimePicker.vue`, la pagination et `SelectableItem.vue` gardent des focus ou sélections verts;
- `resources/js/Pages/Customer/Show.vue` contient plusieurs CTA verts;
- `resources/js/Pages/Request/Show.vue` contient plusieurs actions vertes;
- `resources/js/Pages/Invoice/Show.vue` conserve des actions vertes;
- `resources/js/Pages/Reservation/Index.vue` conserve des onglets et CTA verts;
- `resources/js/Components/AiAssistant/PublicChatWidget.vue` et l'assistant global gardent des contrôles verts;
- `resources/js/Components/Public/PublicSiteHeader.vue` et `resources/js/Components/Store/ProductCard.vue` contiennent encore des couleurs de marque fixes.

Le helper `crmButtonStyles.js` est prioritaire parce qu'il alimente Customer, Request, Quote, CRM, Reservation, ServiceRequests, Work, OfferPackages, Notifications et certains DataTable.

## Verts sémantiques confirmés

Ces exemples doivent rester fonctionnellement verts :

- facture payée ou approuvée;
- réservation confirmée ou créneau disponible;
- preuve de travail terminée;
- notification de succès;
- acquisition ou points de fidélité positifs;
- étape de campagne terminée;
- utilisateur connecté, stock disponible et validation réussie;
- tendance positive;
- séries vertes dans une palette de graphique ou d'employés.

L'étape **courante** d'une campagne suit la marque; une étape **terminée** reste un succès. Cette distinction s'applique aussi au mot `actif` selon qu'il décrit un statut métier ou une sélection d'interface.

## Limites des tests actuels

- `tests/Node/CompanyBrandingTest.mjs` vérifie le moteur et la présence d'au moins un token primaire dans seulement dix composants partagés et treize pages prioritaires;
- ce test ne garantit pas l'absence d'un second bouton vert dans la même page;
- `tests/e2e/tenant-branding.spec.js` vérifie la couleur calculée d'un vrai CTA uniquement dans le formulaire public;
- les parcours workspace, Store et Showcase contrôlent principalement la variable CSS racine;
- aucun gate ne rattache encore chaque page Vue à un module ou à une exclusion.

## Code potentiellement dormant

Environ 205 lignes candidates proviennent de `Barchart.vue`, `LineChart.vue` et `Areachart.vue`, sans import explicite trouvé pendant l'audit. Elles doivent être prouvées inutilisées avant suppression ou classées dans le module qui les consomme réellement.

Les lignes de `MegaMenuBlockPayloadEditor.vue` appartiennent à l'éditeur SuperAdmin et relèvent de la plateforme, pas de la couleur tenant.

## Décision

La remédiation suivra le plan [Audit de la couleur primaire par module](../../PLAN_AUDIT_COULEUR_PRIMAIRE_PAR_MODULE_2026-08-18.md). Aucun module ne sera déclaré conforme tant qu'il conserve une occurrence non classée ou qu'un vrai contrôle interactif n'a pas été vérifié en navigateur.
