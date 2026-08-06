# Runbook de validation — Phase 1

- Dernière mise à jour : 2026-08-04
- Statut : **prêt pour validation humaine**
- Responsable de décision : Jules Roger Sombangnen
- Périmètre : P1-003, P1-004 et P1-005 ; P1-001 et P1-002 sont déjà acceptés
- Référence d’état : [suivi global](SUIVI_GLOBAL.md)

## But de ce document

Ce guide permet de vérifier les trois derniers tickets de la Phase 1 sans confondre une preuve locale avec une mesure d’exploitation.

Il fournit :

1. les rejoues automatiques à lancer ;
2. les contrôles visuels et fonctionnels à effectuer dans le navigateur ;
3. les critères d’acceptation, de refus et de rollback ;
4. le texte de décision à consigner après revue.

Une case cochée ne vaut que pour le commit réellement testé et les preuves associées. En cas d’écart, arrêter la validation du ticket concerné, noter le défaut et ne pas compenser par une dérogation implicite.

## Limites non négociables

- Les preuves P0-005 et P0-006 restent reportées par MLK-DEC-010 jusqu’au 2027-08-04.
- Ce runbook ne valide ni des workers en exploitation, ni p50/p95/p99, ni LCP, INP ou CLS représentatifs.
- Aucun test de charge, aucun canari opérationnel, aucune écriture ni changement de configuration en production n’est autorisé par ce document.
- Les validations ci-dessous sont locales. Un test sur staging ou production exige une autorisation distincte, explicite, datée et bornée.

## 1. Préparer une revue reproductible

1. Ouvrir un terminal dans le dépôt et vérifier que la branche de travail est bien develop.

   ~~~powershell
   git branch --show-current
   git status --short
   git log -1 --oneline
   ~~~

   Résultat attendu : develop est affichée. Le statut doit être vide avant une revue de référence. Si un travail personnel apparaît, ne pas le mélanger à cette validation.

2. Consigner le SHA affiché. Les preuves existantes ont été établies sur les commits techniques suivants :

   | Ticket | Commit technique | État technique connu |
   |---|---|---|
   | P1-003 — traductions par domaine | a27fdea4 | validation locale réussie |
   | P1-004 — médias et polices critiques | 4fa1ac3f | validation locale réussie |
   | P1-005 — budgets frontend | 2ff77eea | validation locale et CI distante réussies |

3. Utiliser l’environnement E2E isolé plutôt que la base définie dans le fichier .env habituel. Si les dépendances ne sont pas déjà installées, les installer une seule fois.

   ~~~powershell
   composer install
   npm ci
   ~~~

4. Ne pas démarrer le serveur manuel avant les tests Playwright de la section 2 : Playwright démarre son propre serveur E2E sur le port 38103 et ne réutilise pas un serveur existant. Après les contrôles automatiques, démarrer le serveur isolé dans un terminal dédié pour la revue humaine.

   ~~~powershell
   npm run qa:build
   node scripts/playwright-webserver.mjs
   ~~~

   Ce serveur force APP_ENV=e2e et utilise uniquement storage/framework/testing/e2e.sqlite. Il exécute migrate:fresh avec E2ESmokeSeeder sur cette base isolée, puis sert l’application sur http://127.0.0.1:38103. Ne jamais lancer migrate:fresh avec le .env habituel pour cette recette.

   Laisser ce terminal ouvert pendant les contrôles manuels, puis l’arrêter avec Ctrl+C. L’arrêter également avant toute commande npx playwright test ou npm run qa:e2e ultérieure.

5. Ouvrir l’application dans un profil de navigateur neuf ou privé. Ouvrir aussi les outils de développement avec les onglets Console et Network visibles. Cocher Disable cache pour les vérifications de chargement, puis recharger la page.

   | Surface E2E | URL ou identifiant sûr |
   |---|---|
   | Accueil | http://127.0.0.1:38103/ |
   | Connexion | http://127.0.0.1:38103/login |
   | Compte owner | e2e.service.owner@example.test / password |
   | Boutique | http://127.0.0.1:38103/store/e2e-product-company |
   | Vitrine services | http://127.0.0.1:38103/services/e2e-service-company |

   Les chemins et comptes générés restent consultables dans storage/app/e2e-fixtures.json. Ils sont créés par E2ESmokeSeeder pour cet environnement isolé.

