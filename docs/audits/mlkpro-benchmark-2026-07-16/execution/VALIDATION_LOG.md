# Journal des validations — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-08-04

## Règles de preuve

La vue consolidée des travaux terminés, en cours et planifiés pour les Phases 0 à 4 est maintenue dans [SUIVI_GLOBAL.md](SUIVI_GLOBAL.md). Le présent journal reste la source des preuves qui autorisent chaque changement d’état.

- Ne jamais enregistrer de secret, jeton, mot de passe, donnée personnelle directe ou URL contenant des identifiants.
- Identifier chaque résultat par date, commit Git, environnement et responsable.
- Lier les sorties volumineuses à un artefact CI ou à un emplacement contrôlé plutôt que de tout coller ici.
- Une validation manuelle indique le scénario, le résultat attendu, le résultat observé et le validateur.
- Une exception possède un propriétaire, une justification et une date d’expiration.
- Les valeurs avant/après utilisent la même méthode, le même environnement et un volume comparable.
- Une campagne P0-006 consigne le run, l’environnement, le commit, la release, la fenêtre UTC, le trafic, le runner et son SHA-256, la fixture privée et son SHA-256, les origines HTTPS autorisées, les exclusions, le mode, les approbations, les canaris P0-005, la configuration cache/base/queue, le responsable et le validateur distincts ; seules ses métriques agrégées et expurgées sont versionnées.

## Entrée initiale — AUDIT-2026-07-16

- Type : photographie d’audit, pas gate de livraison
- Commit de référence : `d89ad55` observé pendant l’audit
- Environnement : local avec débogage actif pour les temps HTTP
- Responsable de mesure : Codex
- Résultats :
  - build Vite réussi ;
  - 1 119 tests Pest réussis en deux segments ;
  - Pint réussi ;
  - PHPStan incomplet à cause du délai Composer de 300 secondes ;
  - audits Composer/npm avec avis à traiter ;
  - observabilité non exploitable : un seul échantillon ;
  - capacité non exploitable : scénarios sous le minimum d’échantillons.
- Verdict : **insuffisant pour autoriser Phase 1** ; Phase 0 proposée.
- Preuves : [evidence.md](../evidence.md), [report.html](../report.html).

## PRECHECK-P0-2026-07-16 — Baseline locale et contrat Twilio

- Type : préparation contrôlée de Phase 0 ; ne prouve pas la rotation externe.
- Date : 2026-07-16.
- Commit de référence : `d89ad55`.
- Environnement : Windows local, PHP 8.4.23, Node 24.14.1, npm 11.11.0, Composer 2.10.1.
- Responsable d’exécution : Codex.
- Changement contrôlé : isolation forcée des quatre variables Twilio dans PHPUnit et ajout de sept tests de contrat SMS/WhatsApp utilisant uniquement des valeurs fictives.
- Baseline ciblée avant changement : 64 tests réussis, 415 assertions.
- Tests du nouveau contrat : 7 tests réussis, 23 assertions.
- Non-régression ciblée après changement : 71 tests réussis, 438 assertions.
- Non-régression complète sur l’état final du lot : 1 126 tests réussis, 11 992 assertions, durée 262,99 s.
- Qualité : Pint réussi ; PHPStan complet réussi sans erreur.
- Frontend : build Vite réussi, 2 603 modules, durée 1 min 48 s.
- Queue locale : connexion database mesurable, zéro job en attente et zéro échec sur 24 h et 7 jours.
- Audits Composer : 20 avis sur 12 paquets de production, dont 3 élevés ; 24 avis sur 14 paquets avec les dépendances de développement, dont 4 élevés.
- Audits npm : 2 paquets de production vulnérables ; audit complet à 15 paquets vulnérables, dont 2 critiques et 7 élevés.
- Observabilité : toujours insuffisante ; un seul échantillon `welcome`, aucun scénario de capacité au minimum configuré.
- Écart d’environnement : la machine locale utilise Node 24 alors que la CI utilise Node 20 ; la validation finale devra être rejouée sous Node 20.
- Preuves versionnées : `phpunit.xml`, `tests/Unit/Services/TwilioNotificationServicesTest.php`.
- Secret scan : réussi sur les artefacts d’audit ; aucune valeur Twilio réelle ajoutée aux tests ou documents.
- Rollback : revert atomique des deux fichiers de test/configuration ; aucun code applicatif modifié.
- Verdict : **durcissement local validé** ; `MLK-IMP-P0-001` reste ouvert jusqu’à rotation, canaris et invalidation vérifiée de l’ancien jeton.

## VALID-P0-001-LOCAL-2026-07-16 — Jeton secondaire validé localement

- Type : validation intermédiaire de rotation ; ne prouve ni la promotion ni l’invalidation de l’ancien jeton.
- Date : 2026-07-16.
- Commit de référence : `d89ad55`, avec lot de durcissement local non commité.
- Environnement : Windows local, Laravel 11.47, PHP 8.4.23.
- Responsables : demandeur pour la génération et l’injection du secret ; Codex pour les contrôles expurgés.
- Changement contrôlé : un jeton secondaire déclaré inutilisé a été généré dans Twilio puis injecté manuellement dans le `.env` local, sans transmission de sa valeur dans la conversation, les commandes, les tests ou les documents.
- Protection du secret : `.env` confirmé ignoré par Git ; format du SID et du jeton contrôlé sans affichage ; aucune réponse Twilio contenant un secret conservée.
- Validation fournisseur : authentification silencieuse réussie sur la ressource Account avec le SID et le nouveau Auth Token chargés en mémoire uniquement.
- Validation Laravel : configuration non cachée avant intervention ; `config:clear` réussi ; signal `queue:restart` diffusé ; SID et jeton chargés par `config('services.twilio.*')` et contrôlés uniquement par type, longueur et format.
- Processus persistants : aucun worker Artisan actif détecté ; Horizon et Octane absents ; le développement local utilise `queue:listen`, qui relance un processus par travail.
- Tests de contrat : 7 réussis, 23 assertions.
- Non-régression ciblée : 69 tests réussis, 429 assertions, couvrant SMS, WhatsApp, réglages, 2FA, tâches, réservations et kiosque.
- Non-régression complète : 1 126 tests réussis, 11 992 assertions, durée 304,46 s avec `memory_limit=512M` limité au processus de test.
- Écart d’environnement : la première exécution complète s’est arrêtée sur la limite PHP locale de 128 Mo, sans échec d’assertion ; aucune configuration applicative n’a été modifiée pour la relance.
- Canaris réels : SMS livré et confirmé manuellement le 2026-07-17 ; WhatsApp, 2FA et campagne restent à exécuter.
- Promotion : non exécutée ; l’ancien primaire reste valide tant que tous les environnements et les canaris SMS, WhatsApp, 2FA et campagne ne sont pas confirmés.
- Rollback : générer un nouveau jeton de remplacement et le réinjecter ; ne jamais remettre en service le jeton exposé.
- Verdict : **validation locale réussie** ; `MLK-IMP-P0-001` passe en validation et reste ouvert jusqu’aux canaris, à la promotion, au rejet prouvé de l’ancien jeton et à la revue d’activité Twilio.

## CANARY-P0-001-SMS-2026-07-17 — Livraison SMS confirmée

- Ticket : `MLK-IMP-P0-001`.
- Date : 2026-07-17.
- Environnement : local, avec le jeton secondaire chargé depuis le `.env` ignoré par Git.
- Scénario : envoi unique avec `php artisan sms:test <destinataire contrôlé>` ; aucun numéro, SID de message ou en-tête d’authentification n’est conservé dans cette preuve.
- Résultat attendu : acceptation par Twilio puis réception sur le terminal contrôlé.
- Résultat observé : réception du SMS confirmée manuellement par le demandeur.
- Observation ergonomique : la commande exige actuellement le format E.164 avant d’appeler le service, bien que le service sache normaliser un numéro nord-américain à dix chiffres ; ce suivi ne modifie pas le code de ce lot.
- Canaris restants : WhatsApp, activation 2FA SMS et test de campagne sur des comptes/destinataires contrôlés.
- Promotion : non exécutée ; l’ancien jeton principal reste valide.
- Verdict : **canari SMS réussi** ; rotation globale encore ouverte.

## VALID-P0-001-HARNESS-2026-07-17 — Harnais des canaris Twilio restants

- Ticket : `MLK-IMP-P0-001`.
- Date : 2026-07-17.
- Base de reprise : `develop` au commit `74bdba9`.
- Commits fonctionnels du lot sur la branche issue de `develop` : `f54bb8c` pour le canari SMS et `d7440ac` pour le harnais Twilio.
- Environnement : Windows local, PHP 8.4.23 et build Vite de production.
- Changement contrôlé : ajout de `whatsapp:test`, diagnostic structuré du service WhatsApp, test du transport 2FA SMS avec repli email et test de campagne SMS limité à l’acteur connecté.
- Protection des communications : toutes les requêtes Twilio des tests utilisent `Http::fake()` avec `Http::preventStrayRequests()` ; les notifications email utilisent `Notification::fake()` ; aucun SMS, WhatsApp ou email réel n’a été envoyé par ce lot.
- Test rouge initial : les cinq scénarios WhatsApp échouaient comme prévu parce que `whatsapp:test` n’existait pas.
- Tests ciblés du harnais : 16 réussis, 87 assertions.
- Non-régression fonctionnelle Twilio, 2FA, réglages et campagnes : 99 réussis, 693 assertions.
- Non-régression complète : 1 140 tests réussis, 12 071 assertions, durée 345,92 s.
- Qualité : Pint réussi sur six fichiers ; PHPStan complet réussi sans erreur ; `git diff --check` réussi.
- Frontend : build Vite réussi, 2 603 modules, durée 2 min 8 s.
- Contrôle fournisseur en lecture seule : l’API officielle des senders WhatsApp a répondu HTTP 200, sans sender enregistré sur le compte ; la valeur locale configurée ne correspond donc pas à un sender WhatsApp actif. Aucun numéro ni identifiant de sender n’a été consigné.
- Rollback : revert du commit `b7efe04` ; le contrat booléen historique de `WhatsappNotificationService::send()` reste compatible.
- Canaris réels restants : activer un Sandbox ou enregistrer un sender WhatsApp, faire rejoindre le destinataire contrôlé, puis exécuter WhatsApp, 2FA SMS et campagne SMS avant promotion.
- Promotion : non exécutée ; l’ancien jeton principal reste valide.
- Verdict : **harnais local validé** ; envoi WhatsApp bloqué proprement par l’absence de sender, autres canaris et rotation globale toujours ouverts.

