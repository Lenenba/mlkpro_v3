# Audit de la couleur primaire par module

Dernière mise à jour : 2026-08-18

Statut : plan complet, certification exhaustive en cours

## 1. Objectif

Ce plan prolonge le socle de personnalisation déjà livré. Son objectif est de certifier, un module à la fois, que toutes les couleurs de marque suivent réellement la couleur principale de l'entreprise.

Il répond au problème suivant : une page peut déjà contenir un bouton `bg-primary` tout en conservant ailleurs des boutons, focus, onglets ou liens actifs codés en `green-*` ou `emerald-*`.

La règle d'exécution est stricte : **un seul module est en cours et le module suivant ne commence pas avant que le précédent soit déclaré conforme avec ses preuves**.

## 2. État initial

Le moteur de thème, le stockage, le contraste, l'isolation tenant, les courriels et les PDF sont en place. La consommation frontend reste partielle.

La baseline canonique produite par la garde M00 sur `develop` indique :

- 4 914 occurrences candidates dans le scan large, réparties sur 1 906 lignes et 259 fichiers;
- 1 664 occurrences à risque fort, réparties sur 893 lignes et 163 fichiers;
- 4 664 tokens Tailwind `green-*` ou `emerald-*` dans `resources/js`, répartis sur 242 fichiers;
- environ 236 signatures de CTA vert avec survol dans 104 fichiers;
- 456 lignes contenant encore un focus vert;
- un helper transversal, `resources/js/utils/crmButtonStyles.js`, qui propage encore des styles primaires verts à plusieurs modules.

Ces nombres mesurent des candidats, pas uniquement des défauts. Ils incluent aussi les statuts de succès, les factures payées, les disponibilités, les graphiques, les marques externes et les surfaces plateforme.