6. Préparer une feuille de preuve : SHA, date/heure, navigateur, taille d’écran, résultat de chaque étape et une capture seulement si elle ne contient ni secret ni donnée client.

## 2. Rejouer les contrôles automatiques

Lancer les commandes depuis la racine du dépôt, dans cet ordre.

1. Vérifier les trois contrats Node des tickets Phase 1.

   ~~~powershell
   node --test tests/Node/P1003I18nDomainLoaderTest.mjs
   node --test tests/Node/P1004PublicMediaTest.mjs
   node --test tests/Node/P1005FrontendBudgetsTest.mjs
   php scripts/generate-public-image-variants.php --check
   ~~~

   Résultat attendu : les trois commandes Node réussissent et le générateur confirme les 100 variantes d’images. Elles contrôlent respectivement les domaines de traduction, le catalogue média et les budgets/versionnement.

2. Construire les actifs puis mesurer et faire respecter les budgets.

   ~~~powershell
   npm run qa:build
   npm run qa:frontend-budgets:measure
   npm run qa:frontend-budgets
   node scripts/check-frontend-budgets.mjs --base-ref HEAD
   ~~~

   Résultat attendu : le build réussit, la mesure affiche les sept parcours et quatre profils d’images, le contrôle strict réussit sans dérogation et la comparaison avec HEAD indique une politique unchanged_or_stricter.

3. Rejouer les parcours navigateur automatisés.

   ~~~powershell
   npm run qa:e2e
   ~~~

   Résultat attendu : la suite Playwright est verte. Les preuves initiales indiquent 16 scénarios réussis ; le nombre peut évoluer si la suite est enrichie, mais aucun scénario ne doit échouer.

   Pour isoler rapidement une anomalie avant le rejeu complet :

   ~~~powershell
   npx playwright test tests/e2e/i18n-domain-loading.spec.js
   npx playwright test tests/e2e/public-media-optimization.spec.js
   ~~~

   Après tous les contrôles automatiques, revenir à l’étape 1.4 pour démarrer le serveur manuel E2E avant la recette humaine des sections 3 à 5.

4. Rejouer la non-régression PHP si l’environnement local le permet.

   ~~~powershell
   php -d memory_limit=512M vendor/bin/pest
   git diff --check
   ~~~

   Résultat attendu : Pest et le contrôle des espaces se terminent sans échec. La preuve de référence P1-004/P1-005 a atteint 1 297 tests et 13 270 assertions ; ce nombre est indicatif, le verdict dépend de l’absence d’échec.

5. Si la revue modifie un fichier PHP, appliquer la gate PHP complète seulement après avoir indexé tous les fichiers PHP du lot.

   ~~~powershell
   git add <fichiers-du-lot>
   composer qa:format
   git diff --check
   ~~~

   Cette étape ne s’applique pas à une revue purement manuelle ou documentaire. Ne pas déclarer un changement PHP prêt tant que la gate de format et la CI Laravel ne sont pas vertes.

6. Enregistrer les sorties synthétiques dans le journal de validation. Ne pas y copier de cookies, URLs contenant des paramètres sensibles, contenu client, SQL, bindings ou jetons.

## 3. Valider P1-003 — Traductions chargées par domaine

Objectif : réduire les catalogues i18n initiaux tout en gardant exactement les mêmes textes, clés, fallbacks et bascules de langue.

### 3.1 Parcours public