## CANARY-P0-001-WHATSAPP-2026-07-17 — Livraison et lecture WhatsApp confirmées

- Ticket : `MLK-IMP-P0-001`.
- Date : 2026-07-17.
- Base applicative : branche dédiée issue de `develop`, avec le harnais au commit `d7440ac`.
- Environnement : local, avec le jeton secondaire chargé depuis le `.env` ignoré par Git.
- Préconditions : Sandbox WhatsApp activé par le demandeur et destinataire contrôlé joint au Sandbox ; l’expéditeur Sandbox a été appliqué uniquement à la commande canari par variable de processus, sans modifier le `.env`.
- Précontrôle automatisé : 13 tests réussis, 50 assertions, couvrant la commande WhatsApp et les contrats des services Twilio.
- Scénario : envoi unique avec `php artisan whatsapp:test <destinataire contrôlé>` en mode silencieux ; aucun numéro, SID de message, contenu d’authentification ou réponse fournisseur brute n’est conservé dans cette preuve.
- Résultat de la commande : code de sortie `0`, confirmant l’acceptation par Twilio.
- Contrôle fournisseur en lecture seule : le message canari sortant exact a été retrouvé comme récent par l’API Twilio, avec HTTP `200`, statut final `read` et aucun code d’erreur ; aucun identifiant ni numéro n’a été affiché.
- Rollback : la variable de processus temporaire a été restaurée automatiquement ; aucune configuration persistante ni donnée applicative n’a été modifiée.
- Canaris restants : activation 2FA SMS et test de campagne SMS sur des comptes QA contrôlés.
- Promotion : non exécutée ; l’ancien jeton principal reste valide jusqu’à la réussite des canaris restants et de la revue fournisseur.
- Verdict : **canari WhatsApp réussi** ; rotation globale encore ouverte.

## CANARY-P0-001-2FA-SMS-2026-07-17 — Parcours 2FA SMS confirmé

- Ticket : `MLK-IMP-P0-001`.
- Date : 2026-07-17.
- Base applicative : branche dédiée issue de `develop`, avec le harnais au commit `d7440ac`.
- Environnement : local, compte QA propriétaire et terminal contrôlé ; aucune donnée d’identification n’est conservée dans cette preuve.
- Préconditions : 2FA SMS autorisé pour l’entreprise QA, méthode SMS sélectionnée dans les paramètres de sécurité et téléphone QA contrôlé.
- Scénario : une déconnexion puis une seule reconnexion ; réception du code 2FA par SMS, saisie du code et finalisation de la connexion.
- Résultat métier : réception du SMS et connexion réussie confirmées par le demandeur.
- Contrôle fournisseur en lecture seule : le SMS 2FA exact a été reconnu par sa structure sans lire ni conserver le code ; l’API Twilio a répondu HTTP `200`, avec un message récent au statut `delivered` et aucun code d’erreur.
- Protection des données : aucun numéro, code 2FA, SID de message, corps de message ou secret fournisseur n’a été affiché ou versionné.
- Couverture automatisée associée : succès du transport SMS, persistance du hash du code et repli email en cas d’échec Twilio couverts dans `TwoFactorServiceTest`.
- Rollback QA : remettre la méthode 2FA précédente et désactiver l’option SMS de l’entreprise si elle n’était activée que pour le canari.
- Canari restant : test de campagne SMS limité à l’acteur d’un tenant QA isolé.
- Promotion : non exécutée ; l’ancien jeton principal reste valide jusqu’au dernier canari et à la revue fournisseur.
- Verdict : **canari 2FA SMS réussi** ; rotation globale encore ouverte.

## CANARY-P0-001-CAMPAIGN-SMS-2026-07-17 — Test de campagne SMS isolé confirmé

- Ticket : `MLK-IMP-P0-001`.
- Date : 2026-07-17.
- Base applicative : branche dédiée issue de `develop`, avec le harnais au commit `d7440ac`.
- Environnement : local, même propriétaire QA contrôlé que le canari 2FA ; aucune identité, aucun numéro ni contenu client n’est conservé.
- Précontrôle bloquant : exactement un propriétaire QA correspondait au canari 2FA récent ; Twilio était configuré ; la branche active n’était pas `main` et descendait de `develop`.
- Scénario : création transactionnelle d’une campagne brouillon temporaire avec le seul canal SMS et un texte statique sans jeton, puis invocation contrôlée de l’action `CampaignRunController::testSend` pour l’acteur QA.
- Résultat applicatif : HTTP `200`, canal `SMS` et résultat `ok=true` avec identifiant fournisseur présent mais jamais affiché.
- Contrôle fournisseur en lecture seule : HTTP `200`, statut final `delivered` et aucun code d’erreur ; le corps et les adresses n’ont pas été affichés.
- Isolation vérifiée : aucune audience, aucune exécution de campagne et aucun destinataire de campagne créés.
- Rollback : transaction annulée, campagne temporaire absente après contrôle et script opérateur temporaire supprimé du dépôt.
- Couverture automatisée associée : le test de frontière campagne confirme que seul l’acteur reçoit le SMS et qu’aucune audience, exécution, file ou recipient n’est créé.
- Canaris requis avant promotion : **tous réussis** — SMS direct, WhatsApp, 2FA SMS et campagne SMS.
- Promotion : non exécutée ; l’ancien jeton principal reste valide jusqu’à la promotion contrôlée et à la preuve de rejet.
- Verdict : **canari campagne SMS réussi** ; P0-001 est prêt pour la promotion du jeton secondaire.

## VALID-P0-001-CLOSEOUT-2026-07-17 — Rotation Twilio fermée

- Ticket : `MLK-IMP-P0-001`.
- Date : 2026-07-17.
- Commit applicatif de référence : `8da7b9c` sur une branche issue de `develop`.
- Validation exploitation fournie par le demandeur : nouveau jeton promu et déployé dans les autres environnements ; ancien jeton révoqué et rejeté ; aucune activité inhabituelle observée dans Twilio.
- Canaris : SMS direct, WhatsApp, 2FA SMS et campagne SMS confirmés ; un canari SMS minimal supplémentaire a réussi après la dernière rotation.
- Contrôle local expurgé : Laravel charge le SID, le jeton, l’expéditeur SMS et l’expéditeur WhatsApp ; aucun secret n’a été affiché. `config:clear` et `queue:restart` ont réussi ; aucun worker Laravel persistant, Horizon ou Octane n’était actif localement.
- Santé queue locale : connexion `database`, zéro job en attente et zéro échec sur 24 h et 7 jours.
- Rollback : générer un nouveau jeton et le redéployer ; ne jamais réactiver un jeton exposé ou révoqué.
- Verdict : **validé ; P0-001 terminé**.

## BASELINE-P0-2026-07-17 — Baseline locale P0-002 gelée

- Ticket : `MLK-IMP-P0-002`.
- Date : 2026-07-17.
- Commit de référence : `8da7b9c`.
- Branche : `agent/twilio-rotation-closeout-develop`, confirmée descendante de `develop` ; worktree propre avant consignation.
- Environnement : Windows local, PHP 8.4.23, Node 24.14.1, npm 11.11.0 et Composer 2.10.1.
- Disponibilité Node : Node 24.14.1 et 25.8.2 installés ; Node 20 absent. La CI ou un environnement Node 20 doit rejouer les gates frontend avant la sortie de Phase 0.
- Audit Composer verrouillé : 24 avis sur 14 paquets, dont 4 élevés, 15 moyens, 4 faibles et 1 sans sévérité renseignée ; remédiation reportée à P0-003.
- Audit npm de production : 2 vulnérabilités, dont 1 élevée et 1 modérée ; remédiation reportée à P0-004.
- Format : `composer qa:format` réussi.
- Analyse statique : PHPStan complet réussi sans erreur avec une limite mémoire de 1 Go.
- Tests Pest : 1 140 tests réussis, 12 071 assertions, durée 390,93 s avec `memory_limit=512M`.
- MySQL isolé : bloqué avant exécution, car l’exécutable `mysql` est absent du `PATH` local ; aucun échec de test MySQL n’a été observé. Gate à rejouer sur l’environnement CI/MySQL.
- Frontend sous Node 24 : build Vite réussi, 2 603 modules transformés ; suite Playwright complète réussie, statut final `passed` et aucun test échoué.
- Queue locale : connexion `database`, zéro job en attente, zéro échec sur 24 h et 7 jours.
- Observabilité : statut `critical` local ; les échantillons ne sont pas représentatifs. Le dashboard possède 17 observations sur un minimum de 25 ; les autres scénarios prioritaires n’ont pas d’échantillon exploitable.
- Capacité : statut `warning` et tous les scénarios marqués `insufficient_data` ; P0-006 reste requis avant toute conclusion de performance.
- `git diff --check` : réussi avant consignation.
- Rollback : sans objet, aucune modification applicative ou de dépendance dans ce ticket.
- Verdict : **baseline locale enregistrée ; P0-002 terminé avec gates Node 20 et MySQL à rejouer avant la sortie de Phase 0**.

