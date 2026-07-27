# Journal des validations — programme d’amélioration MLK Pro

Dernière mise à jour : 2026-07-17

## Règles de preuve

- Ne jamais enregistrer de secret, jeton, mot de passe, donnée personnelle directe ou URL contenant des identifiants.
- Identifier chaque résultat par date, commit Git, environnement et responsable.
- Lier les sorties volumineuses à un artefact CI ou à un emplacement contrôlé plutôt que de tout coller ici.
- Une validation manuelle indique le scénario, le résultat attendu, le résultat observé et le validateur.
- Une exception possède un propriétaire, une justification et une date d’expiration.
- Les valeurs avant/après utilisent la même méthode, le même environnement et un volume comparable.

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

## Gate d’entrée Phase 0 — À compléter

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

## Suivi des tickets Phase 0

| Ticket | Statut | Responsable | Validateur | Commit/déploiement | Preuve | Verdict |
|---|---|---|---|---|---|---|
| MLK-IMP-P0-001 | Terminé | Demandeur / Codex pour les contrôles | Demandeur | Branche issue de `develop`, `8da7b9c` | `VALID-P0-001-CLOSEOUT-2026-07-17` | Validé |
| MLK-IMP-P0-002 | Terminé avec replays requis avant sortie de phase | Codex | Demandeur | Local, `8da7b9c` | `BASELINE-P0-2026-07-17` | Baseline gelée ; Node 20 et MySQL à rejouer |
| MLK-IMP-P0-003 | En validation | Codex | Demandeur | Base `197599f`, lot local non commité | `RESUME-P0-003-2026-07-27` | Audit zéro avis et Laravel 12.64 validés ; installation propre CI, MySQL et Node 20 ouverts |
| MLK-IMP-P0-004 | À valider |  |  |  |  |  |
| MLK-IMP-P0-005 | À valider |  |  |  |  |  |
| MLK-IMP-P0-006 | À valider |  |  |  |  |  |
| MLK-IMP-P0-007 | À valider |  |  |  |  |  |

## Gate de sortie Phase 0 — À compléter

- ID : `GATE-P0-EXIT`
- Date :
- Commit livré :
- Environnement :
- Ancien jeton Twilio invalidé : oui / non / preuve contrôlée
- Avis élevés/critiques de production : zéro / exceptions listées
- Queues/workers alignés : oui / non
- Retry plan scan validé : oui / non
- PHPStan : réussi / bloqué
- Pest complet : réussi / bloqué
- MySQL ciblé : réussi / bloqué
- Build Vite : réussi / bloqué
- Playwright : réussi / bloqué
- Baseline capacité complète : oui / non
- Rollback vérifié : oui / non
- Exceptions et dates d’expiration :
- Validateur produit :
- Validateur technique :
- Validateur exploitation :
- Décision : GO Phase 1 / NO-GO

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