Le détail de référence est conservé dans [l'état initial](audits/company-primary-color/2026-08-18-etat-initial.md).

## 3. Taxonomie obligatoire

Chaque occurrence colorée rencontrée doit recevoir exactement une classification :

| Classe | Traitement |
| --- | --- |
| `marque` | Remplacer par un token `primary` approprié. |
| `statut` | Conserver une couleur fonctionnelle stable et documenter la raison : succès, payé, terminé, approuvé, disponible ou tendance positive. |
| `graphique` | Conserver une palette de données distincte lorsque la couleur identifie une série ou une personne. |
| `marque_externe` | Conserver la couleur officielle d'un service comme WhatsApp. |
| `palette_specifique` | Conserver une palette autonome explicitement prévue : campagne, CMS, démonstration ou en-tête de boutique. |
| `plateforme` | Garder Malikia Pro et vérifier qu'aucune couleur tenant ne fuit. |
| `code_dormant` | Prouver l'absence d'utilisation avant nettoyage ou exclusion documentée. |

Aucune occurrence ne peut rester `inconnue` lorsqu'un module passe à l'état conforme.

Règle d'interprétation : un compte **actif** est un statut métier; un onglet **actif**, un filtre sélectionné ou une pagination courante est un état de marque et doit suivre la couleur de l'entreprise.

## 4. Correspondance des tokens

Les usages de marque doivent employer les tokens sémantiques existants :

| Usage | Tokens attendus |
| --- | --- |
| CTA rempli | `bg-primary`, `text-primary-foreground`, `hover:bg-primary-hover` |
| Focus | `ring-primary-line`, `border-primary-line` ou variante de focus prévue |
| Lien ou icône sur fond neutre | `text-primary-readable` |
| Sélection douce | `bg-primary-soft`, `text-primary-soft-foreground`, `border-primary-line` |
| Case cochée | `text-primary-checked` |

`text-primary` ne doit pas être utilisé directement sur un fond blanc ou sombre sans garantie de contraste.

Une phase ultérieure pourra ajouter des tokens explicites `success`, `warning`, `danger` et `info` afin de rendre la séparation entre marque et statut encore plus évidente.

## 5. États de suivi

Les seuls états autorisés sont :

1. `À auditer`;
2. `Dette identifiée`;
3. `En correction`;
4. `À valider`;
5. `Conforme`;
6. `Bloqué`, avec cause et décision attendue.

Un module ne peut pas revenir silencieusement de `Conforme` vers un état antérieur. Toute réouverture doit être expliquée dans le plan et dans son rapport de preuve.

## 6. Définition de « module conforme »

Avant de commencer le module suivant, toutes les conditions ci-dessous doivent être satisfaites :

- toutes les routes, pages, composants réellement importés, modales, menus, Quick Create, portail, public, courriels et PDF du module sont inventoriés;
- chaque occurrence candidate est classée selon la taxonomie;
- aucun CTA, focus, checkbox, onglet, lien actif, pagination, sélection ou décor de marque ne reste codé en vert;
- chaque vert conservé figure dans une exception précise avec son chemin, ses tokens, son nombre attendu et une raison fermée;
- les exceptions inutilisées et les nouveaux verts de marque font échouer le contrôle automatique;
- une entreprise violette vérifie la couleur calculée d'un vrai contrôle interactif;
- une couleur claire vérifie automatiquement l'utilisation d'un texte sombre lisible;
- au moins un statut positif reste vert sous un thème violet lorsque le module possède un tel statut;
- le mode clair, le mode sombre, le format ordinateur et le format mobile sont contrôlés;
- les profils owner, employé, client, public et impersonation sont testés lorsqu'ils utilisent le module;
- les tests ciblés, la suite Node, le build, les budgets frontend et le scénario Playwright du module sont verts;
- les tests Feature sont étendus si le module construit lui-même le payload entreprise, recharge un owner partiellement, ou rend un courriel, PDF ou endpoint API;
- le rapport de preuve, les nombres résiduels, les commandes exécutées et le commit sont inscrits avant changement de statut.

## 7. Ordre séquentiel de certification

Situation après certification de M00 :

- fondation technique de couleur : livrée;
- modules certifiés exhaustivement : `1 / 36`;
- prochain et unique lot autorisé : `M01`.

| ID | Module ou lot | Périmètre principal | État initial |
| --- | --- | --- | --- |
| M00 | Taxonomie et garde automatique | manifeste, scanner, allowlist, rapport et CI | Conforme — garde stricte et preuves M00 validées |
| M01 | Composants partagés | formulaires, boutons, DataTable, pagination, onglets, modales, Quick Create, helpers dont `crmButtonStyles.js` | Dette identifiée — prochain lot |
| M02 | Shell et navigation | sidebar, header, recherche, notifications, assistant global, hubs et erreur de module | À auditer |
| M03 | Tableaux de bord | sept variantes `Dashboard*.vue` et composants Dashboard | À auditer |
| M04 | Clients | pages Customer, composants Customer, création rapide et activité CRM | À auditer |
| M05 | Services et catégories | pages Service, formulaires rapides, catégories et vitrine tenant | À auditer |
| M06 | Produits et inventaire | pages Product, cartes, tableaux, stock, entrepôts et création rapide | À auditer |
| M07 | Prospects et demandes | pages Request, composants Prospects, création rapide et formulaire public | À auditer |
| M08 | Demandes de service, CRM et pipeline | ServiceRequests, CRM, composants CRM et Pipeline | À auditer |
| M09 | Forfaits et offres | OfferPackages, forfaits client et portail Packages | À auditer |
| M10 | Analyse de plans | PlanScan, création, résultat, tableau et dépendances devis/catalogue | À auditer |
| M11 | Devis | Quote, Quick Create, action publique, portail et courriel | À auditer; quelques surfaces déjà migrées |
| M12 | Travaux | Work, listes, actions et preuves publiques, portail | À auditer; quelques surfaces déjà migrées |
| M13 | Tâches | Task, preuves, téléversements et calendrier lié | À auditer |
| M14 | Ventes, commandes et boutique | Sales, Orders, Store, portail Products, reçu et PDF commande | À auditer; boutique partiellement migrée |
| M15 | Réservations | pages et composants Reservation, réglages, réservation publique, borne et client | À auditer; public partiellement migré |
| M16 | Planning | planning, calendrier, sélection et actions associées | À auditer |
| M17 | Présences | pointage, filtres, cartes et statuts | À auditer |
| M18 | Équipe et RH | Team, réglages RH, rôles associés et invitations | À auditer |
| M19 | Performance | index, fiche employé, KPI et tendances | À auditer |
| M20 | Factures et paiements | Invoice, portail, paiement public, courriel et trois PDF | À auditer; canaux externes déjà migrés |
| M21 | Pourboires | vues owner et membre, filtres et tendances | À auditer |
| M22 | Dépenses | Expense, caisse, scan, workflow et tableaux | À auditer |
| M23 | Approbations financières | inbox, actions, filtres et notifications | À auditer |
| M24 | Comptabilité | dashboard, tableaux, exports et états financiers | À auditer |
| M25 | Fidélité | interne, paramètres et portail | À auditer; portail partiellement migré |
| M26 | Promotions | liste, création, sélection et statuts | À auditer |
| M27 | Campagnes marketing | parcours campagne puis modèles, segments, listes, VIP et fournisseurs | À auditer; palette marketing autonome à préserver |
| M28 | Réseaux sociaux | composition, calendrier, médias, comptes, automatisations et approbations | À auditer |
| M29 | Assistant IA | assistant global, réglages, connaissances, conversations et chat public | À auditer |
| M30 | Paramètres transversaux | entreprise, facturation, profil, sécurité, rôles, notifications et support | À auditer; sélecteur couleur déjà livré |
| M31 | Portail client transversal | shell et dashboard client, navigation et croisements entre modules | À auditer; CTA prioritaires partiellement migrés |
| M32 | Onboarding et authentification | onboarding et auth contextualisée; auth générique reste plateforme | À auditer |
| M33 | Pages publiques génériques | CMS public, header public, composants publics et cookie banner selon contexte | À auditer |
| M34 | Courriels et PDF | régression tenant, plateforme, campagnes, démos et cinq PDF | Validé initialement; revalidation finale requise |
| M35 | Plateforme, isolation et code dormant | SuperAdmin, marketing, légal, impersonation, changement de tenant et graphiques non importés | À auditer en dernier |

Les réglages propres à un module sont validés avec ce module. Le lot M30 ne les recommence pas; il couvre les réglages véritablement transversaux.

## 8. Registre de travail d'un module

Chaque rapport de module doit être placé dans `docs/audits/company-primary-color/` et contenir au minimum :

```text
Module :
Statut avant / après :
Routes et profils couverts :
Fichiers et composants réellement utilisés :
Nombre initial de candidats :
Marque migrée :
Exceptions statut :
Exceptions graphique / externe / spécifique :
Occurrences inconnues restantes : 0
Preuves clair / sombre / mobile / ordinateur :
Tests et résultats :
Commit :
Décision de passage au module suivant :
```

## 9. Garde automatique prévue dans M00

Le premier lot doit créer :

- `config/company-branding-color-audit.json` : modules, chemins, statuts, exceptions et preuves attendues;
- `scripts/check-company-branding-colors.mjs` : scan `--module`, sortie JSON et mode `--strict`;
- `tests/Node/CompanyBrandingColorAuditTest.mjs` : validation du manifeste, détection CTA/focus, exceptions orphelines et couverture de tous les fichiers Vue;
- `tests/e2e/helpers/companyBranding.mjs` : assertions de couleur, contraste, hover, focus et statut;
- `tests/e2e/tenant-branding-modules.spec.js` : scénarios progressifs pilotés par module;
- une commande npm `qa:branding-colors` et son exécution dans la CI.

Pour les modules non encore traités, le scanner conserve une baseline et interdit que la dette augmente. Pour un module `Conforme`, tout vert doit être une exception exacte; aucune allowlist par dossier entier n'est autorisée.

## 10. Matrice de validation visuelle

Chaque module doit utiliser au minimum :

| Cas | Attendu |
| --- | --- |
| Tenant violet `#7C3AED` | CTA, focus et sélection deviennent violets. |
| Tenant clair `#FDE047` | Le premier plan devient sombre et conserve un contraste d'au moins 4,5:1. |
| Tenant sans couleur | Fallback Malikia Pro `#16A34A`. |
| Statut positif sous tenant violet | Le statut reste vert. |
| Erreur et avertissement | Rouge et ambre restent fonctionnels. |
| Mode sombre | Palette sombre lisible et focus visible. |
| Navigation entre deux tenants | Aucune couleur du premier tenant ne subsiste. |
| Superadmin hors impersonation | Palette Malikia Pro. |
| Superadmin en impersonation | Palette du tenant impersonné. |

## 11. Commandes de sortie prévues

À la fermeture de chaque module :

```bash
npm run qa:branding-colors -- --module=<clé> --strict
node --test tests/Node/CompanyBrandingColorAuditTest.mjs
npm run qa:node
npm run build
npm run qa:frontend-budgets
npx playwright test tests/e2e/tenant-branding-modules.spec.js --grep <clé>
npm run docs:index
npm run docs:index:check
git diff --check
```

La suite Playwright complète est exigée avant chaque push. Si un fichier PHP est modifié, tous les PHP du lot doivent être indexés puis `composer qa:format` doit être vert conformément aux règles du dépôt.

## 12. Stratégie de livraison

- un commit isolé par module conforme;
- aucun commit « migration globale des verts »;
- mise à jour du tracker et du rapport de preuve dans le même commit;
- push uniquement après les gates locaux;
- CI distante entièrement verte avant ouverture du module suivant;
- retour arrière possible module par module.

## 13. Exclusions intentionnelles

Les éléments suivants ne doivent pas être écrasés par la couleur entreprise :

- succès, payé, terminé, approuvé, disponible et tendance positive;
- erreurs, avertissements et informations;
- séries de graphiques et couleurs d'employés au calendrier;
- marques externes;
- palette `BrandProfile` des campagnes;
- palette d'un workspace de démonstration;
- palette éditoriale CMS;
- `company_store_settings.header_color`, prioritaire uniquement sur la surface boutique;
- surfaces plateforme, marketing et authentification générique hors impersonation.

## 14. Estimation

La couverture complète représente environ 15 à 22 jours de développement et de QA, selon le nombre d'exceptions réelles et de composants dormants. L'avancement sera mesuré par modules `Conforme`, et non par le nombre brut de remplacements effectués.