## RESUME-P0-003-2026-07-27 — Remédiation PHP reprise et migration Laravel 12 validée localement

- Ticket : `MLK-IMP-P0-003`.
- Date : 2026-07-27.
- Branche : `agent/twilio-rotation-closeout-develop`, issue de `develop` et jamais de `main` ; base de reprise `197599f` avec worktree propre.
- Point d’arrêt retrouvé : `197599f` avait déjà contraint `twilio/sdk` à `^8.11.6` et renouvelé 82 paquets, mais aucune validation P0-003 n’avait été consignée après cette résolution large.
- Cohérence initiale : les 149 paquets installés correspondaient au lock. `composer validate --strict --no-check-publish` confirmait un manifeste valide, avec l’avertissement préexistant sur la contrainte exacte `laravel/cashier-paddle: 2.6`.
- Test ciblé avant correction : 101 tests et 699 assertions réussis sur Twilio, SMS, WhatsApp, 2FA, campagnes, notifications et tâches.
- Non-régression initiale : 1 136 tests réussis et 4 échoués. Les quatre écarts étaient des durées négatives après le passage de Carbon 2 à Carbon 3, dont `diffIn*` renvoie désormais un flottant signé par défaut.
- Changement contrôlé : tous les appels applicatifs `diffIn*` ont reçu une intention de signe explicite ; les contrats historiquement entiers sont convertis explicitement en entier. Aucun contrat de route, API, payload ou modèle n’a changé.
- Rejeu ciblé : 7 tests et 242 assertions réussis sur les prévisions, le dashboard manager et les analyses de demandes qui avaient échoué.
- Autorisation réseau : le demandeur a explicitement autorisé l’envoi à Packagist.org des noms et versions de `composer.lock` pour `composer audit` et `composer update` ; aucun secret applicatif n’a été transmis ou journalisé.
- Audit avant remédiation : audit complet à 15 avis sur 4 paquets — 1 élevé, 8 moyens, 5 faibles et 1 sans sévérité — dont 12 avis de production sur Dompdf, Guzzle et Laravel. L’avis élevé concernait Laravel.
- Résolution Composer contrôlée : contraintes `laravel/framework:^12.61.1`, `larastan/larastan:^3.10` et `laravel/cashier-paddle:^2.6`; suppression des trois exceptions d’audit obsolètes. Le lock a réalisé 2 installations, 19 mises à jour et 1 retrait.
- Versions de sortie principales : Laravel 12.64.0, Inertia Laravel 2.0.24, Larastan 3.10.0, Breeze 2.4.2, Cashier Paddle 2.8.1, Dompdf 3.1.6, Guzzle 7.15.2, Symfony YAML 7.4.14 et PHPStan 2.2.6.
- Audit après remédiation : `composer audit --locked --format=json` réussi avec zéro avis, zéro paquet abandonné et aucune exception configurée. `composer validate --strict --no-check-publish` et `composer check-platform-reqs` ont réussi sous PHP 8.4.23.
- Reproductibilité locale : `composer install --dry-run --no-interaction` a validé le lock et annoncé qu’aucune opération n’était nécessaire. Une installation réellement propre reste à rejouer en CI ou dans un clone vierge.
- Compatibilité Laravel 12 — SVG : retrait de cinq autorisations explicites de SVG, rejet par MIME, extension et contenu raster réel dans la bibliothèque d’assets, refus côté composant de dépôt et sélecteurs d’assets. Un test confirme aussi le rejet d’un SVG renommé en `.png`. Les SVG statiques versionnés et de confiance restent disponibles.
- Compatibilité Laravel 12 — routes : suppression de 367 noms vides `api.` / `api.super-admin.`, préservation explicite des cinq contrats API existants, préfixage des ressources API `product`, `service`, `customer` et `work`. Les noms sont globalement uniques et `artisan route:cache` réussit ; le cache de vérification a ensuite été retiré.
- Tests ciblés Laravel 12 : 25 tests et 167 assertions réussis sur les routes, le profil et les assets, y compris les quatre familles de ressources API et le SVG déguisé.
- Format : Pint réussi sur les 39 fichiers PHP modifiés.
- Analyse statique : PHPStan complet réussi sans erreur avec Larastan 3.10, PHPStan 2.2 et une limite mémoire de 1 Go ; le rejeu final après durcissement SVG est également vert.
- Pest complet final : 1 152 tests réussis, 12 099 assertions, durée 225,01 s avec PHP 8.4.23 et `memory_limit=1G`.
- Frontend : build Vite final réussi sous Node 24.14.1, 2 603 modules transformés, durée 34,49 s.
- E2E : 6 parcours Playwright/Chromium réussis sur SQLite avec le build final et le serveur Laravel 12 local, durée totale 2 min 18 s.
- Gates encore ouvertes : installation réellement propre depuis le lock sur un checkout vierge, MySQL ciblé, replay Node 20/CI et validation humaine du lot. L’exécutable MySQL et Node 20 ne sont pas disponibles dans l’environnement local actuel.
- Rollback : revert du lot P0-003 pour restaurer le manifeste et le lock précédents ; ne pas utiliser `git reset --hard`.
- Verdict : **remédiation Composer et migration Laravel 12 validées localement sans avis connu ni régression ; P0-003 passe en validation jusqu’aux replays d’environnement**.

## VALID-P0-003-CLOSEOUT-2026-07-27 — Remédiation PHP clôturée sur develop