1. Ouvrir l’accueil en français avec un cache navigateur désactivé.
2. Vérifier que les éléments visibles sont en français et qu’aucune chaîne ne ressemble à une clé technique, par exemple avec des points, des underscores ou des accolades non traduits.
3. Changer la langue vers espagnol, attendre la navigation complète, puis vers anglais.
4. À chaque bascule, vérifier :

   - le texte de navigation et le contenu de la page sont traduits ;
   - aucune clé brute n’apparaît brièvement ;
   - aucune erreur rouge i18n, import de module ou promesse rejetée ne paraît dans la Console ;
   - la page reste navigable et les liens publics continuent de fonctionner.

5. Dans Network, filtrer les fichiers JavaScript et noter que les modules de langue demandés correspondent à la page visitée. Il n’est pas nécessaire de comptabiliser manuellement les octets ; le garde automatisé fournit cette mesure.

### 3.2 Parcours authentifié

1. Se connecter avec un compte de démonstration autorisé.
2. Ouvrir le dashboard, puis une page voisine réellement utilisée, par exemple planning ou détail client.
3. Répéter une bascule français → anglais → espagnol.
4. Vérifier les titres, actions, menus, tableaux, messages vides et navigation Inertia.
5. Naviguer au moins une fois sans rechargement complet entre deux pages. Aucun texte brut, clignotement de clé ou erreur Console ne doit apparaître.

### 3.3 Vérifier le rollback compilé

Le rollback est un réglage de build ; modifier une variable après le build ne suffit pas. Cette manipulation est locale et temporaire. Ne pas modifier le fichier .env partagé ou une configuration déployée.

1. Dans le même terminal PowerShell, activer le chargeur historique puis reconstruire.

   ~~~powershell
   $env:VITE_I18N_DOMAIN_LOADING = 'false'
   npm run qa:build
   ~~~

2. Recharger l’accueil et le dashboard en cache désactivé. Répéter une bascule de langue sur chaque surface.
3. Résultat attendu : les mêmes contenus et fallbacks restent corrects, avec le catalogue historique complet.
4. Retirer la variable de la session PowerShell et reconstruire le comportement par domaines par défaut.

   ~~~powershell
   Remove-Item Env:VITE_I18N_DOMAIN_LOADING -ErrorAction SilentlyContinue
   npm run qa:build
   git status --short
   ~~~

5. Résultat attendu : le statut Git reste propre. Si une différence apparaît, ne pas la committer au titre de la validation.

### 3.4 Verdict P1-003

Accepter P1-003 seulement si les contrôles Node, build, public, authentifié et rollback sont tous réussis.

Refuser ou bloquer P1-003 si une clé brute, une traduction manquante, une régression de navigation, une erreur Console, un échec de build ou un rollback non fonctionnel est observé.

## 4. Valider P1-004 — Images et polices du chemin critique

Objectif : servir des variantes AVIF/WebP seulement pour le catalogue stock local, garder le JPEG comme repli, prévenir le déplacement visuel et ne pas altérer les médias externes ou tenant.

### 4.1 Héros et priorité sur accueil

1. Ouvrir l’accueil en bureau, cache désactivé.
2. Vérifier visuellement que le premier héros est complet sans saut apparent de hauteur pendant le chargement.
3. Dans Inspecteur, vérifier que l’image stock est dans un élément picture avec des sources AVIF/WebP et un JPEG de repli.
4. Dans Network, vérifier qu’un navigateur compatible reçoit une image avec un type image/avif ou image/webp. Un JPEG est acceptable sur un navigateur qui ne supporte pas ces formats.
5. Vérifier que la première diapositive est chargée avec priorité haute et que les suivantes, ainsi que les images sous le pli, restent différées.
6. Répéter la vérification dans une largeur mobile, par exemple 390 px. Le héros ne doit ni s’écraser, ni déborder, ni masquer son appel à l’action.

### 4.2 Boutique et vitrine publique

1. Ouvrir une boutique publique disponible dans les fixtures locales.
2. Vérifier l’image de tête, le premier produit visible et une image plus bas dans la page.
3. Ouvrir ensuite la vitrine publique.
4. Vérifier que le héros conserve son cadrage de couverture, sans bandes imprévues ni image étirée.
5. Refaire la vérification sur mobile. Les tailles exactes peuvent varier selon le navigateur ; l’absence de déformation et la conservation du contenu important sont les critères fonctionnels.

