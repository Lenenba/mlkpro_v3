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
- Base livrée : `main` au commit `7b76232`, incluant la PR `#129` fusionnée.
- Commit du lot : `b7efe04` sur `agent/twilio-canary-harness`.
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
| MLK-IMP-P0-001 | En validation — secondaire, SMS et harnais validés | Demandeur / Codex pour les contrôles | Demandeur | Local, `b7efe04` | `VALID-P0-001-HARNESS-2026-07-17` | Sender WhatsApp, canaris réels, promotion et rejet de l’ancien jeton requis |
| MLK-IMP-P0-002 | Pré-baseline enregistrée | Codex | Demandeur | Local, `d89ad55` | `PRECHECK-P0-2026-07-16` | Attente fermeture P0-001 |
| MLK-IMP-P0-003 | À valider |  |  |  |  |  |
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