- Ticket : `MLK-IMP-P0-003`.
- Date : 2026-07-27.
- Livraison : commit applicatif `37bc336f524be4817df779555ad24eefba597442`, pull request [#131](https://github.com/Lenenba/mlkpro_v3/pull/131) vers `develop`, puis merge `28fc253f044d046be4906e214b2eb838df37f554`. Aucune opération n’a été effectuée sur `main`.
- Validation humaine : le demandeur a demandé l’intégration du lot, puis en a confirmé le résultat.
- Audit de sécurité : `composer audit --locked --format=json` a été exécuté localement avec zéro avis, zéro paquet abandonné et aucune exception configurée.
- CI de référence : workflow `quality` réussi sur la tête de pull request `37bc336f524be4817df779555ad24eefba597442`, exécution [30276650856](https://github.com/Lenenba/mlkpro_v3/actions/runs/30276650856).
- Reproductibilité CI : checkout propre, PHP 8.4, Node 20, `composer install`, `npm ci`, format, PHPStan, Pest et build Vite réussis.
- Compatibilité environnement : la suite ciblée MySQL 8 et les six parcours Playwright/Chromium ont réussi ; les jobs `laravel-quality`, `laravel-quality-mysql` et `browser-smoke` sont tous verts.
- Rollback : revert du merge `28fc253f044d046be4906e214b2eb838df37f554` sur `develop` si un retour arrière devient nécessaire ; ne pas utiliser `git reset --hard`.
- Verdict : **P0-003 terminé ; les replays d’installation propre, Node 20 et MySQL demandés par la gate ont réussi**.

## VALID-P0-004-LOCAL-2026-07-27 — Remédiation JavaScript validée localement

- Ticket : `MLK-IMP-P0-004`.
- Date : 2026-07-27.
- Branche : `agent/p0-004-js-dependencies`, créée depuis `develop` au merge `28fc253f044d046be4906e214b2eb838df37f554` ; aucune opération sur `main`.
- Autorisation réseau : le demandeur a autorisé l’envoi à npmjs.org des noms et versions contenus dans `package-lock.json` pour les audits et mises à jour npm ; aucun secret applicatif n’a été transmis ou journalisé.
- Baseline avant remédiation : l’audit de production signalait deux paquets à sévérité élevée, `lodash` et `postcss`. L’audit complet signalait 13 vulnérabilités : 4 modérées, 7 élevées et 2 critiques.
- Changement direct : retrait de `lodash` des dépendances applicatives après confirmation de son absence d’usage dans le code ; sa version sûre `4.18.1` reste uniquement transitive de développement via `concurrently`.
- Contraintes sûres : `postcss:^8.5.18`, `axios:^1.18.0` et `vite:^6.4.3`. Le lock résout respectivement PostCSS 8.5.23, Axios 1.18.1 et Vite 6.4.3, avec les mises à jour transitives compatibles nécessaires.
- Politique de résolution : aucun `npm audit fix --force`, aucune rupture majeure imposée et aucune exception d’audit ajoutée.
- Audits après remédiation : `npm audit --omit=dev --json` et `npm audit --json` réussis avec zéro vulnérabilité, toutes sévérités confondues.
- Installation propre : `npm ci` réussi, 226 paquets installés et 227 audités, sans vulnérabilité.
- Cohérence du graphe : `npm ls lodash postcss nanoid concurrently axios vite sucrase --all` réussi ; `npm exec --offline concurrently -- --version` renvoie `9.1.2`.
- Build : `npm run qa:build` réussi avec Vite 6.4.3 et 2 605 modules transformés.
- Stabilisation Playwright : les journaux d’accès du serveur PHP sont ignorés par défaut et peuvent être réactivés avec `PLAYWRIGHT_SERVER_LOGS=1`. Le délai global passe de 45 à 90 secondes pour absorber le démarrage navigateur local ; le délai strict des assertions reste inchangé à 10 secondes.
- E2E final : la commande officielle `npm run qa:e2e` a réussi les six parcours Playwright/Chromium en 4 min 30 s. Les attentes applicatives sont toutes passées sans retry local.
- Environnement local : Node 24.14.1 et npm 11.11.0. La vérification Node 20 reste requise en CI avant de terminer le ticket.
- Prévention de régression : le workflow `quality` exécute désormais explicitement `npm audit --audit-level=high` après l’installation propre ; son replay distant est encore ouvert.
- Rollback : revert du futur commit P0-004, puis `npm ci` depuis le lock restauré ; ne pas utiliser `git reset --hard`.
- Verdict : **P0-004 validé localement sans avis connu ni régression ; ticket en validation jusqu’au replay CI Node 20**.

## VALID-P0-004-CLOSEOUT-2026-07-27 — Remédiation JavaScript clôturée

- Ticket : `MLK-IMP-P0-004`.
- Date : 2026-07-27.
- Livraison : commit applicatif `ccaf1502475e7d397d313d7737c621b74f4335d9`, pull request [#132](https://github.com/Lenenba/mlkpro_v3/pull/132) vers `develop`. Aucune opération n’a été effectuée sur `main`.
- Validation humaine : le demandeur a autorisé la remédiation npm et l’intégration des travaux sur `develop`.
- CI de référence : workflow `quality` réussi sur le commit applicatif, exécution [30283056414](https://github.com/Lenenba/mlkpro_v3/actions/runs/30283056414).
- Sécurité et reproductibilité Node 20 : `npm ci`, la nouvelle gate `npm audit --audit-level=high` et le build Vite ont réussi sur un checkout propre.
- Qualité applicative : format PHP, PHPStan et Pest complets réussis ; la suite de compatibilité MySQL 8 est verte.
- Non-régression navigateur : installation propre de Chromium et six parcours Playwright réussis dans le job `browser-smoke`.
- Impact : aucun contrat de route, API ou workflow métier n’a changé ; Vite reste sur sa version majeure 6 et aucune résolution forcée n’a été utilisée.
- Rollback : revert du lot P0-004 sur `develop`, puis `npm ci` depuis le lock restauré ; ne pas utiliser `git reset --hard`.
- Verdict : **P0-004 terminé ; audits sans avis et toutes les gates locales et CI sont vertes**.

## PREP-P0-005-2026-07-27 — Topologie queues/workers et procédure de validation définies

- Ticket : `MLK-IMP-P0-005`.
- Date : 2026-07-27.
- Branche : `agent/p0-005-queue-workers`, issue de `develop` ; aucune opération sur `main`.
- Décisions : `plan_scans` est isolé sur `plan-scans` avec un profil dédié ; `social_publish` est centralisé sur `social-publish` et consommé par le profil `social`.
- Inventaire cible : dix workloads et cinq profils dynamiques — `development`, `operations`, `plan-scans`, `campaigns` et `social` — résolus depuis `config/async.php`.
- Délais cibles : timeout de 240 secondes pour `plan_scans`, backoff de 60 secondes et `retry_after` de 300 secondes pour les connexions concernées. La visibilité doit rester strictement supérieure au timeout maximal.
- Contrôles prévus : `queue:workload-audit --json`, résolution à sec de chaque profil avec `queue:workloads <profil> --dry-run --json`, tests d’inventaire positif/négatif, tests de routage, retry/backoff et échec terminal, puis santé des queues.
- Déploiement prévu : installer quatre processus persistants séparés (`operations`, `plan-scans`, `campaigns`, `social`), démarrer les nouveaux consommateurs avant les producteurs et maintenir `default` pendant le drainage des jobs historiques.
- Canari prévu : un scan de plan non client et une cible sociale de test ou privée, suivis dans la file, les statuts métier, les journaux et `failed_jobs` jusqu’à consommation complète.
- Rollback prévu : revenir au routage précédent par revert, conserver simultanément les consommateurs des anciennes et nouvelles files jusqu’au drainage, redémarrer les workers puis vérifier santé et échecs.
- Limites : cette entrée consigne la conception et la procédure. Elle ne prouve ni que les tests P0-005 sont verts, ni que les processus de production sont installés ou actifs, ni qu’un canari de production a été exécuté.
- Verdict : **préparation documentée ; P0-005 reste en cours jusqu’aux validations locale, CI et exploitation**.

## VALID-P0-005-LOCAL-2026-07-27 — Topologie et retries validés localement

- Ticket : `MLK-IMP-P0-005`.
- Date : 2026-07-27.
- Branche : `agent/p0-005-queue-workers`, issue de `develop` ; aucune opération sur `main`.
- Implémentation : dix workloads centralisés, cinq profils dynamiques, `plan-scans` et `social-publish` consommés, files explicites prioritaires, fallback de trois tentatives pour `development`/`operations`, connexions persistantes vérifiées et collisions inter-profils refusées.
- Fiabilité scan : payload database vérifié avec queue `plan-scans`, deux tentatives, backoff 60 secondes et timeout 240 secondes ; exception technique relancée, état terminal porté uniquement par `failed`, journal d’activité best-effort après analyse réussie.
- Audit local : `queue:workload-audit --json` retourne zéro erreur et zéro workload orphelin ; les cinq profils `queue:workloads ... --dry-run --json` se résolvent sans démarrer de worker. Le visibility timeout SQS reste explicitement externe et non vérifié.
- Tests ciblés P0-005 : 29 tests, 146 assertions, tous réussis.
- Non-régression finale : 1 179 tests Pest, 12 236 assertions, tous réussis avec `memory_limit=512M`.
- Gates supplémentaires : Pint réussi sur 22 fichiers PHP, PHPStan complet sans erreur, `composer validate --strict` réussi et résolution Artisan des commandes confirmée.
- Revue indépendante : correction avant clôture des tentatives notifications, connexions invalides, collisions physiques, ordre de priorité, timeout CLI trop court, journalisation post-succès et procédure de drainage.
- Déploiement et rollback : consommateurs préprovisionnés ou trafic maintenu pendant la bascule ; `retry_after=300` et release/commandes de drainage conservés jusqu’au vidage des nouvelles files.
- Limites : aucune CI de cette branche, aucune installation de processus persistants et aucun canari staging/production ne sont encore consignés. Les durées réelles des workloads bulk doivent être mesurées pendant les canaris.
- Verdict : **validation locale réussie ; P0-005 passe en validation et reste ouvert jusqu’aux preuves CI et exploitation**.

## VALID-P0-005-CI-2026-07-27 — Gates GitHub vertes

- Ticket : `MLK-IMP-P0-005`.
- Date : 2026-07-27.
- PR : `#133`, ciblée vers `develop`, commit fonctionnel `45015e7e` ; aucune opération sur `main`.
- Exécution GitHub Actions : `30292598379`.
- `laravel-quality` : réussi en 3 min 31 s.
- `laravel-quality-mysql` : réussi sous MySQL 8.4 en 1 min 38 s.
- `browser-smoke` : réussi sous Chromium en 2 min 07 s.
- Limites : cette preuve confirme la CI de la branche, pas l’installation ni l’activité des quatre processus persistants et pas le canari staging/production.
- Verdict : **code et gates locales/CI verts ; P0-005 reste en validation jusqu’aux preuves d’exploitation**.

## PREP-P0-006-2026-07-27 — Protocole de baseline d’observabilité défini

- Ticket : `MLK-IMP-P0-006`.
- Date : 2026-07-27.
- Branche : `agent/p0-006-observability-baseline`, issue de `develop` ; aucune opération sur `main`.
- Type : préparation technique et documentaire du protocole ; cette entrée ne contient aucune mesure représentative et ne déclare aucun test P0-006 non exécuté comme réussi.
- Périmètre : six familles et sept scénarios exécutables — dashboard, détail client, création de réservation, création de vente, demande publique, consultation de la boutique publique et checkout de la boutique publique.
- Activation sûre : l’observabilité reste désactivée par défaut avec `OBSERVABILITY_ENABLED=false`. Une campagne recevable exige une activation explicite, le driver effectif Redis via `OBSERVABILITY_CACHE_STORE=redis` et une valeur de release dans `OBSERVABILITY_RELEASE`.
- Préflight prévu : `capacity:plan --json` vérifie le contexte complet, le catalogue de scénarios, l’activation, la release, le driver Redis exact, la lecture/écriture du cache, l’absence de perte de télémétrie, la mesurabilité des queues et l’absence de seuil queue déjà dépassé avant d’autoriser le harness.
- Contexte obligatoire : run ID, environnement, commit, fenêtre UTC, trafic, runner, `CAPACITY_BASELINE_RUNNER_HASH` égal au SHA-256 du harness approuvé, exclusions, mode, représentativité, approbation et référence, canaris P0-005 vérifiés, runtime cache/base/queue, propriétaire et validateur distincts. Le staging appartient à `CAPACITY_ALLOWED_STAGING_ENVIRONMENTS` et les scénarios d’écriture non bloqués exigent `CAPACITY_BASELINE_ISOLATED_TENANT_VERIFIED=true`.
- Ordre d’exécution prévu : `capacity:scenario:start` → harness HTTP externe approuvé avec redirections automatiques désactivées → `capacity:scenario:stop` → `capacity:result:import` → `capacity:report`. Le start refuse une fenêtre plus courte que le profil plus `CAPACITY_SCENARIO_START_BUFFER_SECONDS` et le rejeu d’une même clé dans le run.
- Snapshots de queue : `queue:health --record --json`, planifié à une cadence nominale de 60 s, doit couvrir tout l’intervalle runner avec au plus 120 s entre captures et 30 s de grâce aux extrémités. Les snapshots de début et de fin seuls ne suffisent pas.
- Preuve runner : agrégat JSON fermé `schema_version: 1`, lié au run, à l’environnement, au commit, au scénario et au profil par `manifest_hash`, avec un `runner_hash` exactement égal à `CAPACITY_BASELINE_RUNNER_HASH`. Les fichiers d’import sont placés dans le dossier contrôlé `storage/app/capacity-imports` et ne doivent pas contenir de données brutes.
- Mesures obligatoires : `attempted_requests` et `completed_requests` atteignant `profile.minimum_completed_requests`, latence client p50/p95/p99/max, temps de traitement applicatif séparé, résultats métier, erreurs, requêtes lentes, taille de réponse, nombre de requêtes SQL et santé des queues.
- Sémantique de temps : les seuils p95/p99 portent sur la latence client du runner externe ; le temps applicatif Laravel est conservé séparément pour le diagnostic et ne remplace pas le bout en bout.
- Environnement prévu : staging isolé et représentatif ; une production en lecture seule exige une approbation explicite. Aucun environnement n’est encore retenu ni déclaré prêt.
- Expurgation : le dépôt ne reçoit que des agrégats. Aucun chemin ou paramètre brut, message d’exception, SQL, binding, identifiant, secret ou donnée client directe n’est admissible dans cette preuve.
- Artefacts : les sorties brutes éventuelles restent dans un stockage contrôlé ; le journal ne conserve qu’une synthèse expurgée et un lien non sensible.
- Rollback prévu : remettre `OBSERVABILITY_ENABLED=false`, recharger la configuration, redémarrer les processus PHP persistants concernés puis confirmer l’arrêt des captures HTTP et des snapshots planifiés.
- Conditions manquantes : environnement représentatif, fenêtre de mesure, propriétaire exploitation, validateur distinct, canaris P0-005, échantillons atteignant `targets.min_samples`, charge atteignant `profile.minimum_completed_requests` et couverture temporelle complète des queues.
- Validation non exécutée dans cette entrée : aucune campagne externe, aucun import runner, aucun rapport strict représentatif et aucune validation d’exploitation P0-005. Les résultats de tests du lot P0-006 doivent être consignés séparément lorsqu’ils auront réellement été exécutés.
- Verdict : **P0-006 est techniquement préparé ; la collecte représentative, les canaris P0-005 et la validation finale restent explicitement bloqués**.

## VALID-P0-006-LOCAL-2026-07-27 — Contrôles frontend et statiques du harnais

- Ticket : `MLK-IMP-P0-006`.
- Date : 2026-07-27.
- Branche : `agent/p0-006-observability-baseline`, issue de `develop` ; aucune opération sur `main`.
- Commit fonctionnel contrôlé : `12b9ecde4e6315cf95574ae3e66bdd789658c0f5`.
- Build frontend : build Vite de production réussi en 2 min 19 s.
- E2E ciblé : `tests/e2e/superadmin-dashboard-health-smoke.spec.js` réussi, 1 test sur 1, avec les états inconnus de queue et de stockage couverts ; test en 26,8 s, exécution totale en 2,0 min.
- Contrôles statiques : `git diff --check` réussi ; les trois catalogues i18n modifiés et l’exemple de résultat runner sont des JSON valides ; le scan des ajouts ne détecte aucun motif de secret.
- Revue : les garde-fous de concurrence, d’isolation du tenant, d’identité du runner, de fenêtre, de couverture queue et de contexte CLI ont été relus sans bloquant certain restant.
- Limite locale : les tests PHP, Pint et PHPStan n’ont pas été exécutés localement dans cette passe ; ils doivent être rejoués par la CI sur un checkout propre avant intégration.
- Limite opérationnelle : cette preuve ne contient aucune charge représentative, aucun import runner réel, aucun rapport strict de campagne et aucun canari P0-005 d’exploitation.
- Verdict : **frontend ciblé et contrôles statiques réussis ; validation code complète en attente de CI, validation P0-006 représentative toujours bloquée**.

## VALID-P0-006-CORRECTIVE-LOCAL-2026-08-01 — Correctif CI et durcissement de la gate PHP

- Ticket : `MLK-IMP-P0-006` et gate qualité PHP transversale.
- Date : 2026-08-01.
- Branche : `agent/fix-p0-006-php-format`.
- Commits techniques validés : `af133457` et `dbe50152`.
- Cause vérifiée : l’exécution GitHub Actions `30369949212` liée à la PR #134 a terminé `laravel-quality` en échec sur cinq tests d’observabilité ; `laravel-quality-mysql` a réussi et `browser-smoke` a été ignoré après cet échec.
- Correctifs : isolation du scope et du cache des tests d’observabilité, rafraîchissement déterministe du registre des routes, sélection Git NUL-safe des fichiers PHP, refus des fichiers PHP partiellement indexés et des suppressions non indexées, distinction des diffs `direct` et `merge-base`, et exécution fail-closed du proxy PHP de Pint.
- Gate PHP du lot technique : `composer qa:format` réussi sur le commit `dbe50152`, avec six fichiers PHP sélectionnés et aucune modification de format produite.
- Tests ciblés Observability/Capacity : **56 réussis, 326 assertions**.
- Analyse statique : **PHPStan réussi, 850 fichiers analysés, zéro erreur**.
- Non-régression : **Pest complet réussi, 1 240 tests et 12 654 assertions**.
- Contrôle de diff : `git diff --check` réussi.
- Validation distante : non exécutée, car les deux commits techniques et le présent suivi ne sont pas encore poussés et aucune PR corrective n’existe.
- Verdict : **correctif validé localement ; validation distante requise. P0-006 reste ouvert jusqu’au push, à la PR vers `develop`, à une CI verte et à la campagne représentative**.

## VALID-P0-005-P0-006-LOCAL-2026-08-04 — Harnais finaux et gates locales

- Tickets : `MLK-IMP-P0-005`, `MLK-IMP-P0-006` et gate qualité PHP transversale.
- Date : 2026-08-04.
- Branche : `agent/fix-p0-006-php-format`.
- Commit technique validé : `6af521e`.
- P0-005 : `queue:workload-canary` vérifie chaque file d’un profil par un job sans effet métier, un vrai worker, la connexion/file observée et l’identité environnement/release/commit. Les modes `internal_test` et dry-run sont inéligibles comme preuves opérationnelles. Tests ciblés : **33 tests, 457 assertions, tous réussis**.
- P0-006 : runner Node 20 et import fermés en résultat `schema_version: 3`, fixture v2 liée par SHA-256, origines HTTPS allowlistées, empreinte du contexte, cadence/timeout signés, redirections interdites, préflight/catalogue/blocage revérifiés et cycle `start` → `stop` obligatoire. Tests Node : **12/12 réussis**.
- Suite ciblée combinée queues/capacité/observabilité : **93 tests, 798 assertions, tous réussis**.
- Gate PHP après indexation complète : `composer qa:format` **réussi**, 18 fichiers PHP sélectionnés, aucun échec Pint.
- Analyse statique : **PHPStan réussi, 852/852 fichiers analysés, zéro erreur**.
- Non-régression SQLite : **Pest complet réussi, 1 284 tests et 13 180 assertions** avec `COMPOSER_PROCESS_TIMEOUT=1200` ; une première tentative avait seulement atteint le délai Composer de 300 s, sans échec de test.
- Compatibilité MySQL isolée : **137 tests, 1 093 assertions, tous réussis** ; la base temporaire `mlkpro_v3_test` a été supprimée par le script à la fin.
- Dépendances : `composer audit` sans avis de sécurité et `npm audit` avec **0 vulnérabilité**.
- Frontend : build Vite réussi, **2 605 modules** transformés.
- E2E : une première suite a produit un échec intermittent du contrôle d’URL après un filtre alors que l’écran était correctement filtré ; sans modification de code, le test isolé a réussi **1/1**, puis le rejeu complet a réussi **7/7**. L’incident et le rejeu ne sont pas masqués.
- Contrôle de diff : `git diff --check` et `git diff --cached --check` réussis avant le commit.
- Rectificatif : les anciennes entrées décrivant un résultat runner v1 restent historiques ; le contrat courant qui les remplace est le résultat v3 avec fixture v2 du commit `6af521e`.
- Validation distante : absente, car la branche n’est pas poussée et aucune CI ne couvre encore `6af521e`.
- Limite opérationnelle : aucune installation staging des quatre processus, aucun canari P0-005 éligible, aucune campagne P0-006, aucun import représentatif et aucun rollback réel ne sont consignés.
- Verdict : **implémentation technique locale P0-005/P0-006 terminée et gates locales vertes ; tickets canoniques toujours en validation faute de CI distante et de preuves d’exploitation**.

## VALID-P0-005-P0-006-CI-2026-08-04 — Validation distante de la PR #135

- Tickets : `MLK-IMP-P0-005`, `MLK-IMP-P0-006` et gates qualité transversales.
- Date : 2026-08-04.
- Pull request : [#135 — Harden Phase 0 queue and capacity validation](https://github.com/Lenenba/mlkpro_v3/pull/135), cible `develop`, branche `agent/fix-p0-006-php-format`.
- SHA contrôlé : `02dc5c355dc9a08ce5afa8f4c09bb2ce66de49de`, qui contient le commit technique `6af521ee90fecb6927545a3985dcbc59b1a3bac6`.
- Workflow GitHub Actions : `quality`, exécution `30911394066`.
- `laravel-quality` : **réussi**, durée 4 min 01 s.
- `laravel-quality-mysql` : **réussi**, durée 1 min 49 s.
- `browser-smoke` : **réussi**, durée 1 min 56 s.
- État GitHub : PR fusionnable sur le plan Git ; créée en brouillon selon la procédure de publication contrôlée.
- Limite : cette CI valide le code et les tests du lot, pas le déploiement des quatre processus, les canaris staging, la campagne représentative ou le rollback.
- Verdict : **validation technique locale et distante complète ; P0-005/P0-006 restent ouverts uniquement sur leurs preuves d’exploitation et de campagne**.

## AUDIT-P0-STAGING-ACCESS-2026-08-04 — Inventaire des moyens d’exploitation disponibles

- Date : 2026-08-04.
- GitHub : seul le workflow actif `quality` est déclaré ; aucun workflow de déploiement n’est disponible.
- Environnements GitHub : liste vide.
- Variables et secrets GitHub Actions au niveau dépôt : listes vides. Seuls les noms ont été interrogés ; aucune valeur sensible n’a été lue ni exposée.
- Checkout local : `APP_ENV=local`. Les variables `OBSERVABILITY_*`, `CAPACITY_BASELINE_*` et `ASYNC_QUEUE_CANARY_*` nécessaires au staging sont absentes.
- Dépôt : aucun script ou manifeste exploitable Forge, Supervisor, systemd, Horizon ou Docker n’a été trouvé pour installer et contrôler les quatre processus persistants.
- Conséquence : aucun endpoint staging, fournisseur, gestionnaire de processus, tenant isolé, fenêtre ou identité de release n’est accessible depuis le périmètre actuel. Exécuter localement avec une étiquette `staging` fabriquerait une preuve non représentative et est donc interdit.
- Déblocage requis : fournir l’environnement staging et son mode d’accès/déploiement, nommer le propriétaire exploitation et le validateur distinct, puis approuver la fenêtre et `MLK-DEC-009`.
- Verdict : **blocage externe confirmé et documenté ; aucune action locale supplémentaire ne peut produire les preuves opérationnelles exigées**.

## VALID-P0-007-GO-CONDITIONNEL-2026-08-04 — Sortie Phase 0 sous dérogation

- Ticket : MLK-IMP-P0-007.
- Date : 2026-08-04.
- Référence technique : lot P0-005/P0-006 intégré dans develop par le commit `e91adf8`.
- Environnement : aucune action staging ou production exécutée ; le constat d’accès reste celui de AUDIT-P0-STAGING-ACCESS-2026-08-04.
- Décideur et signataire : Jules Roger Sombangnen, responsable Produit, Technique et Exploitation.
- Trace de signature : déclaration explicite du décideur dans la conversation de pilotage du 2026-08-04.
- Décision : **GO P0-007 sous dérogation** ; l’ouverture de la Phase 1 est autorisée.
- Dérogation : P0-005 exploitation et P0-006 campagne représentative restent sans preuve opérationnelle faute de staging. Les risques acceptés sont des workers non validés en exploitation et l’absence de baseline dynamique représentative.
- Limites impératives : aucun test de charge, canari opérationnel, runner de capacité ni écriture en production sans nouvelle approbation explicite, datée et bornée. Cette validation ne vaut ni déploiement, ni validation staging, ni conformité opérationnelle.
- Validation distincte : l’exception au validateur distinct P0-006 est acceptée jusqu’au 2027-08-04 ; le décideur assume seul cette responsabilité.
- Échéance : 2027-08-04, sans renouvellement automatique. Avant cette date, fournir un staging, exécuter les quatre canaris P0-005 avec redémarrage/rollback, puis collecter/importer les sept scénarios P0-006 et archiver un rapport strict.
- Référence de gouvernance : MLK-DEC-010 ; MLK-DEC-009 reste proposée pour la future campagne staging.
- Verdict : **Phase 0 clôturée sous dérogation ; Phase 1 ouverte. Les preuves opérationnelles P0-005/P0-006 restent dues et ne sont pas décrites comme vertes.**

## VALID-P1-001-LOCAL-2026-08-04 — Initialisation Preline unique et ciblée

- Ticket : `MLK-IMP-P1-001`.
- Date : 2026-08-04.
- Commit technique : `71c1252c64b92d5153fa01f79afe146dd0a1628c` sur `develop`.
- Portée : remplacement du mixin Vue global et du délai de 100 ms par un planificateur `requestAnimationFrame` au montage racine et après navigation Inertia. Les demandes répétées avant le rendu stable sont coalescées.
- Correctif de navigation : les instances d’overlay Preline dont les déclencheurs ont été remplacés par Inertia sont fermées de façon synchrone, détruites, puis initialisées sur les nouveaux déclencheurs. Cela évite un backdrop ou un verrouillage de défilement orphelin sur la navigation mobile.
- Vérification unitaire ciblée : `node --test tests/Node/P1001PrelineInitializerTest.mjs` — **4/4 réussis**.
- Vérification navigateur ciblée : `tests/e2e/preline-navigation.spec.js` — navigation Inertia mobile, sidebar, backdrop, nouvel ouvreur et touche Échap — **1/1 réussi**.
- Non-régression : `npm run qa:e2e` — build Vite réussi puis **8/8 scénarios Playwright réussis**.
- Contrôle de livraison : `git diff --check` réussi. Aucun fichier PHP n’est modifié ; la gate `composer qa:format` n’est donc pas applicable à ce lot.
- Mesure statique : le chemin `vueApp.mixin({ mounted() { … } })` passe de 1 à 0 et le délai fixe de 100 ms du boot Preline passe de 1 à 0. Cette preuve ne constitue pas une mesure dynamique représentative, absente sous la dérogation `MLK-DEC-010`.
- Environnement : exécution locale avec build Vite et environnement Playwright isolé ; aucune écriture, charge ou action staging/production.
- Rollback : revert isolé du commit technique ci-dessus ; aucune migration ni donnée persistante impliquée.
- Validation indépendante : aucune signature humaine distincte n’est encore consignée.
- Verdict : **validation technique locale réussie ; P1-001 reste en validation jusqu’à l’acceptation humaine.**

## VALID-P1-001-ACCEPTATION-HUMAINE-2026-08-04 — Clôture

- Ticket : `MLK-IMP-P1-001`.
- Décideur : Jules Roger Sombangnen, responsable Produit, Technique et Exploitation.
- Source : acceptation explicite dans la conversation de pilotage (« parfait, on continue ») après remise de `VALID-P1-001-LOCAL-2026-08-04`.
- Décision : la preuve locale Preline est acceptée et le démarrage de P1-002 est autorisé.
- Portée : cette acceptation ne transforme pas la mesure statique en baseline dynamique représentative et ne modifie pas la dérogation `MLK-DEC-010`.
- Verdict : **P1-001 terminé ; P1-002 ouvert.**

## VALID-P1-002-LOCAL-2026-08-04 — Groupes de routes Ziggy

- Ticket : `MLK-IMP-P1-002`.
- Date : 2026-08-04.
- Commit technique : `477894887cd99fb9685daecaa281499ae6e9c973` sur `develop`.
- Portée : cartes Ziggy distinctes pour les surfaces public, portail et admin ; sélection au rendu HTML par route et rôle ; carte complète limitée aux frontières authentification/onboarding.
- Franchissements protégés : navigation public/admin/portail vers authentification ou onboarding, sortie de ces écrans, logout, login démo, suppression de compte et expiration d’espace démo. L’admin et le superadmin partagent volontairement une surface pour conserver l’impersonation sans transition de carte.
- Audit de couverture : 494 noms `route()` littéraux dans `resources/js`, aucun absent de l’union des groupes. Les usages publics conditionnels de `dashboard` et `portal.orders.index` reçoivent la carte admin ou portail selon la session ; `offers.*` reste interne.
- Mesure statique locale, même sérialisation Ziggy :

| Carte | Routes | JSON (octets) | Écart vs complète |
|---|---:|---:|---:|
| Complète | 711 | 83 324 | référence |
| Public | 81 | 8 241 | -90,1 % |
| Portail (`public` + `portal`) | 134 | 14 793 | -82,2 % |
| Admin | 590 | 69 248 | -16,9 % |

- Vérification PHP ciblée : `php artisan test tests/Feature/ZiggyRouteGroupsTest.php` — **6 tests réussis, 39 assertions** ; couvre les rendus HTML public/portail/admin et les réponses `Inertia::location` aux frontières logout, démo et suppression de compte.
- Vérification Node ciblée : `node --test tests/Node/P1001PrelineInitializerTest.mjs` — **4/4 réussis**.
- Vérification navigateur et build : `npm run qa:e2e` — build Vite réussi puis **11/11 scénarios Playwright réussis**, incluant boutique publique, public → onboarding et login → dashboard.
- Gate PHP : `composer qa:format` exécuté après indexation complète des 8 fichiers PHP modifiés — **réussi**. `git diff --cached --check` puis `git diff --check` réussis.
- Environnement : validations locales uniquement ; aucune écriture, charge ni action staging/production.
- Rollback : revert isolé du commit technique ci-dessus ; aucune migration ni donnée métier persistante.
- Validation restante : acceptation humaine de P1-002 avant l’ouverture de P1-003.
- Verdict : **validation technique locale réussie ; P1-002 reste en validation jusqu’à l’acceptation humaine.**

## VALID-P1-002-ACCEPTATION-HUMAINE-2026-08-04 — Clôture

- Ticket : `MLK-IMP-P1-002`.
- Date : 2026-08-04.
- Décideur : Jules Roger Sombangnen, responsable Produit, Technique et Exploitation.
- Source : acceptation explicite dans la conversation de pilotage : « J’accepte P1-002 — Groupes de routes Ziggy, ses preuves locales et son rollback. J’autorise le GO P1-003 — Traductions chargées par domaine. »
- Décision : la preuve locale, les mesures statiques, les tests et le rollback de P1-002 sont acceptés ; l’exécution de P1-003 est autorisée.
- Portée : cette acceptation ne transforme pas les mesures statiques en baseline dynamique représentative, ne modifie pas la dérogation `MLK-DEC-010` et n’autorise ni staging, ni production, ni test de charge.
- Verdict : **P1-002 terminé ; P1-003 ouvert.**

## VALID-P1-003-LOCAL-2026-08-04 — Traductions chargées par domaine

- Ticket : `MLK-IMP-P1-003`.
- Date : 2026-08-04.
- Commit technique : `a27fdea4e1b54fdf41060bcb4880faa0672c2c2f` sur `develop`.
- Portée : modules i18n asynchrones par domaine, sélection par page Inertia, fallback anglais conservé, préchargement avant résolution de page et avant changement de langue, avec repli sûr sur le catalogue complet pour toute page inconnue.
- Mesure locale statique du **payload i18n additionnel** (entrée applicative déjà chargée exclue) :
  - Dashboard FR + fallback EN : catalogues complets historiques 142 actifs / 769 852 o bruts / 269 430 o gzip ; domaines 40 actifs / 227 318 o bruts / 79 734 o gzip ; réduction **-70,5 % brute / -70,4 % gzip**.
  - Boutique publique ES + fallback EN : catalogues complets historiques 142 actifs / 722 981 o bruts / 255 431 o gzip ; domaines 32 actifs / 66 427 o bruts / 28 965 o gzip ; réduction **-90,8 % brute / -88,7 % gzip**.
  - Protocole : `scripts/measure-i18n-domain-loading.mjs --mode domain` mesure les actifs domaine ; `--mode legacy-full-catalog` mesure le repli historique. Le mode est explicite car Vite conserve les chunks de domaine dans le manifeste même quand le repli est compilé.
- Vérification Node : `node --test tests/Node/P1003I18nDomainLoaderTest.mjs` — **4/4 réussis**.
- Build : `vite build` réussi avec le mode domaines par défaut et avec `VITE_I18N_DOMAIN_LOADING=false` pour le rollback.
- Vérification navigateur sur les actifs construits : **2/2 scénarios Playwright réussis** en mode domaines, puis **2/2 réussis** avec le rollback compilé ; parcours couvert : accueil public FR → ES → EN sans clé brute et dashboard authentifié avec préchargement de la langue cible.
- Gate PHP : non applicable ; ce lot ne modifie aucun fichier PHP. `git diff --cached --check` et `git diff --check` réussis.
- Environnement : validations locales uniquement ; aucune écriture, charge ni action staging/production.
- Rollback : positionner `VITE_I18N_DOMAIN_LOADING=false` dans l’environnement de build, reconstruire puis déployer les actifs Vite ; les catalogues complets historiques sont alors utilisés. En alternative, revert isolé du commit technique ; aucune migration ni donnée métier persistante.
- Validation restante : acceptation humaine explicite de P1-003 avant la clôture de la Phase 1 ; P1-004 était parallélisable après P1-002.
- Verdict : **validation technique locale réussie ; P1-003 reste en validation locale jusqu’à l’acceptation humaine.**

## VALID-P1-004-LOCAL-2026-08-04 — Images et polices critiques

- Ticket : `MLK-IMP-P1-004`.
- Date : 2026-08-04.
- Commit technique : `4fa1ac3f` sur `develop`.
- Portée : 25 JPEG du catalogue stock public reçoivent AVIF/WebP en `640w` et `1280w` (100 variantes). Le composant `PublicResponsiveImage` ne transforme que les chemins locaux connus, expose `picture`, `srcset`, `sizes`, `width` et `height`, puis conserve le JPEG d’origine comme fallback. Toute URL tenant, CDN externe ou `data:` demeure strictement inchangée.
- Chemins critiques : accueil, page produit/solution, boutique et vitrine utilisent le composant ; la première image de chaque carrousel est `eager` avec `fetchpriority=high`, les suivantes sont `lazy`. Les images de contenu restent différées. La vitrine conserve `object-fit: cover` à travers la frontière de composant Vue.
- Police : suppression de l’`@import` Bunny dans le CSS ; ajout d’une préconnexion et d’une feuille Montserrat directe avec `display=swap`, sans JavaScript inline.
- Mesure locale statique : JPEG stock historiques 7 843 029 o ; variantes AVIF/WebP ajoutées 6 132 235 o. Exemple déterministe : `hero-team.jpg` 301 175 o, AVIF 1280w 107 488 o (-64,3 %), AVIF 640w 40 433 o (-86,6 %). Ce constat de fichier n’est ni un LCP, ni un CLS, ni une baseline dynamique représentative.
- Vérification Node : `node --test tests/Node/P1004PublicMediaTest.mjs` — **3/3 réussis** ; manifeste aligné, 100 variantes attendues, fallback sécurisé, polices et priorités contrôlés.
- Générateur : `php scripts/generate-public-image-variants.php --check` — **100 variantes vérifiées**, existence et dimensions comprises.
- Build : `vite build` réussi, 2 611 modules transformés.
- Vérification navigateur : Playwright complet — **16/16 scénarios réussis**, incluant accueil desktop/mobile, sélection AVIF/WebP avec MIME correspondant, boutique publique et vitrine avec `object-fit: cover`.
- Gate PHP : le script de `composer qa:format` (`php scripts/run-pint-diff.php`, Composer non exposé dans cet environnement) a été exécuté après indexation complète des 3 fichiers PHP modifiés — **réussi**. `git diff --cached --check` et `git diff --check` réussis.
- Non-régression PHP finale : `php -d memory_limit=512M vendor/bin/pest` — **1 297 tests réussis, 13 270 assertions**, 412,39 s.
- Environnement : validations locales uniquement ; aucune écriture, charge ni action staging/production.
- Rollback : revert isolé du commit technique ci-dessus. Les JPEG historiques sont conservés ; aucune migration ni donnée métier persistante.
- Validation restante : acceptation humaine de P1-004. La preuve ne prétend pas valider toutes les images personnalisées ni améliorer dynamiquement les Web Vitals avant P0-006.
- Verdict : **validation technique locale réussie ; P1-004 reste en validation locale jusqu’à l’acceptation humaine.**

## VALID-P1-005-LOCAL-2026-08-04 — Budgets frontend CI

- Ticket : `MLK-IMP-P1-005`.
- Date : 2026-08-04.
- Commit technique : `2ff77eea71b591523cd1f8c4780a2295bd5109ed` sur `develop`.
- Portée : `config/frontend-budgets.json` versionne les baselines et plafonds des actifs JavaScript, CSS et i18n bruts/gzip. Le script suit l’entrée `app` + l’entrée de page et leurs imports **statiques** Vite, dédupliqués ; les imports dynamiques ne font pas partie du budget initial. Les domaines i18n de la locale et du fallback sont isolés des actifs déjà comptés par la route.
- Parcours : accueil, connexion, dashboard, détail client, planning, boutique publique et vitrine publique. Les quatre profils d’images concernent exclusivement les 25 images stock locales AVIF/WebP en `640w` et `1280w` ; les images tenant, uploads et CDN sont explicitement hors périmètre.
- Tolérance : chaque plafond est `ceil(baseline × 1,05)`. À titre de baseline gzip : accueil JS/CSS/i18n `253 063 / 51 623 / 29 375 o`, connexion `216 138 / 36 180 / 8 628 o`, dashboard `286 958 / 36 839 / 79 734 o`, détail client `318 991 / 36 839 / 122 454 o`, planning `289 711 / 36 839 / 82 754 o`, boutique `274 564 / 47 655 / 29 375 o`, vitrine `249 496 / 48 137 / 29 375 o`.
- Images encodées locales : AVIF `640w` `727 672 o`, AVIF `1280w` `2 046 667 o`, WebP `640w` `915 912 o`, WebP `1280w` `2 441 984 o`, chacune avec un plafond de +5 % et 25 fichiers attendus.
- Politique : après le build, la CI compare la configuration au SHA de base de la PR/push. Une hausse de baseline/plafond, une suppression, un changement d’identité ou de version est refusé sans `FRONTEND_BUDGET_EXCEPTION=MLK-DEC-XXX`. Cette décision doit être explicitement acceptée, active, non expirée et dédiée à P1-005 **et** aux budgets frontend. Aucune dérogation n’a été utilisée pour créer cette première baseline.
- Vérification Node : `node --test tests/Node/P1005FrontendBudgetsTest.mjs` — **3/3 réussis** ; couverture des sept parcours, imports statiques/déduplication, isolation i18n et refus d’une dérogation générique ou expirée.
- Build et garde : `vite build` — **2 611 modules transformés** ; `node scripts/check-frontend-budgets.mjs --base-ref HEAD` — **réussi** pour les sept parcours et les quatre profils. Le SHA de base ne contenait pas encore de configuration P1-005 : statut attendu `base_config_absent` pour l’initialisation.
- CI : `.github/workflows/quality.yml` lance le test Node puis, après `npm run qa:build`, `npm run qa:frontend-budgets` avec le SHA de base. La CI distante n’a pas encore été observée dans cette preuve locale.
- Gate PHP : non applicable ; aucun fichier PHP n’est modifié. `git diff --cached --check` et `git diff --check` réussis.
- Environnement : validations locales uniquement ; aucune écriture, charge, action staging ou production.
- Rollback : revert isolé du commit technique ; il retire le garde et son étape CI, sans migration ni donnée métier persistante.
- Validation restante : CI distante verte, puis acceptation humaine de P1-005 avec P1-003/P1-004 avant clôture de Phase 1. Le garde n’est pas une baseline dynamique et ne démontre aucun LCP, INP ou CLS représentatif avant P0-006.
- Verdict : **validation technique locale réussie ; P1-005 reste en validation locale jusqu’à la CI distante et l’acceptation humaine.**

## Gate d’entrée Phase 0 — Archive historique non rétroactive

Ce gabarit initial n’a pas été signé à l’ouverture. Il est conservé comme dette de gouvernance et ne doit pas être rempli rétroactivement sans preuve datée.

- ID : `GATE-P0-ENTRY`
- Date :
- Commit :
- Environnement :
- Responsable technique :
- Responsable exploitation :
- Validateur métier :
- Scope Phase 0 approuvé : oui / non
- Accès Twilio confirmé : oui / non
- Environnement de baseline choisi :
- Fenêtre de déploiement :
- Rollback owner :
- Décision : GO / NO-GO
- Commentaires :

## Tableau de suivi opérationnel Phase 0

Ce tableau détaille la sortie de la Phase 0. La suite du programme, jusqu’à la Phase 4, est visible dans le [suivi global](SUIVI_GLOBAL.md).

État constaté le 2026-08-04. Les lignes sont ordonnées selon l’avancement acquis puis l’ordre de dépendance des prochaines actions. `Terminé` signifie que la preuve attendue est consignée ; `Dérogation acceptée` signifie que la preuve reste absente, le risque est explicitement accepté et l’échéance est ferme.

| Ordre | Avancement | État | Preuve actuelle | Prochaine action |
|---:|---|---|---|---|
| 1 | P0-001 — Rotation et invalidation Twilio | Terminé | `VALID-P0-001-CLOSEOUT-2026-07-17`, commit `8da7b9c` | Aucune ; conserver les preuves expurgées |
| 2 | P0-002 — Baseline gelée | Terminé | `BASELINE-P0-2026-07-17` ; replays Node 20 et MySQL dans `VALID-P0-003-CLOSEOUT-2026-07-27` | Aucune |
| 3 | P0-003 — Dépendances PHP sécurisées | Terminé | PR #131, merge `28fc253f`, audit sans avis et gates vertes | Maintenir la surveillance des avis Composer |
| 4 | P0-004 — Dépendances JavaScript sécurisées | Terminé | PR #132, commit `ccaf150`, audits et gates locales/CI verts | Maintenir la surveillance des avis npm |
| 5 | P0-005 — Technique queues/workers | Terminé techniquement | Harnais `6af521e`, 33 tests/457 assertions ; lot intégré dans develop par `e91adf8` | Conserver les gates et préparer la preuve d’exploitation avant l’échéance |
| 6 | P0-006 — Technique observabilité/runner | Terminé techniquement | Runner/import v3, Node 16/16, validations locales et trois jobs CI PR #135 verts | Conserver les gates et préparer la campagne staging avant l’échéance |
| 7 | P0-005 — Déploiement des quatre processus et canaris | Dérogation acceptée jusqu’au 2027-08-04 | Procédure et harnais prêts ; aucune installation persistante, sortie canari opérationnelle, fenêtre ni preuve de rollback | Fournir staging, déployer, exécuter quatre canaris, santé/métier/redémarrage et rollback |
| 8 | P0-006 — Campagne représentative des sept scénarios | Dérogation acceptée jusqu’au 2027-08-04 | Aucun résultat v3 importé ni rapport strict représentatif ; MLK-DEC-009 reste proposée | Fournir staging, nommer un validateur distinct, approuver le trafic, collecter, importer et archiver le rapport |
| 9 | P0-007 — Revue et signatures de sortie | Terminé — GO sous dérogation | VALID-P0-007-GO-CONDITIONNEL-2026-08-04 ; décision unique de Produit/Technique/Exploitation | Réévaluer la dérogation à l’échéance ou après livraison des preuves |
| 10 | P1-001 — Initialisation Preline unique et ciblée | Terminé | `VALID-P1-001-LOCAL-2026-08-04` et `VALID-P1-001-ACCEPTATION-HUMAINE-2026-08-04` ; commit `71c1252`, Node 4/4 et Playwright 8/8 verts | Aucun ; P1-002 est ouvert |
| 11 | P1-002 — Groupes de routes Ziggy | Terminé | `VALID-P1-002-LOCAL-2026-08-04` et `VALID-P1-002-ACCEPTATION-HUMAINE-2026-08-04` ; commit `4778948`, Feature 6/39, Node 4/4 et Playwright 11/11 verts | Aucun ; P1-003 est ouvert |
| 12 | P1-003 — Traductions chargées par domaine | En validation locale | `VALID-P1-003-LOCAL-2026-08-04` ; commit `a27fdea4`, Node 4/4, Vite et Playwright 2/2 dans les deux modes | Obtenir l’acceptation humaine de P1-003 avant la clôture de Phase 1 ; P1-004 parallélisable après P1-002 |
| 13 | P1-004 — Images et polices critiques | En validation locale | `VALID-P1-004-LOCAL-2026-08-04` ; commit `4fa1ac3f`, 100 variantes vérifiées, Node 3/3, Vite, Pest 1 297/13 270 et Playwright 16/16 verts | Obtenir l’acceptation humaine de P1-004 ; baseline dynamique P0-006 toujours reportée |
| 14 | P1-005 — Budgets frontend CI | En validation locale | `VALID-P1-005-LOCAL-2026-08-04` ; commit `2ff77eea`, sept parcours, quatre profils locaux AVIF/WebP, Node 3/3, Vite et garde réelle verts | Observer la CI distante puis obtenir l’acceptation humaine ; toute dérogation doit être dédiée à P1-005, acceptée et active |

## Gate de sortie Phase 0 — Matrice factuelle au 2026-08-04

- ID : `GATE-P0-EXIT-2026-08-04`
- Commit technique intégré : `e91adf8`.
- Environnement validé : local pour le code ; aucun staging validé. L’absence de staging est une dérogation acceptée, pas une validation opérationnelle.

| Critère | État | Preuve ou blocage |
|---|---|---|
| Ancien jeton Twilio invalidé | Réussi | `VALID-P0-001-CLOSEOUT-2026-07-17` |
| Avis Composer/npm de production | Réussi localement | Audits du 2026-08-04 sans avis/vulnérabilité |
| Topologie et retry plan scan | Réussi localement | PR #133 et `6af521e` |
| Consommation réelle des queues | Dérogation acceptée jusqu’au 2027-08-04 | Aucun staging ni quatre canaris opérationnels ; risque accepté dans MLK-DEC-010 |
| PHPStan | Réussi localement | 852/852, zéro erreur |
| Pest complet | Réussi localement | 1 284 tests, 13 180 assertions |
| MySQL ciblé | Réussi localement | 137 tests, 1 093 assertions |
| Runner Node | Réussi localement | 12/12 |
| Build Vite | Réussi localement | 2 605 modules |
| Playwright | Réussi au rejeu | 7/7 après un échec intermittent consigné |
| CI distante du lot | Réussi | PR #135, SHA `02dc5c3`, workflow `30911394066`, trois jobs verts |
| Baseline capacité complète | Dérogation acceptée jusqu’au 2027-08-04 | Staging, sept résultats v3/imports et rapport strict absents ; aucune mesure n’est réputée verte |
| Rollout et rollback | Dérogation acceptée jusqu’au 2027-08-04 | Aucune preuve d’exploitation P0-005/P0-006 ; aucune charge ou écriture production n’est autorisée |
| Signatures | GO sous dérogation signé | Jules Roger Sombangnen assume Produit, Technique et Exploitation ; VALID-P0-007-GO-CONDITIONNEL-2026-08-04 |

- Recommandation documentaire : **NO-GO remplacé par la décision humaine GO sous dérogation**.
- Décision humaine : **signée dans la conversation de pilotage du 2026-08-04** par Jules Roger Sombangnen (Produit, Technique et Exploitation) ; échéance de la dérogation : 2027-08-04.

## Gabarit de validation d’un ticket

```markdown
## VALID-YYYY-MM-DD-XXX — Ticket

- Ticket : MLK-IMP-PN-XXX
- Date/heure :
- Commit ou déploiement :
- Environnement :
- Responsable :
- Validateur :
- Baseline avant :
- Changement contrôlé :
- Tests ciblés :
- Non-régression :
- Mesures après :
- Écart avant/après :
- Vérification métier :
- Rollback testé : oui / non
- Risques ou exceptions :
- Preuves expurgées :
- Verdict : validé / refusé / bloqué
```

## Gabarit de mesure avant/après

| Indicateur | Avant | Après | Variation | Méthode identique ? | Verdict |
|---|---:|---:|---:|---|---|
| p50 |  |  |  | oui/non |  |
| p95 |  |  |  | oui/non |  |
| p99 |  |  |  | oui/non |  |
| erreurs |  |  |  | oui/non |  |
| requêtes SQL |  |  |  | oui/non |  |
| mémoire |  |  |  | oui/non |  |
| taille transférée |  |  |  | oui/non |  |
| temps de tâche utilisateur |  |  |  | oui/non |  |

## Historique des gates

| Gate | Date | Décision | Validateurs | Commentaire |
|---|---|---|---|---|
| AUDIT-2026-07-16 | 2026-07-16 | Phase 0 requise | Codex, analyse seulement | Baseline production insuffisante |
