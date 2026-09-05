# Registre initial des risques - coherence des modules

- Date : 2026-08-07
- Portee : lecture statique preparatoire au runbook global
- Statut : constats a verifier, pas audit complet des 21 modules

Ce document conserve les ecarts reperes pendant la preparation du [runbook d'audit global](../../MODULE_VISIBILITY_AUDIT_RUNBOOK.md). Un constat reste `A verifier` tant qu'un test actif/inactif n'a pas confirme son comportement. Il ne doit pas etre interprete comme une correction deja livree.

| Priorite | Modules | Surface a verifier | Risque observe | Prochaine preuve attendue | Statut |
| --- | --- | --- | --- | --- | --- |
| Haute | `requests`, `quotes`, `jobs`, `tasks`, `invoices` | `PipelineController` et routes `/pipeline*` | Les routes ne semblent pas avoir de garde module ; une URL directe pourrait encore interroger un module desactive. | Tests HTTP par module desactive, puis garde serveur explicite. | A verifier |
| Haute | `sales`, `quotes`, `jobs`, `tasks`, `invoices` | Portail client : commandes, devis, chantiers, taches, factures | Plusieurs routes du portail paraissent hors des groupes `company.feature` et aucun controle equivalent n'a ete identifie pendant la lecture initiale. | Definir les exceptions archivales, puis tester le portail avec donnees historiques et modules desactives. | A verifier |
| Haute | `invoices`, `sales` | Endpoints de paiement facture/vente | Les endpoints paraissent hors des groupes modules ; une permission seule ne doit pas reactiver le paiement. | Tester directement `mark-paid`, Stripe portail et paiement portail avec module actif puis inactif. | A verifier |
| Moyenne | `assistant` | Routes Web et API `/ai/images` | Les routes ne semblent pas protegees par `company.feature:assistant`. | Tests HTTP Web/API module inactif et verification des quotas/effets de bord. | A verifier |
| Haute | `quotes`, `requests`, `jobs` | `database/seeders/LaunchSeeder.php` | Des donnees de lancement salon et restaurant semblent reactiver explicitement des modules terrain desactives par les defaults sectoriels. | Comparer les features effectives apres seed pour salon et restaurant. | A verifier |
| Moyenne | ensemble du registre, notamment `social` | `DemoWorkspaceCatalog` | Le registre central traite salon et restaurant ensemble ; le catalogue ajoute les modules de reservation a salon, wellness et restaurant, mais ne retire les modules terrain que pour salon. La cle `social` est aussi absente du catalogue lu lors de l'audit. | Tester l'egalite des cles et les defaults salon/wellness/restaurant. | A verifier |
| Moyenne | `promotions` | Defaults des plans | La cle ne semble pas declaree explicitement dans tous les `default_modules` et peut devenir active par fallback. | Rendre le choix explicite et tester chaque plan. | A verifier |
| Haute | `products`, `sales`, `services`, `assistant`, `quotes`, `jobs`, `invoices`, `tasks` | Vues publiques et liens signes | Plusieurs parcours publics ou signes doivent definir s'ils restent accessibles lorsque le module est desactive : boutique/checkout, showcase, chat IA, devis, chantier, facture et medias de tache. | Documenter la politique public/archivale de chaque parcours, puis tester lecture et mutation. | A verifier |
| Haute | `products`, `requests`, `quotes` | API integrations : inventaire et evenements CRM | Certaines routes d'integration semblent s'appuyer sur les abilities de token sans garde du module correspondant. | Tests API avec token valide et module inactif ; refuser les mutations hors politique explicite. | A verifier |
| Moyenne | ensemble du registre | Endpoints administrateurs des features | Des payloads `features.*` peuvent accepter des cles inconnues si aucune whitelist serveur n'est appliquee. | Tester une cle orpheline et valider contre le registre canonique. | A verifier |

## Regle de traitement

Pour fermer une ligne :

1. reproduire le comportement avec un compte ou le module est actif ;
2. reproduire avec le module inactif et des donnees historiques ;
3. corriger la source de verite, le serveur et l'interface selon le besoin ;
4. ajouter les tests owner, membre d'equipe ou client concernes ;
5. inscrire le commit, le test ou le ticket dans ce registre ;
6. passer le statut a `Conforme` uniquement lorsque les preuves sont vertes.

Les corrections produit ne doivent pas etre regroupees aveuglement : chaque ligne peut toucher des permissions, des donnees existantes ou des parcours de paiement differents et doit etre livree avec une portee verifiable.