### 4.3 Frontière des médias non stock

1. Si une fixture locale déjà existante contient une image tenant, CDN externe ou data:, ouvrir l’écran correspondant.
2. Comparer l’attribut source rendu à l’URL connue avant la revue.
3. Résultat attendu : l’URL est strictement conservée ; aucune variante locale ne doit être fabriquée pour cette image.
4. Si aucune fixture non stock n’est disponible, noter « non exercé manuellement ». Ne pas inventer une donnée métier pour forcer ce test ; le contrat automatique P1004 couvre cette frontière.

### 4.4 Police et compatibilité CSP

1. Recharger une page publique une fois avec le cache désactivé.
2. Vérifier que le texte s’affiche lisiblement pendant et après le chargement de Montserrat.
3. Dans Elements, vérifier dans head la présence d’une feuille directe https://fonts.bunny.net/css avec display=swap. L’absence de l’ancien import CSS imbriqué dans la feuille compilée est garantie par le test Node P1004 ; ne pas conclure à partir de la seule présence d’une requête réseau.
4. Vérifier dans Console l’absence d’erreur CSP liée à un script inline.

### 4.5 Rollback

Le rollback est un revert isolé du commit 4fa1ac3f. Il ne supprime ni migration ni donnée métier : les JPEG historiques restent présents.

Pour une revue humaine, il suffit de vérifier le commit et sa portée sans modifier develop :

~~~powershell
git show --stat 4fa1ac3f
git show --format=fuller --no-ext-diff 4fa1ac3f
~~~

Un test réel de revert ne doit être réalisé que dans une branche ou un worktree jetable, jamais dans la branche develop partagée.

### 4.6 Verdict P1-004

Accepter P1-004 seulement si les variantes stock, le fallback, les dimensions, les priorités, les vues bureau/mobile, la boutique, la vitrine et la frontière des médias non stock sont conformes.

Refuser ou bloquer P1-004 si une image est cassée, déformée, chargée à tort depuis une URL réécrite, si le héros bouge de manière visible ou si une erreur CSP apparaît.

## 5. Valider P1-005 — Budgets frontend en CI

Objectif : empêcher une hausse silencieuse des actifs initiaux des sept parcours et des quatre profils d’images locaux.

### 5.1 Contrôle local du garde

1. Vérifier qu’aucune dérogation de budget n’est active pour cette revue.

   ~~~powershell
   Get-Item Env:FRONTEND_BUDGET_EXCEPTION -ErrorAction SilentlyContinue
   ~~~

   Résultat attendu : aucune valeur n’est nécessaire. Ne jamais définir une dérogation pour faire passer un contrôle de validation.

2. Construire les actifs et lancer le garde strict.

   ~~~powershell
   npm run qa:build
   npm run qa:frontend-budgets:measure
   npm run qa:frontend-budgets
   ~~~

3. Vérifier que le rapport couvre exactement ces parcours : accueil, connexion, dashboard, détail client, planning, boutique publique et vitrine publique.
4. Vérifier que les profils médias se limitent au catalogue local AVIF/WebP en 640w et 1280w. Les uploads, médias tenant et CDN sont volontairement hors budget.
5. Résultat attendu : toutes les métriques restent au plus égales à leur plafond. Le plafond de référence est baseline × 1,05, arrondi à l’entier supérieur.

### 5.2 Contrôle de la CI distante

1. Ouvrir l’exécution GitHub Actions 30964242010 associée au commit P1-005.
2. Vérifier que les jobs qualité Laravel, compatibilité MySQL et navigateur sont tous verts.
3. Dans le job qualité, vérifier que le test P1005 est exécuté avant le build et que le garde de budget s’exécute après le build.
4. Vérifier qu’aucune dérogation n’est mentionnée dans le log du garde.

La CI distante est une preuve complémentaire. Elle ne transforme pas la mesure locale statique en Web Vitals représentatifs.

### 5.3 Politique d’exception et rollback

Une exception future doit référencer une décision distincte, explicitement acceptée, non expirée et dédiée à P1-005 ainsi qu’aux budgets frontend. Une exception générale, expirée ou non dédiée est refusée par le garde.

Le rollback est un revert isolé du commit 2ff77eea. Il retire la configuration, le script et l’étape CI sans migration ni écriture métier. Il ne doit être utilisé qu’après décision explicite, car il retire une protection préventive.

### 5.4 Verdict P1-005

Accepter P1-005 seulement si le garde local, ses tests Node et la CI distante sont tous verts, sans dérogation.

Refuser ou bloquer P1-005 si un parcours ou profil manque, si un plafond est dépassé, si l’identité d’un parcours a changé sans décision, ou si une dérogation a été utilisée sans décision dédiée active.

## 6. Décision de clôture de la Phase 1

Après les trois verdicts, compléter la matrice suivante.

| Élément | État attendu pour la clôture | État à inscrire |
|---|---|---|
| P1-003 | accepté humainement |  |
| P1-004 | accepté humainement |  |
| P1-005 | accepté humainement après CI verte |  |
| Workflows frontend | aucun écart observé |  |
| Rollbacks | documentés et applicables |  |
| Limite P0-006 | explicitement reconnue, sans revendication dynamique |  |
| Phase 2 | pas ouverte par cette seule acceptation |  |

L’acceptation des tickets et la clôture de Phase 1 sont deux décisions distinctes. La case Web Vitals avant/après de la gate Phase 1 reste non satisfaite tant que P0-006 n’a pas produit de baseline représentative. Ainsi, une clôture formelle exige soit cette preuve, soit une exception de clôture Phase 1 explicitement écrite, datée et bornée ; MLK-DEC-010 ne peut pas être réemployée implicitement car elle autorise seulement l’ouverture de la Phase 1.

Même si la Phase 1 est formellement clôturée, cela ne vaut pas GO Phase 2. La Phase 2 exige en plus la décision MLK-DEC-011, le traitement explicite de MLK-DEC-005, les preuves P0-005/P0-006 sur staging ou une nouvelle exception GO P2 datée et bornée.

## 7. Texte de décision à copier après revue

~~~text
Je suis responsable Produit, Technique et Exploitation.

J’ai revu le runbook PHASE_1_VALIDATION_RUNBOOK.md au commit [SHA].
J’accepte / refuse P1-003 — Traductions chargées par domaine.
J’accepte / refuse P1-004 — Images et polices du chemin critique.
J’accepte / refuse P1-005 — Budgets frontend en CI, y compris la CI distante verte.

Je confirme que les validations sont locales, que P0-005/P0-006 restent des dettes reportées jusqu’au 2027-08-04 et qu’aucun gain dynamique représentatif n’est revendiqué.

Je clôture / ne clôture pas formellement la Phase 1.
Si je la clôture sans baseline P0-006, j’accepte une exception Phase 1 explicitement décrite, datée et bornée.
Cette décision n’ouvre pas la Phase 2 et n’autorise ni test de charge, ni staging, ni écriture en production.
~~~

Après la décision, ajouter une entrée expurgée dans [VALIDATION_LOG.md](VALIDATION_LOG.md), mettre à jour [SUIVI_GLOBAL.md](SUIVI_GLOBAL.md) et, si la Phase 1 est clôturée, préparer le GO Phase 2 distinct à partir du [runbook Phase 2](PHASE_2_EXECUTION_RUNBOOK.md).

## Documents liés

- [Phase 1 — gains rapides de performance](PHASE_1_QUICK_PERFORMANCE_WINS.md)
- [Runbook d’exécution Phase 2](PHASE_2_EXECUTION_RUNBOOK.md)
- [Protocole obligatoire de tests et de non-régression](QUALITY_GATES.md)
- [Journal de validation](VALIDATION_LOG.md)
