# Malikia Pulse — refonte globale Buffer-first

Date de cadrage : 2026-08-26

Révision : 30 — décision de capacité WP1-I qualifiée localement

Baseline auditée : branche develop, commit a54169d3d096

Branche de travail active : `feature/pulse-buffer-refonte`, créée depuis `develop@a54169d3d096`

Branche distante : `origin/feature/pulse-buffer-refonte`, checkpoint probatoire WP1-H `5ffcc685cb3e`

Statut documentaire : complet — référence active

Statut de livraison : **WP0/WP0-S validés localement — gate d’indexation vert — gate de déploiement ouvert — WP1-A/WP1-B/WP1-D authentifiés en lecture seule — WP1-H prouve réellement create, edit et delete sur un brouillon Facebook standard — WP1-I qualifie `move@draft` comme frontière négative provisoire, typée et non retryable pour le seul tuple observé ; replanification et autres statuts restent non prouvés — tous les gates BUF-P0 restent ouverts**

Statut de décision : **BLOCKED_P0**

## 0. Journal d’évolution

Ce journal est mis à jour à chaque étape de la refonte. Une étape n’est déclarée terminée que lorsque ses preuves techniques et ses limites sont enregistrées ici.

| ID | Date | Étape | Évolution | Preuves | Statut |
| --- | --- | --- | --- | --- | --- |
| EV-PULSE-001 | 2026-08-26 | WP0 — stabilisation legacy | Verrous transactionnels d’approbation, dispatch après commit, retries explicites, invariant tenant, édition des publications en file verrouillée et erreurs par cible exposées dans l’interface | 100 tests Pulse / 1 322 assertions ; 7 tests Node ; build Vite ; budgets frontend ; PHPStan ; `composer qa:format` | Terminé |
| EV-PULSE-002 | 2026-08-27 | WP1 — revalidation du contrat | Le graphe du dépôt, Nightwatch et la documentation Buffer officielle sont recontrôlés avant de choisir une tranche. Aucun incident Nightwatch ouvert. Les pages Buffer exposent désormais un contrat public plus détaillé, mais aucune preuve réelle liée au client OAuth Malikia n’est encore attachée aux BUF-P0-01 à 10. | Graphify BFS sur Buffer/OAuth/transport/outbox ; Nightwatch : 0 issue ouverte ; documentation officielle Buffer consultée ; navigateur intégré indisponible faute d’instance connectée | En cours |
| EV-PULSE-003 | 2026-08-27 | WP0-S — sécurité OAuth et DTO | Le verifier PKCE X quitte `metadata` pour un champ chiffré dédié. Les DTO n’exposent plus la metadata brute : seuls `connection_flow` et `test_connection` sont autorisés. Tous les chemins terminaux OAuth, refresh, test et déconnexion effacent l’état, le verifier et son expiration. La lecture legacy est conservée uniquement pendant la fenêtre de migration. | Tests de chiffrement au repos, de migration aller/retour, de non-fuite Inertia et API, de fallback legacy et de nettoyage des secrets ; Graphify path service → publisher X | Validé localement |
| EV-PULSE-004 | 2026-08-27 | WP0 — workflow, file et interface | Une publication déjà demandée ne peut plus repartir en approbation ; une approbation en attente ne peut être publiée ou programmée directement ; le worker refuse tout contenu encore en attente. Une publication déjà programmée ne peut plus redispatcher la même cible. Les contrôles UI couvrent les vrais handlers de mutation. Les contrats du job et l’isolation tenant avant dispatch sont figés par tests. | Tests approbation owner/approver, rollback et `afterCommit` ; double programmation sans mutation/dispatch ; contrats exacts tries/backoff/timeout/middleware ; tests Node des handlers et boutons | Terminé |
| EV-PULSE-005 | 2026-08-27 | WP0-S — callback OAuth à usage unique | Le callback revendique atomiquement la connexion avant l’appel HTTP fournisseur, consomme le verifier, bloque les redémarrages concurrents et ne finalise que si le claim attendu est toujours propriétaire. La relance OAuth verrouille et relit la ligne en base : un modèle obsolète ne peut pas écraser le claim. Après expiration du lease, l’interface autorise la récupération. Une réponse ancienne ne peut plus écraser une nouvelle autorisation. Les appels OAuth portent des timeouts explicites et aucun retry automatique. | Tests de double callback réentrant, modèle obsolète, claim actif/expiré, réponse obsolète, refus fournisseur, timeout et unicité de l’appel HTTP | Validé localement |
| EV-PULSE-006 | 2026-08-27 | WP0-S — stratégie de déploiement | Le schéma et le nouveau writer ne sont pas compatibles avec un déploiement rolling bidirectionnel : un ancien callback ne relit pas le nouveau champ dédié. Le lot courant exige donc un déploiement atomique sous maintenance ; un rolling nécessiterait un pont temporaire en trois phases. Le rollback de la migration métier ne doit être utilisé qu’avec le retour coordonné vers l’ancien code. | Revue de migration, test SQLite isolé `up → down → up`, audit des readers/writers et fenêtre OAuth de 15 minutes | Gate production ouvert |
| EV-PULSE-007 | 2026-08-27 | Validation intégrée WP0/WP0-S | Le lot PHP, les contrats frontend et la compilation sont verts après les corrections de revue. PHPStan a été relancé hors sandbox après le blocage attendu de son port local éphémère et ne relève aucune erreur. Les deux migrations passent un cycle réel `fresh → rollback --step=2 → migrate` sur une base SQLite isolée. | 127 tests Pulse / 1 546 assertions ; 197 tests Node ; Pint sur 22 fichiers ; build Vite ; budgets frontend ; PHPStan 901/901 sans erreur ; migrations `up → down → up` | Contrôles intégrés verts |
| EV-PULSE-008 | 2026-08-27 | Validation croisée MCP et graphe | Le graphe est reconstruit après les changements et relie le claim OAuth, le service de connexion, le publisher X, la migration et le gate documentaire. Nightwatch reste sans issue ouverte. GitHub confirme le dépôt distant et l’existence de `develop` ; aucune action distante n’est exécutée. Le navigateur intégré a été diagnostiqué mais aucune instance n’est connectée. Laravel Boost, Context7 et un MCP Buffer ne sont pas exposés dans cette session : la documentation officielle revalidée et datée reste le fallback consigné. Aucun credential ni client OAuth Buffer réel n’est disponible pour fermer WP1. | Graphify : 31 924 nœuds / 66 037 arêtes / 1 753 communautés puis requête BFS ciblée ; Nightwatch : 0 issue ouverte ; GitHub MCP : dépôt `Lenenba/mlkpro_v3`, branche `develop` ; Browser : liste vide | Terminé avec limites explicites |
| EV-PULSE-009 | 2026-08-27 | Revue finale contradictoire | Trois relectures indépendantes identifient un écrasement possible du claim OAuth par un modèle obsolète, la récupération UI impossible après expiration du claim, un redispatch de programmation, deux handlers UI incohérents et des formulations documentaires trop absolues. Les corrections utilisent un verrou DB frais pour relancer OAuth, exposent l’activité réelle du claim, bloquent la double programmation et alignent tous les handlers concernés. | Revue backend, frontend et documentaire multi-agent ; 32 tests backend ciblés / 247 assertions ; 5 tests Node ciblés ; suite intégrée EV-PULSE-007 | Corrections et validation intégrée terminées |
| EV-PULSE-010 | 2026-08-27 | Gate PHP obligatoire | Tous les fichiers PHP du lot, y compris les ajouts et migrations, sont complètement indexés avant le contrôle de format. Le garde-fou du dépôt sélectionne 22 fichiers depuis le merge-base `origin/develop` et Pint ne produit aucune correction. | `composer qa:format` : PASS, 22/22 fichiers ; aucun fichier PHP partiellement indexé | Terminé |
| EV-PULSE-011 | 2026-08-27 | Isolation de la refonte | Le lot WP0/WP0-S validé est déplacé sans perte depuis `develop` vers une branche dédiée afin de poursuivre les essais et la refonte sans exposer la branche d’intégration aux travaux intermédiaires. Les 31 fichiers restent complètement indexés et le présent lot constitue le checkpoint initial de la branche. Aucune branche distante n’est créée à cette étape. | Branche locale `feature/pulse-buffer-refonte` créée depuis `develop@a54169d3d096` ; index Git conservé ; absence de fichier non indexé | Terminé |
| EV-PULSE-012 | 2026-08-27 | Régression complète de branche | La suite PHP complète confirme l’absence de régression Pulse. Deux échecs déterministes hors périmètre subsistent depuis la baseline : `ProductSalesKpiTest` appelle la route inexistante `service.show` et `SavedSegmentUiPhaseThreeTest` attend 200 mais reçoit 403. Les tests et les routes/contrôleurs concernés sont inchangés entre `develop` et le checkpoint Pulse ; ils ne sont pas corrigés dans cette branche sans élargissement explicite du scope. | Suite complète : 1 613 tests verts / 19 323 assertions, 2 échecs ; relance isolée : 11 tests verts / 78 assertions, mêmes 2 échecs ; `git diff develop...HEAD` limité aux 31 fichiers Pulse | Pulse vert ; gate global bloqué par 2 défauts baseline hors scope |
| EV-PULSE-013 | 2026-08-27 | WP1-A — harness probatoire read-only | Un spike Node isolé prépare la collecte de preuves authentifiées sans démarrer le client de production WP2. Il ne contient que les requêtes publiques `account` et `channels`, exige un environnement explicitement local, lit le token uniquement depuis l’environnement, interdit tout document de mutation, ne persiste rien et n’effectue aucun retry. Les messages distants sont hashés après masquage et toute occurrence exacte du token est masquée dans l’enveloppe. Les trois politiques de quota sont parsées, appariées par label et validées sur les périodes 900/86 400/2 592 000 secondes sans figer les quotas du plan. Aucun appel Buffer réel n’est exécuté dans cette étape. | 20 tests Node ciblés : contrats publics et nullabilité, payloads et `errors` fail-closed, quotas manquants/dupliqués/désalignés/malformés et plan-dépendants, HTTP 200/401/429, timeout réellement aborté, flux > 1 Mio, environnements, arguments et masquage global ; suite Node complète : 217/217 | Harness validé localement ; aucun BUF-P0 fermé |
| EV-PULSE-014 | 2026-08-27 | Validation intégrée WP1-A | Trois relectures indépendantes couvrent le contrat Buffer public, la sécurité de la sonde et la valeur des tests. Les constats reproduits ont été fermés : aucune valeur distante ne peut réémettre exactement le token, une clé GraphQL `errors` présente doit être une liste non vide valide, les nullabilités suivent le schéma public, le corps est borné en flux et les trois quotas cohérents sont obligatoires. Le graphe du dépôt inclut désormais le harness et ses relations sans démarrer WP2. | Revue finale contractuelle sans blocant ; revue sécurité sans blocant, puis deux durcissements complémentaires couverts par tests ; 20/20 ciblés, 217/217 Node, build Vite vert, budgets frontend verts, index docs vert ; Graphify : 31 979 nœuds / 66 156 arêtes / 1 769 communautés | Prêt pour le gate Git/PHP et le checkpoint de branche |
| EV-PULSE-015 | 2026-08-27 | Gate final du checkpoint WP1-A | Les six fichiers du lot WP1-A sont complètement indexés sans inclure de credential ni de sortie Buffer. Le contrôle PHP obligatoire réinspecte également les 22 fichiers PHP déjà commités sur la branche depuis `origin/develop`; aucun fichier PHP sale, partiellement indexé ou supprimé hors index n’est présent. | `composer qa:format` : PASS 22/22 ; `vendor/bin/pint --dirty` : PASS 0 fichier sale ; `git diff --check` et `git diff --cached --check` : PASS | Prêt à commiter et pousser uniquement `feature/pulse-buffer-refonte` |
| EV-PULSE-016 | 2026-08-27 | Publication de la branche et gate d’accès WP1 | Les deux checkpoints sont publiés sur la branche distante dédiée sans modifier `develop` ni `main`. GitHub confirme la branche et le SHA de tête ; le suivi local/distant est strictement synchronisé. Nightwatch ne signale aucun incident ouvert sur `Malikia pro dev`. La vérification locale, limitée à des booléens sans afficher de secret, confirme que la sonde est désactivée et qu’aucun token WP1 n’est configuré ; aucun appel Buffer réel n’est donc tenté. | GitHub : branche `feature/pulse-buffer-refonte`, tête `2514561f81a89130fe0e6a796cc11ed841a02643` ; Git `0/0` ; Nightwatch : 0 incident ouvert ; `BUFFER_WP1_PROBE_ENABLED=false`, token absent | Branche publiée ; collecte authentifiée et tous les BUF-P0 toujours en attente |
| EV-PULSE-017 | 2026-08-27 | Premières preuves authentifiées Buffer | Deux exécutions séparées du harness interrogent réellement `account`, puis `channels` pour l’unique organisation visible. Les deux réponses sont HTTP 200 sans erreur GraphQL. Le compte expose une organisation, aucun client connecté observable et trois canaux sains : Instagram business, LinkedIn page et Facebook page. Les permissions visibles couvrent la publication sur les trois réseaux, mais elles ne prouvent aucune mutation Buffer. Les trois fenêtres de quota décrémentent d’une unité entre les deux appels sous la même partition opaque. Aucun identifiant, nom de canal, request ID, partition key, token ou corps brut n’est conservé dans le dépôt. | `account` : succès, ~559 ms ; `channels` : succès, ~255 ms ; quotas observés : 100/15 min, 250/jour, 3 000/30 jours ; compteurs 99→98, 249→248, 2 999→2 998 ; 1 organisation / 3 canaux | Preuve partielle BUF-P0-01 et BUF-P0-02 ; tous les gates restent ouverts |
| EV-PULSE-018 | 2026-08-27 | WP1-B — contrat GraphQL authentifié | Une introspection fixe et strictement read-only confirme le schéma réellement exposé au credential : signatures exactes de `post`, `posts`, `createPost`, `editPost`, `deletePost` et `movePostInQueue`, types, nullabilités et valeurs par défaut des 14 champs Create/Edit, unions d’erreur et enums de programmation. Le normalizer refuse les suppressions, dépréciations, doublons, noms/kinds/wrappers incohérents, changements de type/défaut et nouveaux champs/arguments obligatoires sans défaut, tout en tolérant les ajouts GraphQL compatibles. Buffer ne renvoie aucun header de quota sur l’introspection ; cette exception est limitée à l’opération `schema`, tandis que `account` et `channels` exigent toujours les trois fenêtres. Aucun document de mutation n’est envoyé. | Appel réel : HTTP 200, 0 erreur GraphQL, contrat classé `success`, 0 fenêtre de quota sur l’introspection ; 59/59 tests ciblés ; contre-revues contrat, sécurité et couverture | Preuve contractuelle read-only acquise ; BUF-P0-05/06/07 toujours ouverts faute de comportement de mutation réel |
| EV-PULSE-019 | 2026-08-27 | Validation intégrée WP1-B | Trois revues indépendantes concluent sans finding reproductible après correction de tous les cas adversariaux : dérives de signature/type/nullabilité/défaut, dépréciations, doublons, kinds contextuels, wrappers impossibles, noms GraphQL invalides, ajout obligatoire incompatible, arguments CLI dupliqués et collision du marqueur de redaction. Le probe authentifié final reste vert et le graphe partagé est reconstruit. | 59/59 ciblés ; 256/256 Node complets ; build Vite, budgets frontend et index de 231 documents verts ; Graphify : 32 013 nœuds / 66 241 arêtes / 1 769 communautés ; revues contrat, sécurité et tests : VERT | Prêt pour gate Git/PHP et checkpoint WP1-B ; aucun BUF-P0 fermé |
| EV-PULSE-020 | 2026-08-27 | Gate final du checkpoint WP1-B | Le lot WP1-B est limité au harness read-only, à son test Node et au journal central. Les trois fichiers sont complètement indexés ; aucun `.env`, token, identifiant distant, request ID, partition key ou corps Buffer brut n’entre dans le commit. Le gate PHP obligatoire réinspecte les 22 fichiers PHP déjà commités depuis `origin/develop` sans correction. | `composer qa:format` : PASS 22/22 ; `git diff --check` et `git diff --cached --check` : PASS ; 3 fichiers indexés | Prêt à committer et pousser uniquement `feature/pulse-buffer-refonte` |
| EV-PULSE-021 | 2026-08-27 | Publication et vérification distante WP1-B | Le checkpoint fonctionnel WP1-B est publié uniquement sur la branche dédiée. GitHub confirme le SHA complet, un commit d’avance sur le checkpoint authentifié précédent et exactement les trois fichiers attendus. Le suivi local/distant est synchronisé. Nightwatch ne signale aucun incident ouvert sur `Malikia pro dev`. `develop` et `main` ne sont ni modifiés ni ciblés. | GitHub : `55840503e97501fa81562d8a545f9abbad336883`, comparaison `ahead 1 / behind 0`, 3 fichiers ; Git `0/0` ; Nightwatch : 0 incident ouvert | Checkpoint WP1-B publié ; tous les BUF-P0 restent ouverts |
| EV-PULSE-022 | 2026-08-27 | WP1-C — harness de cycle draft Facebook | Un harness Node séparé prépare un seul cycle contrôlé `create draft → edit draft → move bottom → delete → vérification NOT_FOUND` sur l’unique page Facebook saine et autorisée. Il exige deux gates locaux, une confirmation CLI exacte, une empreinte opaque de cible acquise lors d'un préflight sans mutation, huit unités de quota disponibles dans chaque fenêtre et un journal privé armé avant création. Les variables sont figées sur `saveToDraft=true`, `mode=addToQueue`, `schedulingType=notification`, sans média, approbation, planification personnalisée, partage immédiat ni retry. Le cycle et le mode `cleanup-only` partagent un verrou local exclusif avec propriétaire et PID ; seul un verrou dont le processus n'existe plus peut être remplacé. Le mode de reprise relit le journal, le fingerprint et tous les invariants du brouillon avant une suppression unique. SIGINT/SIGTERM interrompent les étapes optionnelles mais laissent finir le nettoyage. Toute ambiguïté conserve le journal et impose une réconciliation manuelle ; une nouvelle création est interdite tant qu'il reste actif. Un arrêt brutal entre l'acceptation distante et la réception de l'identifiant reste récupérable seulement par recherche manuelle du marqueur unique. Aucune mutation réelle n'est encore exécutée à cette étape. | 39/39 tests WP1-C ; 98/98 tests WP1-A/B/C ; concurrence cycle/reprise, PID vivant/mort, signaux, quotas, redaction et suppression stricte couverts ; `node --check` et `git diff --check` verts | Harness local prêt ; exécution distante encore soumise au GO final ; aucun BUF-P0 fermé |
| EV-PULSE-023 | 2026-08-27 | WP1-C — préflight et premier essai Facebook | Le préflight authentifié confirme une organisation, une seule page Facebook éligible, le schéma attendu, l'empreinte de cible exacte et une réserve suffisante dans les trois fenêtres. Le premier `createPost` utilise le profil de sécurité `saveToDraft=true`, `addToQueue`, `notification`, sans média ni approbation. Buffer répond HTTP 200 avec `InvalidInputError` et aucun identifiant de post. Ce rejet est définitif : aucun retry, brouillon, edit, move ou delete n'est produit ; le journal armé est effacé et le verrou libéré. La requête diffère de l'exemple officiel de création d'un brouillon par `schedulingType=notification` ; la prochaine tranche isolera uniquement ce paramètre avec `automatic`, tout en conservant `saveToDraft=true` et tous les invariants de non-publication. | Préflight : schéma/compte/canaux `success`, 1 cible, quota suffisant ; create : HTTP 200, `InvalidInputError`, ~119 ms ; journal et verrou absents après l'arrêt ; message distant conservé uniquement sous forme de hash | Refus propre acquis ; aucun objet distant à réconcilier ; BUF-P0-06/07 restent ouverts |
| EV-PULSE-024 | 2026-08-27 | WP1-C — adaptation contractuelle minimale | Le seul paramètre modifié après le refus est `schedulingType`, de `notification` vers `automatic`, valeur utilisée par l'exemple officiel Buffer « Create Draft Post ». Les valeurs explicites `assets=[]` et `needsApproval=false` sont conservées afin d'isoler ce facteur. La sûreté ne dépend pas du mode de livraison : `saveToDraft=true`, `status=draft`, `dueAt=null`, `sentAt=null`, `sharedNow=false`, `shareMode=addToQueue`, le texte exact et l'identité du canal sont tous contrôlés après create, edit et move. Le mode reprise exige les mêmes invariants avant de supprimer. | 39/39 tests WP1-C après l'adaptation ; syntaxe Node et `git diff --check` verts ; documentation officielle « Create Draft Post » revalidée le 2026-08-27 | Second essai en attente des revues ciblées ; aucun objet distant actif |
| EV-PULSE-025 | 2026-08-27 | WP1-C — second essai Facebook | Le second `createPost` conserve tous les garde-fous et ne remplace que `schedulingType=notification` par `automatic`. Buffer répond de nouveau HTTP 200 avec `InvalidInputError`, mais la signature assainie du message diffère de celle du premier refus. Aucun identifiant n'est retourné : aucun brouillon, edit, move ou delete n'est donc produit. Le journal privé armé avant l'appel est effacé et le verrou exclusif est libé. Aucun troisième essai de création n'est autorisé dans cette tranche. La suite doit d'abord introspecter en lecture seule le contrat des métadonnées Facebook et comparer l'input minimal exact ; ces pistes ne sont pas encore prouvées comme cause. | Create : HTTP 200, `InvalidInputError`, aucune identité de post ; signature assainie distincte du premier refus ; journal et verrou absents après l'arrêt ; aucune donnée distante sensible conservée | Refus propre acquis ; aucun objet distant à réconcilier ; BUF-P0-06/07 restent ouverts |
| EV-PULSE-026 | 2026-08-27 | WP1-D — introspection Facebook durcie | La requête fixe read-only couvre désormais `PostInputMetaData`, `FacebookPostMetadataInput` et `PostTypeFacebook`. Le normalizer verrouille les onze entrées metadata connues, les quatre champs Facebook, leurs kinds, wrappers, valeurs par défaut et l'enum actif `post/reel/story`, tout en tolérant les ajouts optionnels ou munis d'un défaut. Une contre-revue a reproduit un cas où l'ancien marqueur de redaction pouvait recomposer un token court ; les deux harness utilisent maintenant un marqueur non collisionnel et recontrôlent le résultat. Le contrat expose deux capacités structurées : `facebook_create_metadata` pour le preflight de création et un profil minimal `post_delete_cleanup`, afin qu'une dérive metadata n'empêche jamais le nettoyage d'un brouillon journalisé. Un faux succès, une mauvaise opération, un profil incorrect ou une capacité vide sont refusés avant journal et HTTP. | 86/86 tests du probe read-only ; 44/44 tests du lifecycle ; 130/130 combinés ; reproduction `RE`/`RREE`, aliases absents, kinds falsifiés, wrappers, dépréciations, contrat vide et indépendance du cleanup couverts ; contre-revue sécurité GO lecture seule | Prêt pour une introspection authentifiée unique ; mutation NO-GO |
| EV-PULSE-027 | 2026-08-27 | WP1-D — preuve Facebook authentifiée | Une unique introspection authentifiée, sans variable métier ni document `mutation`, confirme HTTP 200 et le contrat complet. `CreatePostInput.metadata` reste nullable ; `PostInputMetaData.facebook` reste nullable ; si l'objet Facebook est fourni, son champ `type: PostTypeFacebook!` est obligatoire et accepte `post`, `reel` ou `story`. Pour un futur brouillon texte, `{facebook: {type: post}}` constitue donc un candidat minimal bien formé, mais pas la cause démontrée des deux `InvalidInputError`. Le premier lancement confiné n'a pas atteint Buffer (`transport_error`) ; le lancement réseau explicitement autorisé a réussi. Aucun create, edit, move, delete, publication ou objet distant n'est produit. | Introspection réelle : HTTP 200, `success`, ~284 ms, aucune erreur GraphQL et aucun header quota ; aucun identifiant distant, request ID, token ou corps brut conservé dans Git | Contrat Facebook acquis ; hypothèse d'input à préparer séparément ; BUF-P0-06/07 restent ouverts et aucun troisième essai n'est autorisé |
| EV-PULSE-028 | 2026-08-27 | Validation et publication WP1-C/WP1-D | Le harness de cycle Facebook, l'introspection metadata, les protections de redaction, les profils de capacité et leurs tests sont publiés uniquement sur la branche feature. GitHub confirme le commit, exactement sept fichiers et un écart `ahead 1 / behind 0` depuis le checkpoint WP1-B. Nightwatch ne signale aucun incident ouvert sur `Malikia pro dev`. La régression complète couvre 327 tests Node ; le build Vite, les budgets frontend, l'index documentaire et Graphify sont verts. Tous les fichiers du lot sont indexés avant le gate PHP, qui réinspecte les 22 fichiers PHP de la branche sans correction. | GitHub : `9cdaa02cf139877252f4423be229e38ca4bcba61`, 7 fichiers, `ahead 1 / behind 0` ; Nightwatch : 0 incident ouvert ; Node 327/327 ; build/budgets/docs verts ; Graphify : 32 116 nœuds / 66 502 arêtes / 1 791 communautés ; `composer qa:format` PASS 22/22 ; diff Git vert | Checkpoint fonctionnel et journal publiés ; `develop` et `main` inchangées |
| EV-PULSE-029 | 2026-08-27 | WP1-E — contrat Facebook post préparé | Le futur input create est fermé sur l'unique ajout `metadata.facebook.type=post`, sans annotation, premier commentaire, lien ni média. Les retours create/edit/move et l'inspection cleanup sélectionnent la même branche typée `FacebookPostMetadata` et exigent exactement `__typename=FacebookPostMetadata` et `type=post`, en plus de tous les invariants draft existants. Le preflight read-only verrouille maintenant la chaîne de sortie `Post.metadata → PostMetadata → FacebookPostMetadata.type → PostType.post` dans les profils `full` et `cleanup`; le profil cleanup reste indépendant de `CreatePostInput`, `PostInputMetaData`, `FacebookPostMetadataInput` et `PostTypeFacebook`. Les réponses nulles, incomplètes, élargies, d'un autre réseau ou d'un autre type échouent fermées; après un ID créé, elles conservent la suppression unique et sa vérification, tandis qu'une inspection non exacte préserve le journal sans supprimer. Cette tranche n'exécute aucun appel authentifié ni document de mutation. | Documentation Buffer officielle revalidée ; trois contre-revues GO ; probe read-only 96/96, lifecycle 47/47, combinés 143/143 ; Node complet 340/340 ; build, budgets frontend et index de 231 documents verts ; Graphify 32 121 nœuds | Préparé localement, non exécuté ; hypothèse causale toujours ouverte et troisième create toujours non autorisé |
| EV-PULSE-030 | 2026-08-27 | Validation et publication WP1-E | Le contrat d'input/output Facebook, l'introspection de sortie, les invariants de cycle, la récupération fail-closed et leurs tests sont publiés uniquement sur la branche feature. GitHub confirme le SHA fonctionnel, exactement cinq fichiers et un écart d'un commit depuis le checkpoint WP1-C/WP1-D. Nightwatch reste sans incident ouvert sur `Malikia pro dev`. Le gate PHP réinspecte les 22 fichiers PHP déjà présents sur la branche sans correction; aucun fichier PHP n'est modifié par WP1-E. Aucun script de mutation Buffer n'est exécuté pendant la validation ou la publication. | GitHub : `d08ee404cb0a66f90d3d38f48f76d8b2842b1753`, 5 fichiers, `ahead 1 / behind 0` ; Nightwatch : 0 incident ouvert ; Node 340/340 ; ciblés 143/143 ; build, budgets frontend, docs et Graphify verts ; `composer qa:format` PASS 22/22 ; diff Git vert | Checkpoint fonctionnel WP1-E publié ; `develop` et `main` inchangées ; troisième create toujours non autorisé |
| EV-PULSE-031 | 2026-08-27 | WP1-F — preuve distante Facebook | Le préflight authentifié confirme le contrat, une organisation, une seule page Facebook admissible et un quota suffisant. L'unique `createPost` autorisé retourne un brouillon Facebook standard conforme avec `metadata.facebook.type=post`. L'édition sans metadata est ensuite refusée par `InvalidInputError`; le déplacement n'est pas tenté. Le harnais exécute une seule suppression, confirme ensuite `NOT_FOUND`, efface son journal privé et libère son verrou. | Réponses HTTP 200 typées ; create `PostActionSuccess`, edit `InvalidInputError`, delete `DeletePostSuccess`, vérification `NOT_FOUND` ; aucun retry, aucun identifiant conservé, aucun objet résiduel et aucun besoin de réconciliation | Preuve create/delete acquise ; edit et move restent ouverts ; tous les BUF-P0 restent ouverts |
| EV-PULSE-032 | 2026-08-27 | WP1-G — préparation locale edit Facebook | L'input edit est fermé sur l'ajout minimal `metadata.facebook.type=post`, sans modifier les autres variables ni les invariants draft. Les mutations dont l'issue peut être ambiguë — timeout, transport, réponse 5xx, JSON invalide, flux absent ou réponse surdimensionnée — sont classées inconnues sans retry. La reprise couvre le brouillon avant sa première édition ; create avec identifiant récupérable n'utilise cet identifiant que pour le cleanup ; inspect, delete et verify restent fail-closed. | Lifecycle 72/72, probe 96/96, combinés 168/168 ; Node complet 365/365 ; `node --check` ; Graphify incrémental ; aucune requête réseau | Préparé localement ; hypothèse edit non prouvée ; aucun nouvel essai distant autorisé |
| EV-PULSE-033 | 2026-08-27 | Hygiène — retrait de code mort | L'audit des imports, appels directs, callables, usages réflexifs, routes, payloads, tests, documentation et relations Graphify ne démontre aucun élément mort dans les deux harnais Buffer. Une seule méthode historique du modèle social n'a jamais eu de consommateur : `SocialAccountConnection::allowedAuthMethods()`. Elle est retirée sans supprimer les constantes d'authentification toujours actives. Les providers, routes et configurations sociales directes restent nécessaires jusqu'à la bascule WP7. | Recherche dépôt et historique Git ; Graphify ; test de surface du modèle ; 37 tests sociaux / 340 assertions | Un seul élément inutile supprimé ; aucune suppression spéculative ; audit à répéter à chaque tranche |
| EV-PULSE-034 | 2026-08-27 | Validation intégrée WP1-F/WP1-G | Le lot local, le retrait de code mort et les deux documents d'avancement passent les gates du dépôt. PHPStan nécessite uniquement son port local éphémère hors sandbox et termine sans erreur. Le graphe partagé est reconstruit après les changements. | Node 365/365 ; 37 tests PHP / 340 assertions ; PHPStan 901/901 ; `composer qa:format` 22/22 ; Pint 2/2 ; build Vite, budgets frontend et index de 232 documents verts ; Graphify 32 130 nœuds / 66 529 arêtes / 1 790 communautés | Prêt à indexer, committer et pousser uniquement sur la branche feature |
| EV-PULSE-035 | 2026-08-27 | Publication et vérification distante WP1-F/WP1-G | Le checkpoint fonctionnel est publié uniquement sur la branche feature. GitHub confirme le SHA complet, les huit fichiers attendus et un écart d'un commit depuis le checkpoint WP1-E. Nightwatch ne signale aucun incident ouvert sur `Malikia pro dev`. `develop` et `main` restent inchangées. | GitHub : `a0cbad7aa51dd679b3813d3ae92cd5773c64bc33`, comparaison `ahead 1 / behind 0`, 8 fichiers ; Nightwatch : 0 incident ouvert | Checkpoint fonctionnel publié ; edit distant et move restent à prouver dans une future tranche autorisée |
| EV-PULSE-036 | 2026-08-27 | WP1-H — preuve distante edit et move Facebook | Un préflight sans empreinte s'arrête avant journal et mutation après avoir confirmé le schéma, un compte, une organisation, une seule Page Facebook éligible et la capacité requise. L'unique cycle ensuite autorisé crée un brouillon Facebook `post`, l'édite avec la même metadata minimale et conserve tous les invariants de non-publication. `movePostInQueue`, documenté expérimental par Buffer, refuse ensuite le brouillon avec un `VoidMutationError` typé ; ce refus est définitif, non ambigu et non rejoué. Le `finally` effectue une seule suppression, la lecture suivante confirme `NOT_FOUND`, puis le journal privé et le verrou disparaissent. | HTTP 200 à chaque étape ; create/edit `PostActionSuccess`, move `VoidMutationError` classé `draft_move_rejected`, delete `DeletePostSuccess`, vérification `NOT_FOUND` ; aucun retry, identifiant conservé, objet résiduel ni besoin de réconciliation | Preuve réelle create/edit/delete acquise ; move sur brouillon explicitement refusé ; preuves partielles BUF-P0-06/07, tous les gates restent ouverts |
| EV-PULSE-037 | 2026-08-27 | Validation et hygiène WP1-H | Deux audits indépendants confirment les gates, l'absence de retry distant, la suppression unique, la redaction des sorties et la valeur de la couverture. L'audit de code mort ne démontre aucun nouvel élément supprimable dans les harnais ou leurs tests ; la branche `VoidMutationError` est au contraire prouvée vivante par l'essai réel. La documentation Buffer officielle est revalidée et Nightwatch ne signale aucun incident ouvert. Aucun fichier PHP n'est modifié dans cette tranche documentaire. | Lifecycle 72/72, probe 96/96, combinés 168/168 ; Node complet 365/365 ; `node --check`, JSON, index de 232 documents et `git diff --check` verts ; Graphify 32 130 nœuds ; Nightwatch : 0 incident ouvert | Zéro suppression spéculative ; preuve et documents prêts à committer sur la branche feature |
| EV-PULSE-038 | 2026-08-27 | Publication et vérification distante WP1-H | La preuve distante, la vue visuelle, le statut documentaire et l'index sont publiés uniquement sur la branche feature. GitHub confirme le commit probatoire complet, exactement quatre fichiers et un écart d'un commit depuis le checkpoint WP1-F/WP1-G. Nightwatch ne signale aucun incident ouvert ; `develop` et `main` restent inchangées. | GitHub : `5ffcc685cb3e6dcfe29c97231894b3ae9a0d78fe`, comparaison `ahead 1 / behind 0`, 4 fichiers ; Nightwatch : 0 incident ouvert | Checkpoint WP1-H publié ; branche feature prête pour la prochaine tranche P0 |
| EV-PULSE-039 | 2026-08-27 | WP1-I — qualification de `move@draft` | La documentation officielle distingue le brouillon, non programmé tant qu'il n'est pas explicitement planifié, du post réellement présent dans une file. `movePostInQueue` est une opération expérimentale, limitée par son contrat aux posts en file. Croisée avec l'unique réponse réelle HTTP 200 `VoidMutationError`, cette distinction qualifie le refus comme frontière de capacité négative provisoire pour le seul tuple observé : Page Facebook sélectionnée, statut `draft`, position `bottom`. Le harnais conserve donc `draft_move_rejected`, `ok=false`, zéro retry et zéro fallback ; il ne généralise pas le refus au canal, aux autres statuts, à `top` ou à Buffer entier. | Documentation Buffer officielle `Create Draft Post`, `EditPostInput` et référence `movePostInQueue` revalidée ; Graphify relie mutation, normalizer, test et gates ; deux audits indépendants convergents | Décision locale acquise ; preuves partielles BUF-P0-06/07 ; tous les gates restent ouverts et WP2 bloqué |
| EV-PULSE-040 | 2026-08-27 | Validation et hygiène WP1-I | Le contrat existant reste inchangé et toutes ses branches de move sont conservées : le refus `VoidMutationError` est vivant en réel, tandis que le succès, les réponses ambiguës et les autres erreurs typées restent couverts pour détecter une dérive future. Deux audits indépendants ne démontrent aucun nouvel import, symbole, test ou chemin supprimable ; aucune suppression spéculative n'est effectuée. La tranche ne modifie aucun fichier PHP et n'exécute aucune mutation Buffer. | Buffer ciblé 168/168 ; Node complet 365/365 ; `node --check`, JSON, index de 232 documents et `git diff --check` verts ; Graphify actualisé ; Nightwatch : 0 incident ouvert | Décision et documentation prêtes à committer uniquement sur la branche feature ; zéro nouveau code mort démontré |

### 0.1 Gate de déploiement WP0-S

Pour le seul lot de sécurité WP0-S, le code courant est **GO uniquement pour un déploiement atomique sous maintenance**. Ce GO limité ne vaut ni GO fondation Buffer, ni GO pilote, ni autorisation de mise en production globale :

1. arrêter l’entrée de nouveaux callbacks OAuth et drainer les workers et nœuds web anciens ;
2. attendre ou invalider la fenêtre OAuth engagée de 15 minutes ;
3. déployer les deux migrations et le code dans la même fenêtre, avec la même `APP_KEY` et, en cas de rotation, les anciennes clés conservées dans `APP_PREVIOUS_KEYS` ;
4. exécuter les contrôles de migration et de connexion, puis reprendre le trafic.

Le déploiement rolling/zero-downtime du lot courant est **NO-GO** : le nouveau writer place le verifier dans le champ chiffré dédié alors que l’ancien callback ne lit que `metadata`.

L’alternative rolling exige trois versions distinctes : expansion du schéma ; reader compatible et writer temporairement double ; drainage de la fenêtre de 15 minutes, nettoyage des données legacy puis suppression du double writer. Cette alternative n’est pas implémentée dans ce lot.

Le `down()` de la migration de données restaure volontairement le verifier en clair dans `metadata` pour permettre un retour coordonné vers l’ancien code. Il ne constitue pas un rollback de routine et ne doit jamais être exécuté alors que le nouveau code reste en service.

La preuve locale `up → down → up` utilise SQLite. Avant production, la même séquence doit être répétée sur un clone MySQL représentatif, avec sauvegarde vérifiée et contrôle de déchiffrement ; SQLite ne ferme pas ce gate fournisseur de base de données.

## 1. Décision exécutive

La direction d’architecture est approuvée :

> **Malikia Pulse reste le système éditorial et métier. Buffer devient l’unique passerelle de livraison vers les réseaux sociaux.**

La mise en production n’est pas approuvée.

L’audit confirme que Pulse possède déjà un domaine éditorial réutilisable, mais qu’aucune intégration Buffer n’existe encore dans le code, le schéma, la configuration ou les tests. Le transport actif reste un scaffold de publishers directs Facebook, Instagram, LinkedIn et X.

La décision courante est donc :

| Niveau | Décision | Signification |
| --- | --- | --- |
| Architecture | GO conditionnel | La séparation Pulse métier / Buffer livraison est retenue |
| Fondation | Bloquée par P0 | Les contrats fournisseur et les risques structurants doivent être fermés |
| Pilote | NO-GO actuel | Aucun flux Buffer fiable n’est implémenté |
| Généralisation | NO-GO actuel | Aucune preuve de capacité, migration, sécurité ou exploitation |

### Règles non négociables

- Aucun nouvel investissement dans un publisher social direct.
- Aucun appel Buffer depuis le navigateur.
- Aucun token, secret, verifier PKCE ou payload sensible dans les DTO frontend ou les logs.
- Aucun fallback automatique vers les APIs sociales directes.
- Aucun contenu non approuvé ne quitte Pulse.
- Aucun retry de création distant après un résultat ambigu sans réconciliation.
- Aucun changement de transport décidé dynamiquement par un feature flag au moment où un worker s’exécute.
- Aucune promesse d’analytics, de commentaires ou d’engagement non couverte par le contrat public Buffer.

## 2. État réel du dépôt au point de départ

### 2.1 Matrice de readiness

| Domaine | État observé | Écart à fermer |
| --- | --- | --- |
| Client GraphQL Buffer | Absent | Créer un client isolé et testé |
| OAuth Buffer | Absent | Implémenter PKCE, organisations, scopes et révocation |
| Connexion provider | Absente | Séparer le grant Buffer des canaux |
| Synchronisation de canaux | Absente | Découvrir, normaliser et mettre en cache les capacités |
| Livraison | Publishers directs génériques | Remplacer par un gateway Buffer |
| Programmation | Job Laravel retardé jusqu’à la date | Soumettre immédiatement à Buffer après approbation |
| Outbox | Absente | Ajouter une outbox transactionnelle avec claim et lease |
| Idempotence locale | Absente | Clé unique par cible, opération et révision |
| Idempotence distante | Non confirmée | Gate fournisseur et protocole de timeout ambigu |
| Réconciliation | Absente | Ajouter polling adaptatif et action manuelle |
| Quotas | Non gérés | Budgets sur trois fenêtres et équité tenant |
| Statuts | Éditorial, livraison et sync mélangés | Séparer les axes |
| Médias | URL publique locale ou externe arbitraire | URL HTTPS stable avec cycle de vie |
| UI | Onze surfaces et composants monolithiques | Simplifier et exposer la récupération par canal |
| Tests Buffer | Aucun | Fake, contrats HTTP, concurrence, migration et E2E |
| Observabilité Buffer | Absente | Métriques, alertes, cockpit et runbooks |

### 2.2 Socle métier à préserver

Pulse fournit déjà :

- brouillons et publications ;
- une cible par canal ;
- préremplissage depuis produits, services, promotions et campagnes ;
- voix de marque, modèles, médiathèque et assistance IA ;
- calendrier et historique ;
- approbations et permissions social.view, social.manage, social.publish et social.approve ;
- campagnes et Autopilot ;
- feature tenant social ;
- états partiels par cible côté backend ;
- chiffrement des credentials au repos.

Les modèles **SocialPost**, **SocialPostTarget**, **SocialApprovalRequest**, **SocialAutomationRule**, **SocialPostTemplate** et **SocialMediaAsset** restent les fondations métier.

### 2.3 Couche directe à remplacer

Le chemin actif est documenté par :

- [SocialProviderRegistry](../app/Services/Social/SocialProviderRegistry.php), qui injecte quatre publishers directs ;
- [PlatformPublisherInterface](../app/Services/Social/Contracts/PlatformPublisherInterface.php), qui mélange définition, OAuth, refresh et publication ;
- [AbstractPlatformPublisher](../app/Services/Social/Providers/AbstractPlatformPublisher.php), qui effectue un POST générique ;
- [AbstractOauthPlatformPublisher](../app/Services/Social/Providers/AbstractOauthPlatformPublisher.php), qui porte les échanges de token par réseau ;
- [SocialPublishingService](../app/Services/Social/SocialPublishingService.php), qui sélectionne directement un publisher réseau ;
- [PublishSocialPostTargetJob](../app/Jobs/PublishSocialPostTargetJob.php), qui exécute la cible.

Cette couche est une baseline historique directe, pas une implémentation Buffer.

### 2.4 Défauts critiques prouvés par l’audit

Cette section décrit le point de départ au commit de baseline. Les fermetures réalisées depuis sont consignées dans le journal d’évolution ; elles ne transforment pas rétroactivement cette photographie historique.

#### Double publication possible

**SocialPublishingService** acceptait explicitement une cible déjà en cours de publication. Deux workers ou un crash après acceptation distante, mais avant sauvegarde locale, pouvaient produire un deuxième envoi.

Il n’existait alors :

- ni verrou de cible ;
- ni claim atomique ;
- ni clé d’idempotence ;
- ni révision éditoriale persistée ;
- ni recherche distante avant retry ;
- ni état de résultat ambigu.

#### Retries neutralisés

**SocialPublishingService** capturait tous les Throwable, marquait la cible failed et ne relançait pas l’exception. Les tries et backoffs déclarés dans **PublishSocialPostTargetJob** ne s’appliquaient donc pas aux erreurs réseau, 429, 5xx ou timeouts fournisseur.

#### Dispatch avant commit possible

Autopilot pouvait appeler la publication dans une transaction alors que les connexions de queue avaient after_commit désactivé. Un worker pouvait s’exécuter avant le commit, ne pas trouver une cible, retourner silencieusement et perdre la livraison.

#### Course dans l’approbation

**SocialApprovalService** déclenchait la publication avant de persister la résolution de l’approbation, sans transaction globale ni verrou. Deux approbateurs concurrents pouvaient mettre en queue la même révision.

#### Invariant tenant incomplet

Le worker recevait seulement un identifiant de cible et ne vérifiait pas explicitement que :

- le post ;
- la cible ;
- le canal ;
- la connexion provider ;
- et l’organisation Buffer

appartiennent tous au même tenant.

#### Risque OAuth et DTO

Le verifier PKCE du provider X était placé dans metadata, et le DTO de connexion retournait alors la metadata complète. La refonte devait passer à des DTO en liste blanche et conserver tous les secrets exclusivement côté serveur.

#### Contrat média insuffisant

La médiathèque utilise un disque public local et accepte aussi des URL externes arbitraires. Elle ne garantit pas encore qu’un média restera publiquement accessible, en HTTPS, jusqu’à sa date de publication plus une période de grâce.

#### Défauts UI observés dans la baseline

- Pendant pending_approval, [SocialPostComposer](../resources/js/Pages/Social/Components/SocialPostComposer.vue) verrouillait les champs texte mais laissait [DropzoneInput](../resources/js/Components/DropzoneInput.vue) interactif. L’approbateur pouvait voir une image locale différente de la révision réellement approuvée.
- Le backend retournait déjà le statut, la date d’échec et la raison par cible, mais [SocialPostHistory](../resources/js/Pages/Social/Components/SocialPostHistory.vue) ne les affichait pas.
- Les capacités des réseaux étaient codées en dur dans Vue.
- Aucun test frontend Pulse ne couvrait le responsive, l’accessibilité, la récupération ou les statuts Buffer.

### 2.5 Interprétation des tests existants

Lors de l’audit du 26 août 2026 :

- 50 tests backend Pulse ciblés et 601 assertions étaient verts ;
- 8 contrôles Node partagés étaient verts ;
- le dépôt restait propre sur develop.

Ces résultats prouvent la stabilité de la baseline directe. Ils ne prouvent pas l’idempotence, la réconciliation, la concurrence, la gestion des quotas ou la fiabilité Buffer.

## 3. Portée et non-objectifs

### 3.1 Inclus dans la refonte

- connexion d’un compte Buffer appartenant au client ;
- OAuth, organisations et canaux ;
- activation des canaux dans Pulse ;
- publication immédiate ;
- programmation exacte ;
- variantes facultatives par réseau ou canal ;
- approbation locale avant livraison ;
- modification et annulation lorsque Buffer le permet ;
- réconciliation des statuts ;
- diagnostic, reconnexion et resynchronisation ;
- migration des brouillons, règles Autopilot, modèles et posts futurs ;
- mode dégradé sans perte de travail éditorial ;
- observabilité, runbooks et rollback.

### 3.2 Hors scope initial

- lecture ou réponse aux commentaires et messages ;
- community management complet ;
- analytics sociaux avancés ;
- administration complète d’un compte Buffer ;
- compte Buffer mutualisé détenu par Malikia ;
- support de nouvelles plateformes avant stabilisation des quatre réseaux déjà visés ;
- utilisation des workflows d’approbation Buffer à la place de ceux de Pulse ;
- authentification des utilisateurs à Malikia par Google, Microsoft, Facebook ou LinkedIn.

Les composants **UserSocialAccount**, **Auth\SocialAuthController**, la configuration social_auth et les routes de connexion utilisateur restent hors périmètre et doivent être préservés.

## 4. Contrat public Buffer revalidé le 27 août 2026

Les faits de cette section sont datés. Ils doivent être revérifiés avant chaque gate de lancement, car l’API évolue rapidement.

### 4.1 Points confirmés

#### API

L’API publique actuelle est GraphQL sur https://api.buffer.com. La refonte ne doit jamais s’appuyer sur l’ancienne API REST.

Sources :

- [Documentation développeur Buffer](https://developers.buffer.com/)
- [Présentation officielle de l’API](https://support.buffer.com/en-us/articles/what-is-buffers-api-GtIYIQilz5)

#### OAuth et rotation

Pour un client OAuth, Buffer exige Authorization Code avec PKCE. Le state doit être vérifié et la redirect URI doit correspondre exactement. Le scope offline_access est requis pour obtenir un refresh token.

Chaque refresh token est à usage unique :

- un refresh réussi invalide l’ancien token ;
- réutiliser un ancien refresh token révoque tous les tokens associés au grant et impose une nouvelle autorisation ;
- la durée doit être lue depuis expires_in et non codée en dur.

Conséquence : un verrou distribué par grant, une transaction et un contrôle de version sont obligatoires pour le refresh.

Source : [Authentification Buffer](https://developers.buffer.com/guides/authentication.html).

#### Quotas

Les limites documentées par client utilisent trois fenêtres glissantes :

| Plan | 15 minutes | 24 heures | 30 jours |
| --- | ---: | ---: | ---: |
| Free | 100 | 250 | 3 000 |
| Essentials | 100 | 250 | 7 500 |
| Team | 100 | 500 | 15 000 |

Chaque réponse GraphQL expose plusieurs headers RateLimit et RateLimit-Policy. Les politiques doivent être identifiées par leur fenêtre, et Retry-After doit être respecté sur HTTP 429.

Un polling toutes les cinq minutes représente déjà 288 appels par jour avant toute autre opération. Le partage réel des buckets d’un app client OAuth entre tenants est donc un bloqueur P0.

Source : [Limites de l’API Buffer](https://developers.buffer.com/guides/api-limits.html).

#### Erreurs GraphQL

Une mutation peut retourner une erreur typée dans data ou une erreur système dans errors sous HTTP 200. Les erreurs de transport, 401 et 429 restent traitées séparément.

Le client doit donc interpréter :

- le statut HTTP ;
- le tableau GraphQL errors ;
- le type concret de l’union de mutation ;
- les headers de quota ;
- un identifiant de requête lorsqu’il existe.

Source : [Gestion des erreurs Buffer](https://developers.buffer.com/guides/error-handling.html).

#### Publication et programmation

Buffer documente notamment :

- shareNow pour une publication immédiate ;
- customScheduled avec dueAt ISO-8601 UTC pour une heure exacte ;
- addToQueue pour la prochaine plage disponible.

Pulse choisit architecturalement de soumettre une programmation exacte à Buffer dès son approbation. Laravel ne doit pas conserver un job dormant jusqu’à la date finale.

Source : [Publications et programmation](https://developers.buffer.com/guides/posts-and-scheduling.html).

La valeur shareNow est également décrite par le type [ShareMode](https://developers.buffer.com/types/ShareMode.html).

Les statuts distants documentés comprennent :

- draft ;
- needs_approval ;
- scheduled ;
- sending ;
- sent ;
- error.

Source : [PostStatus](https://developers.buffer.com/types/PostStatus.html).

Les publications API respectent aussi les permissions et politiques du canal Buffer. Un canal peut donc produire draft ou needs_approval. Le spike doit décider si ces canaux sont refusés dans le MVP ou supportés avec un état remote_approval_required visible ; Pulse ne doit jamais masquer une seconde approbation distante.

#### Médias

Buffer ne fournit pas d’endpoint d’upload pour ce flux. Le média est récupéré depuis une URL :

- HTTPS ;
- directe ;
- publique sans authentification ;
- stable jusqu’à la publication.

Les URL signées à courte durée sont inadaptées aux publications futures.

Source : [Hébergement des médias](https://developers.buffer.com/guides/hosting-media.html).

#### Analytics et engagement

Les métriques publiques sont encore décrites comme expérimentales et orientées vers un usage personnel par clé API. Elles ne doivent pas devenir une dépendance de production OAuth. Les commentaires ne sont pas couverts pour le MVP.

Source : [Limites actuelles de l’API](https://support.buffer.com/en-us/articles/what-is-buffers-api-GtIYIQilz5).

### 4.2 Capacités non documentées publiquement

Au 27 août 2026, aucune garantie publique n’a été identifiée pour :

- un webhook de changement de statut ;
- une clé d’idempotence de création ;
- un clientMutationId ou champ équivalent récupérable ;
- une recherche garantie après un timeout ambigu ;
- le partage exact des quotas OAuth entre tenants.

Cette formulation ne signifie pas que Buffer ne possède pas ces capacités. Elle signifie qu’elles doivent être confirmées par écrit ou démontrées par le spike.

Une clé locale protège Pulse contre ses propres doubles traitements. Elle ne garantit pas un exactly-once distant si Buffer a accepté la publication avant une coupure de réponse.

### 4.3 Contradictions documentaires à mesurer au runtime

Deux formulations officielles ne doivent pas être résolues par hypothèse :

- le guide d’erreurs indique que les erreurs GraphQL utilisent HTTP 200 ;
- le guide des quotas documente explicitement HTTP 429 avec `Retry-After` et `extensions.window`.

Le harness WP1-A conserve donc simultanément le statut HTTP, le tableau `errors`, `Retry-After`, les valeurs répétées de `RateLimit` et de `RateLimit-Policy`, ainsi qu’un identifiant de requête lorsqu’il existe. Aucun statut de transport n’est déduit du seul corps GraphQL.

## 5. Registre des décisions P0

Toutes les lignes sont bloquantes tant qu’aucune preuve n’est attachée.

| ID | Question | Preuve attendue | Responsable | État |
| --- | --- | --- | --- | --- |
| BUF-P0-01 | OAuth avec deux organisations et plusieurs rôles | Trace du spike, scopes et canaux visibles | Backend + produit | Ouvert |
| BUF-P0-02 | Bucket de quota OAuth partagé entre tenants | Réponse écrite Buffer et calcul de capacité | Produit + exploitation | Ouvert |
| BUF-P0-03 | Webhook de statut disponible ou prévu | Contrat ou réponse écrite Buffer | Backend | Ouvert |
| BUF-P0-04 | Idempotence ou corrélation distante | Contrat, champ supporté ou protocole officiel | Backend | Ouvert |
| BUF-P0-05 | Recherche après timeout ambigu | Test réel après acceptation sans réponse | Backend | Ouvert |
| BUF-P0-06 | Modification, replanification et suppression par statut | Matrice testée draft à error | Backend + produit | Ouvert — edit/delete prouvés uniquement sur `draft`; move de file, replanification et autres statuts non prouvés |
| BUF-P0-07 | Capacités, formats, publication par notification et approbation distante | Matrice par canal, dont draft et needs_approval | Produit + frontend | Ouvert — format Facebook `post` et refus provisoire `move@draft/bottom` observés; autres capacités, canaux et approbation non prouvés |
| BUF-P0-08 | URL média stable et cycle de vie | Spike avec publication future et suppression différée | Backend + sécurité | Ouvert |
| BUF-P0-09 | Usage SaaS, DPA, support et incident | Validation juridique et fournisseur | Juridique + sécurité | Ouvert |
| BUF-P0-10 | Modèle commercial compte client | Parcours, coûts et prérequis validés | Produit | Ouvert |

### 5.1 Harness probatoire WP1-A

Le harness temporaire [wp1-read-only-probe.mjs](../scripts/spikes/buffer/wp1-read-only-probe.mjs) reste hors du runtime Laravel et ne constitue ni le client GraphQL, ni le gateway, ni le fake Buffer prévus en WP2.

Garde-fous :

- endpoint figé à `https://api.buffer.com` et redirections refusées ;
- environnement obligatoire et limité à `local`, `development`, `test` ou `testing` dans `APP_ENV`/`NODE_ENV` ; toute valeur de production, staging ou inconnue est refusée ;
- activation explicite par `BUFFER_WP1_PROBE_ENABLED=true` ;
- token lu depuis `BUFFER_WP1_PROBE_ACCESS_TOKEN`, jamais depuis un argument CLI ;
- timeout borné entre 1 et 30 secondes ;
- une seule requête par exécution, sans retry ;
- réponse lue en flux et bornée à 1 Mio ;
- messages d’erreur hashés et masquage exact du token sur tous les champs distants normalisés ;
- trois périodes de quota distinctes exigées et appariées entre `RateLimit` et `RateLimit-Policy` par leur label et leur `w` pour `account` et `channels` ; l’introspection `schema`, qui n’émet pas ces headers en pratique, conserve leur absence comme preuve sans invalider un contrat valide ;
- aucune mutation, persistance, tâche planifiée ou route web ;
- sortie brute considérée éphémère et interdite de commit.

Parcours opérateur lorsque des credentials de spike seront disponibles :

1. utiliser Node.js 20.6 ou plus récent et placer les variables WP1 uniquement dans le `.env` local non versionné ;
2. exécuter `npm run pulse:buffer:probe` pour relever le compte, les organisations, le client connecté, les scopes et les headers de quota ;
3. exécuter `npm run pulse:buffer:probe -- --organization=<id>` séparément pour chaque organisation autorisée ;
4. exécuter `npm run pulse:buffer:probe -- --schema` séparément pour contrôler le contrat GraphQL sans mutation ;
5. pseudonymiser la preuve avant d’en reporter le résumé dans ce journal ;
6. laisser tous les BUF-P0 ouverts tant que les rôles, mutations, timeouts ambigus, quotas inter-tenants, médias, aspects juridiques et modèle commercial ne sont pas démontrés.

Les tests du harness utilisent uniquement des réponses simulées et bloquent toute requête inattendue. Ils valident le format de la sonde, pas le comportement réel du client OAuth Malikia chez Buffer.

### 5.2 Preuve authentifiée WP1-A — résumé pseudonymisé

La collecte réelle du 2026-08-27 utilise deux requêtes GraphQL séparées et strictement read-only. Aucun fichier de réponse n’est créé.

Constats observés :

- `account` répond HTTP 200 sans erreur GraphQL et expose une seule organisation pseudonymisée `ORG-01` ;
- `connectedApps` est vide, donc le client OAuth et ses scopes propres ne sont pas observables par cette lecture ;
- `channels` répond HTTP 200 sans erreur GraphQL et expose trois canaux pseudonymisés : Instagram business, LinkedIn page et Facebook page ;
- les trois canaux sont connectés, non verrouillés, avec file active et timezone `America/Toronto` ;
- les scopes réseau comprennent la publication Instagram, l’écriture sociale LinkedIn et la gestion des publications de page Facebook ;
- les actions visibles comprennent la consultation de publication et la gestion des mises à jour/plannings, sans démontrer le nom ni le comportement d’une mutation GraphQL ;
- les quotas réels sont 100 requêtes/15 minutes, 250/jour et 3 000/30 jours ; chaque appel consomme une unité dans chacune des trois fenêtres ;
- la partition de quota opaque reste identique entre `account` et `channels` pour ce credential.

Portée probatoire :

- `BUF-P0-01` reçoit une preuve authentifiée partielle — une organisation et trois canaux visibles — mais reste ouvert car le critère exige deux organisations, plusieurs rôles et les scopes du client ;
- `BUF-P0-02` reçoit une preuve authentifiée partielle — même partition et décrément partagé entre deux opérations — mais reste ouvert faute de test inter-tenant, de réponse écrite Buffer et de calcul de capacité ;
- `BUF-P0-03` à `BUF-P0-10` ne sont pas avancés par ces lectures ;
- aucun 429 réel, mutation, média, webhook, timeout ambigu, approbation distante ou cycle edit/delete n’est testé.

Les identifiants de compte, organisation et canal, les noms, request IDs, partition keys et valeurs de token restent volontairement hors de ce document et hors de Git.

### 5.3 Preuve authentifiée WP1-B — contrat de schéma

La collecte réelle du 2026-08-27 utilise une seule opération `query PulseBufferSchemaProbe` composée exclusivement de sélections d’introspection `__type`. Elle n’embarque aucune variable, aucun identifiant métier et aucun document `mutation`.

Contrats racine observés :

| Opération | Argument(s) | Retour |
| --- | --- | --- |
| `post` | `input: PostInput!` | `Post!` |
| `posts` | `after: String`, `first: Int`, `input: PostsInput!` | `PostsResults!` |
| `createPost` | `input: CreatePostInput!` | `PostActionPayload!` |
| `editPost` | `input: EditPostInput!` | `PostActionPayload!` |
| `deletePost` | `input: DeletePostInput!` | `DeletePostPayload!` |
| `movePostInQueue` | `input: MovePostInQueueInput!` | `MovePostInQueuePayload!` |

Le schéma authentifié confirme également :

- les 14 champs de `CreatePostInput`, dont `assets: [AssetInput!]! = []`, `channelId: ChannelId!`, `mode: ShareMode!`, `needsApproval: Boolean! = false` et `schedulingType: SchedulingType!` ;
- les 14 champs de `EditPostInput`, avec `id: PostId!` et les variantes éditables nullable ;
- `DeletePostInput.id: PostId!` et `MovePostInQueueInput.position: QueuePosition!` ;
- `QueuePosition = bottom | top`, `ShareMode = addToQueue | customScheduled | shareNext | shareNow`, `SchedulingType = automatic | notification`, `PostApprovalChange = request | revert` ;
- `PostStatus = draft | error | needs_approval | scheduled | sending | sent` ;
- les unions typées de succès et d’erreurs nécessaires aux opérations Create/Edit/Delete/Move.

Le résultat réel est HTTP 200, sans erreur GraphQL et classé `success`. Contrairement aux lectures `account` et `channels`, cette introspection ne contient ni `RateLimit` ni `RateLimit-Policy`. Le harness conserve donc des tableaux de quota vides pour cette opération seulement ; il ne transforme pas cette absence en quota supposé et ne relâche pas le gate des deux opérations métier.

Portée probatoire :

- l’existence et la forme des lectures et mutations sont désormais démontrées pour ce credential ;
- cette introspection ne démontre ni autorisation effective, ni effet, ni statut final, ni idempotence, ni comportement après timeout d’une mutation ;
- `BUF-P0-05`, `BUF-P0-06` et `BUF-P0-07` reçoivent une base contractuelle, mais restent ouverts jusqu’à une matrice comportementale réelle explicitement autorisée ;
- tous les autres `BUF-P0` restent inchangés ;
- aucune création, édition, replanification, suppression ou publication distante n’a été effectuée.

Aucun request ID, token, identifiant de compte/organisation/canal, partition key ou corps brut n’est conservé dans Git.

### 5.4 Preuve authentifiée WP1-D — metadata Facebook

L'introspection authentifiée du 2026-08-27 ajoute la chaîne d'input spécifique à Facebook :

| Niveau | Contrat confirmé |
| --- | --- |
| `CreatePostInput` | `metadata: PostInputMetaData` — nullable |
| `PostInputMetaData` | `facebook: FacebookPostMetadataInput` — nullable |
| `FacebookPostMetadataInput` | `annotations: [AnnotationInputFacebook!]`, `firstComment: String`, `linkAttachment: LinkAttachmentInput`, `type: PostTypeFacebook!` |
| `PostTypeFacebook` | `post`, `reel`, `story`, tous actifs |

Le seul candidat minimal cohérent avec un brouillon Facebook texte est donc `metadata.facebook.type=post`. Cette conclusion était une **validation de forme**, pas encore une preuve comportementale : le schéma autorise aussi l'omission complète de `metadata`, et l'erreur `InvalidInputError` ne fournit aucun chemin de champ structuré. WP1-F démontre ensuite qu'un create portant cette metadata est accepté comme brouillon Facebook standard, sans attribuer rétrospectivement une cause structurée aux deux refus antérieurs.

Le probe sépare désormais deux profils :

- `full` exige le contrat complet, la capacité structurée `facebook_create_metadata` et la chaîne de sortie metadata avant toute création ;
- `cleanup` ignore toute dérive du contrat d'input de création, mais exige `post`, `deletePost` et la chaîne de sortie strictement nécessaire pour confirmer qu'un objet journalisé reste un post Facebook standard avant de le supprimer.

Le gate mutation local reste désactivé et aucune empreinte de cible n'est configurée après cette collecte. Aucun objet distant n'est actif ou à réconcilier.

### 5.5 Préparation locale WP1-E — post Facebook standard

WP1-E transforme le candidat de forme confirmé par WP1-D en contrat local exécutable, sans l'envoyer à Buffer. La variable create conserve toutes les valeurs déjà isolées et ajoute uniquement :

```text
metadata.facebook.type=post
```

La liste blanche interdit toute autre clé sous `metadata` et `facebook`. Les documents create, edit, move et inspect demandent tous la branche de sortie minimale `FacebookPostMetadata.type`; un succès n'est accepté que si le serveur retourne exactement le type Facebook `post`. Dans ce contrat historique WP1-E, edit omet la metadata en entrée et doit donc prouver sa conservation en sortie. Move reste limité à l'identifiant et à la position `bottom` et doit fournir la même preuve.

Le profil `cleanup` ne dépend toujours d'aucun type d'input create. Il valide uniquement le contrat de lecture/suppression et les quatre maillons de sortie nécessaires à l'inspection. Si l'objet inspecté n'a pas la metadata exacte, aucune suppression n'est tentée et le journal est conservé pour réconciliation. Si un create avait déjà retourné un identifiant avant une réponse metadata invalide, le `finally` conserve au contraire l'unique tentative de suppression et la vérification `NOT_FOUND` minimale.

Dans WP1-E, edit omettait la metadata et devait prouver sa conservation uniquement en sortie. L'essai WP1-F montre que Buffer refuse cet input d'édition avec `InvalidInputError`. WP1-G isole donc l'ajout exact `metadata.facebook.type=post` sur edit, sans nouvel appel distant.

### 5.6 Preuve distante WP1-F et préparation locale WP1-G

L'unique cycle autorisé pour WP1-F confirme successivement : preflight vert, create d'un brouillon Facebook `post` conforme, refus typé de l'edit, arrêt avant move, suppression unique puis lecture `NOT_FOUND`. Le journal de récupération est effacé seulement après cette dernière confirmation. Aucun objet distant, identifiant, verrou ou besoin de réconciliation ne subsiste.

WP1-G ne retente pas le cycle. Le contrat local renvoie désormais sur edit la même metadata Facebook minimale que celle acceptée au create. Il traite aussi toute issue réseau ou réponse inexploitable comme ambiguë, interdit les retries automatiques et conserve le journal dès qu'une suppression ne peut pas être confirmée. La tranche distante distincte WP1-H ci-dessous exécute ensuite ce contrat une seule fois.

### 5.7 Preuve distante WP1-H — edit confirmé, move draft refusé

WP1-H exécute une seule fois le contrat préparé par WP1-G. Le préflight initial est volontairement privé d'empreinte cible : il confirme les capacités et la cible unique, puis s'arrête avec `target_confirmation_required` avant tout journal ou document de mutation. L'empreinte fraîche est ensuite fournie uniquement au processus du cycle, sans être enregistrée dans Git.

Le cycle réel confirme que Buffer accepte l'édition du brouillon Facebook lorsque l'input edit renvoie exactement `metadata.facebook.type=post`. Le post reste `draft`, sur le même canal, avec le texte édité exact, sans date, envoi, partage ou lien externe. Cette preuve comportementale remplace l'hypothèse encore ouverte après WP1-F.

La mutation expérimentale `movePostInQueue(position: bottom)` répond ensuite par un `VoidMutationError`. Le résultat est classé `draft_move_rejected` : Buffer a répondu de façon typée, donc le harnais ne le traite ni comme une issue inconnue ni comme une réussite. Le move n'est pas rejoué. La suppression unique et sa vérification `NOT_FOUND` réussissent, sans objet distant, journal, verrou ou réconciliation manuelle résiduelle.

Cette tranche apporte une preuve partielle à `BUF-P0-06` pour create/edit/delete au statut draft et à `BUF-P0-07` pour le format Facebook `post`. Elle ne ferme aucun gate : la matrice des autres statuts, la replanification, l'approbation et les autres canaux restent à couvrir. WP1-I qualifie ce refus dans la section suivante, sans inventer de contournement ni démarrer WP2 avant la décision GO/NO-GO P0.

### 5.8 Décision WP1-I — capacité bornée par statut

La documentation Buffer officielle établit deux faits distincts :

- [`saveToDraft=true`](https://developers.buffer.com/examples/create-draft-post.html) conserve le post au statut `draft` et ne le programme pas avant une action explicite ;
- [`movePostInQueue`](https://developers.buffer.com/reference.html) est une mutation expérimentale décrite pour un post déjà présent dans la file, vers `top` ou `bottom`. Elle réordonne la file et ne revalide pas le contenu ; elle n'est pas une preuve de replanification.

Le contrat [`EditPostInput`](https://developers.buffer.com/types/EditPostInput.html) permet séparément de conserver un post en brouillon avec `saveToDraft=true`, ou de modifier son mode et sa date. Ces opérations ne doivent donc pas être fusionnées sous une capacité générique « déplacer/replanifier ».

La décision WP1-I est volontairement bornée :

- le résultat réel `VoidMutationError` reste `draft_move_rejected`, `ok=false`, définitif, non ambigu et non retryable pour cet appel ;
- la seule frontière négative observée est `Facebook Page + status=draft + position=bottom` ; le message distant n'étant pas conservé et le type d'erreur n'exposant aucun code métier structuré, aucune cause plus précise n'est affirmée ;
- le produit ne doit pas proposer un réordonnancement de file à un post encore `draft` ; les capacités devront être évaluées par statut, opération et canal dans le futur modèle WP2 ;
- le même tuple ne doit pas être rejoué sans nouvel élément contractuel et nouvelle autorisation ; aucune mutation distante n'est exécutée par WP1-I ;
- la branche de succès du harnais reste nécessaire pour détecter une évolution du contrat et pour la future matrice des posts réellement en file. Elle est couverte et n'est pas du code mort.

| Opération | Statut/cible observé | Verdict WP1-I | Portée restante |
| --- | --- | --- | --- |
| Créer un brouillon | Facebook Page, `post`, `draft` | Succès réel confirmé | Autres formats, canaux et statuts |
| Modifier un brouillon | Facebook Page, `post`, `draft` | Succès réel confirmé avec metadata exacte | Replanification, approbation et autres statuts |
| Déplacer vers `bottom` | Facebook Page, `post`, `draft` | Refus typé observé ; frontière négative provisoire | Post réellement en file, `top`, autres canaux |
| Supprimer et vérifier | Facebook Page, `post`, `draft` | Succès réel et `NOT_FOUND` confirmé | Suppression par autres statuts |
| Replanifier | Non testé | Inconnu | Matrice complète requise |

Le prochain contrat probatoire doit isoler un post réellement en file d'attente. Il ne sera exécutable qu'avec un canal de test empêchant matériellement une publication accidentelle — par exemple une file dédiée et maîtrisée — ainsi qu'un préflight de créneau, une suppression garantie et une autorisation distincte. Tant que ces préconditions ne sont pas réunies, `BUF-P0-06` et `BUF-P0-07` restent ouverts et WP2 ne démarre pas.

## 6. Systèmes de référence et invariants

### 6.1 Autorité par domaine

| Domaine | Source de vérité |
| --- | --- |
| Contenu, variante et révision | Pulse |
| Source produit, service, promotion ou campagne | Pulse |
| Voix de marque, modèles et IA | Pulse |
| Permissions et approbation | Pulse |
| Intention de date et fuseau | Pulse |
| Grant OAuth et organisations accessibles | Buffer, représentés localement |
| Canaux et capacités distantes | Buffer, mis en cache dans Pulse |
| Livraison finale | Buffer |
| Historique et audit métier | Pulse |
| Statut distant | Buffer, réconcilié dans Pulse |
| Analytics avancés et engagement | Hors contrat Pulse initial |

### 6.2 Invariants obligatoires

1. Une révision approuvée ne peut produire qu’une opération create automatique par cible et génération de récupération.
2. Une cible ne peut référencer qu’un canal, un provider et une organisation du même tenant que le post.
3. L’approbation, le gel de la révision et l’insertion de l’outbox sont atomiques dans la même transaction.
4. Seul le dispatch ou le traitement de l’outbox intervient après commit.
5. Un worker réclame une entrée par compare-and-swap avec lease expirante et fencing token avant tout appel distant.
6. Un timeout ambigu ne déclenche jamais automatiquement une nouvelle création.
7. Le transport choisi est persisté sur la cible et l’outbox avant mise en queue.
8. Un changement de feature flag ne change pas le transport d’une cible déjà créée.
9. Une programmation exacte ne devient jamais silencieusement addToQueue.
10. Un succès de mutation create signifie submitted, pas published.
11. Published est dérivé d’un statut distant sent réconcilié.
12. Partial_failed est un statut agrégé du post, jamais le statut d’une cible individuelle.
13. Les credentials, tokens, verifiers et réponses sensibles restent côté serveur.
14. Un média approuvé est immuable ; toute modification crée une nouvelle révision.
15. Une URL média reste disponible jusqu’à la date distante plus une période de grâce définie.
16. Aucun rollback n’active automatiquement le transport direct.
17. Une récupération manuelle après unknown crée une nouvelle recovery_generation ou une nouvelle révision, référence l’outbox remplacée et reste auditée.
18. Un sweeper durable retrouve périodiquement les outbox pending ou retryable et répare les leases expirées claimed ou submitting.
19. Les canaux pouvant produire draft ou needs_approval chez Buffer sont refusés ou affichés comme remote_approval_required tant que leur politique n’est pas explicitement supportée.

## 7. Architecture cible

~~~mermaid
flowchart LR
    A[Sources métier Malikia] --> B[Composeur et variantes Pulse]
    B --> C[Révision éditoriale]
    C --> D[Approbation Pulse]
    D -->|même transaction| E[Targets + Outbox]
    E -->|après commit| F[Worker de soumission]
    F --> G[Gateway Buffer]
    G --> H[API GraphQL Buffer]
    H --> I[Canaux sociaux]
    J[Stockage média de livraison] --> H
    H --> K[Réconciliation]
    K --> L[Statuts locaux et historique]
~~~

### 7.1 Composants et responsabilités

| Composant cible | Responsabilité | Ne doit pas faire |
| --- | --- | --- |
| BufferGraphqlClient | HTTP, GraphQL, headers, timeouts et réponse brute minimale | Décider des statuts métier |
| BufferOauthService | PKCE, state, callback et sélection d’organisation | Retourner des secrets au frontend |
| BufferTokenService | Refresh verrouillé, transactionnel et versionné | Rafraîchir en concurrence sans lock |
| BufferDistributionGateway | Traduire les opérations Pulse en contrat Buffer | Exposer le schéma GraphQL au domaine |
| BufferChannelSynchronizer | Organisations, canaux, capacités et tombstones | Écraser l’historique legacy |
| SocialDeliveryOutboxService | Créer, réclamer, relâcher et terminer les opérations | Effectuer un appel réseau dans la transaction métier |
| SocialDeliveryReconciler | Lire le distant et résoudre les écarts | Recréer une publication inconnue |
| SocialChannelCapabilityService | Valider texte, média et options par canal | Utiliser des limites codées en dur dans Vue |
| SocialMediaDeliveryUrlService | Produire, tester, retenir et révoquer les URL | Utiliser une URL courte durée |
| BufferErrorMapper | Classer les erreurs et actions utilisateur | Stocker la réponse complète sans filtrage |
| BufferQuotaBudget | Suivre les trois fenêtres et arbitrer les appels | Affamer un tenant au profit d’un autre |

### 7.2 Contrat métier interne

Le contrat exact sera figé pendant WP2. Il doit couvrir au minimum :

- synchroniser les organisations ;
- synchroniser les canaux ;
- créer une publication immédiate ;
- créer une publication programmée ;
- modifier ou replanifier ;
- annuler ou supprimer ;
- lire un post distant ;
- lire un ensemble de statuts ;
- normaliser les capacités ;
- retourner un résultat typé sans dépendance GraphQL dans le domaine.

Une seule implémentation de production est prévue : Buffer.

## 8. Modèle de données cible

Toutes les migrations sont additives. Les migrations historiques déjà exécutées ne sont jamais réécrites.

### 8.1 social_provider_connections

Une ligne représente le grant Buffer d’un tenant.

Champs minimaux :

- id ;
- user_id ;
- provider, fixé à buffer ;
- external_account_id ;
- external_organization_id sélectionné ;
- display_name ;
- credentials chiffrés ;
- granted_scopes ;
- credential_version ;
- status ;
- token_expires_at ;
- connected_at ;
- last_synced_at ;
- last_error_code ;
- last_error_message expurgé ;
- metadata en liste blanche ;
- timestamps.

Contraintes :

- index par user_id et status ;
- unicité du grant actif selon la politique V1 ;
- aucun canal ne duplique les credentials ;
- un refresh compare credential_version avant sauvegarde.

### 8.2 social_posts

La table actuelle possède un status unique. La migration additive introduit :

- editorial_status ;
- delivery_status agrégé ;
- sync_status agrégé ;
- current_editorial_revision ;
- approved_revision_id nullable ;
- scheduled_timezone, identifiant IANA ;
- scheduled_local_time nullable ;
- payload_hash de la révision courante ;
- dernière date de calcul des agrégats.

Le champ status historique reste temporairement alimenté pendant une période de compatibilité, puis devient lecture seule avant retrait. Le backfill doit distinguer ce qui est connu de ce qui est seulement déduit.

### 8.3 social_post_revisions

Une révision approuvée doit être un snapshot immuable réel, pas seulement un entier sur le post.

Champs minimaux :

- id ;
- user_id ;
- social_post_id ;
- revision_number ;
- base_content ;
- source_snapshot ;
- media_snapshot versionné ;
- scheduled_for ;
- scheduled_timezone ;
- scheduled_local_time ;
- payload_hash ;
- created_by_user_id ;
- approved_by_user_id nullable ;
- approved_at nullable ;
- timestamps.

L’approbation, les variantes et l’outbox référencent cette révision. Un objet média d’une révision approuvée ne peut pas être remplacé en place.

La cible reste stable par post et destination ; elle pointe seulement vers sa révision courante. L’association historique immuable entre une opération et une révision est portée par l’outbox.

La table social_approval_requests reçoit aussi social_post_revision_id. La migration suit quatre temps :

1. ajouter les FK nullables ;
2. créer une révision synthétique pour chaque post existant concerné ;
3. rattacher chaque demande d’approbation à la révision synthétique ou reconstruite appropriée ;
4. valider les orphelins avant de rendre la FK obligatoire pour les nouvelles approbations.

### 8.4 social_account_connections

Les lignes legacy ne sont pas converties en place. Elles restent lisibles pour l’historique et le drain.

De nouvelles lignes représentent les canaux Buffer et reçoivent :

- social_provider_connection_id ;
- delivery_provider ;
- transport_generation ;
- logical_destination_key ;
- external_account_id, identifiant du canal Buffer ;
- platform, réseau réel ;
- channel_type ;
- avatar_url ;
- timezone ;
- capabilities normalisées ;
- provider_status ;
- is_disconnected ;
- is_locked ;
- is_queue_paused ;
- last_synced_at ;
- metadata filtrée.

La stratégie d’unicité distingue les générations legacy et Buffer. Une barrière de cutover empêche aussi deux transports actifs vers la même logical_destination_key.

### 8.5 social_post_variants

Une variante facultative surcharge le contenu de base pour un réseau ou un canal :

- social_post_revision_id ;
- social_account_connection_id nullable ;
- platform nullable ;
- text ;
- hashtags ;
- media_snapshot ;
- link ;
- channel_options ;
- capability_validation ;
- payload_hash ;
- timestamps.

Contraintes :

- exactement un scope entre canal et plateforme ;
- unicité par révision et scope ;
- précédence canal, puis plateforme, puis contenu de base ;
- aucun changement en place après approbation.

Sans variante, la cible hérite du contenu de base de la révision.

### 8.6 social_post_targets

Une cible représente une destination éditoriale stable pour le couple post/canal. Elle n’est pas recréée pour chaque révision.

Ajouts minimaux :

- current_revision_id ;
- last_submitted_revision_id nullable ;
- delivery_provider ;
- transport_generation ;
- logical_destination_key ;
- current_editorial_revision ;
- provider_post_id ;
- provider_status ;
- delivery_status ;
- sync_status ;
- submitted_at ;
- remote_scheduled_for ;
- last_synced_at ;
- provider_error_code ;
- provider_error_message expurgé ;
- payload_hash ;
- timestamps.

L’unicité actuelle post/canal reste une règle métier. Changer de révision déplace le pointeur courant sans modifier les révisions ou outbox historiques. Une contrainte de cutover interdit aussi deux cibles actives de transports différents pour le même post et la même destination logique. Les opérations et leur association immuable à une révision sont portées par l’outbox.

### 8.7 social_delivery_outbox

L’outbox est une table, pas seulement un service.

Champs minimaux :

- id ;
- user_id ;
- social_post_target_id ;
- social_post_revision_id ;
- social_provider_connection_id ;
- operation : create, update ou cancel ;
- delivery_provider ;
- transport_generation ;
- logical_destination_key ;
- external_organization_id_snapshot ;
- external_channel_id_snapshot ;
- editorial_revision ;
- recovery_generation ;
- supersedes_outbox_id nullable ;
- idempotency_key ;
- correlation_key nullable ;
- payload_hash ;
- payload chiffré ou strictement filtré ;
- status ;
- attempts ;
- available_at ;
- claimed_at ;
- claim_expires_at ;
- claimed_by ;
- claim_token ;
- claim_version ;
- request_started_at ;
- submitted_at ;
- processed_at ;
- last_error_category ;
- last_error_code ;
- last_error_message expurgé ;
- timestamps.

Contraintes et indexes :

- unicité sur idempotency_key ;
- unicité logique cible, opération, révision et recovery_generation ;
- index de claim sur status et available_at ;
- index de récupération sur claim_expires_at ;
- index tenant et cible ;
- chaque écriture du worker compare claim_token et claim_version ;
- FK restrictives ou stratégie explicite de conservation d’audit.

États d’outbox :

- pending ;
- claimed ;
- submitting ;
- retryable ;
- unknown ;
- completed ;
- dead.

Transitions :

| Depuis | Vers | Condition |
| --- | --- | --- |
| pending ou retryable | claimed | Claim atomique et lease obtenue |
| claimed | submitting | request_started_at persisté avant l’appel |
| claimed | retryable | Échec certain avant le début de l’appel |
| submitting | completed | Mutation reconnue, résultat et provider_post_id persistés atomiquement |
| submitting | unknown | Effet distant possible mais résultat non prouvé |
| retryable | dead | Nombre maximal de tentatives atteint |
| claimed | pending | Lease expirée et request_started_at absent |
| claimed ou submitting | unknown | Lease expirée après début possible de l’appel |

L’état completed clôt l’opération d’outbox. La cible porte ensuite submitted, scheduled, sending ou published. Unknown exige une réconciliation ou une décision opérateur.

Les identités d’organisation, canal, destination logique et transport sont snapshotées sur l’outbox. Les credentials restent référencés par la connexion provider afin de pouvoir être rafraîchis sans modifier le routage de l’opération.

### 8.8 Registre de cutover

Un registre persistant doit indiquer, par tenant :

- génération de transport active ;
- date du cutover ;
- mapping legacy vers canal Buffer ;
- dernière cible legacy autorisée ;
- posts delayed encore en drain ;
- statut du pilote ;
- rollback demandé ou interdit ;
- opérateur et preuve.

Ce registre est distinct de l’entitlement commercial social.

## 9. Machines d’état

### 9.1 Éditorial

États du post :

- draft ;
- pending_approval ;
- approved ;
- rejected ;
- archived.

Une modification d’un contenu approved crée une nouvelle révision et revient à draft ou pending_approval selon le workflow.

### 9.2 Livraison d’une cible

États locaux :

- not_submitted ;
- queued ;
- submitted ;
- scheduled ;
- remote_approval_required ;
- sending ;
- published ;
- failed ;
- unknown ;
- canceled.

Une cible ne prend jamais partial_failed.

### 9.3 Agrégat du post

L’état de livraison du post est dérivé des cibles :

- not_submitted ;
- queued ;
- submitted ;
- scheduled ;
- remote_approval_required ;
- publishing ;
- published ;
- partial_failed ;
- failed ;
- unknown ;
- canceled.

Ordre de calcul :

1. unknown si une cible possède un résultat distant ambigu ;
2. partial_failed si au moins une cible est failed et une autre reste active ou a réussi ;
3. failed si toutes les cibles non annulées ont échoué ;
4. publishing si une cible est sending ;
5. remote_approval_required si une cible attend une approbation Buffer supportée ;
6. scheduled si une cible est programmée et aucune règle supérieure ne s’applique ;
7. submitted si une cible est soumise ;
8. queued si une cible attend la soumission ;
9. published si toutes les cibles non annulées sont publiées ;
10. canceled si toutes les cibles sont annulées ;
11. not_submitted sinon.

Une cible annulée volontairement est exclue du calcul du succès des autres cibles. Published avec une cible canceled reste donc published si toutes les destinations encore actives ont réussi.

### 9.4 Synchronisation

États :

- pending ;
- synced ;
- error ;
- reconnect_required.

### 9.5 Dates et fuseaux

- scheduled_for conserve l’instant de l’intention Pulse ;
- scheduled_timezone conserve explicitement le fuseau IANA ;
- scheduled_local_time conserve, si nécessaire, la valeur locale présentée à l’utilisateur ;
- dueAt est envoyé en UTC ;
- remote_scheduled_for conserve la date acceptée par Buffer ;
- tout écart est visible dans l’UI et l’audit ;
- les changements DST sont testés.

## 10. Algorithme de livraison fiable

### 10.1 Primitive unique de gel et outbox

Les quatre chemins suivants utilisent la même primitive transactionnelle :

- approbation explicite ;
- publication directe par un acteur autorisé ;
- programmation directe par un acteur autorisé ;
- publication Autopilot autorisée par une politique.

Une publication directe matérialise une approbation implicite auditée de la révision par l’acteur autorisé. Autopilot matérialise une approbation de politique avec sa règle, sa version et son audit. Aucun de ces chemins ne contourne le gel de révision ou l’outbox.

Dans une transaction :

1. Verrouiller le post et la demande ou politique applicable.
2. Revalider permissions, tenant, règle Autopilot et révision affichée.
3. Marquer la révision approved avec le type d’approbation.
4. Geler le snapshot de contenu et de média.
5. Créer ou mettre à jour les cibles.
6. Persister delivery_provider et transport_generation.
7. Insérer une entrée d’outbox par cible.
8. Enregistrer l’audit.
9. Commit.

Après commit seulement :

10. Réveiller le traitement de l’outbox par un signal best effort.
11. Laisser un dispatcher périodique durable balayer pending et retryable si ce signal est perdu.
12. Laisser un reaper traiter les leases expirées : claimed sans request_started_at revient à pending ; claimed ou submitting après début possible de l’appel passe à unknown.

Deux approbateurs ou deux déclencheurs concurrents doivent aboutir à une seule résolution et une seule opération create automatique par cible et génération.

### 10.2 Claim et soumission

Le worker :

1. réclame une entrée par compare-and-swap ;
2. attribue une lease bornée, un claim_token et une claim_version ;
3. vérifie tenant, provider, organisation, canal et révision ;
4. vérifie la capacité et le média ;
5. obtient un token valide via le service verrouillé ;
6. persiste submitting et request_started_at avant l’appel ;
7. appelle le gateway ;
8. persiste atomiquement provider_post_id, le résultat cible et completed en comparant le fencing token ;
9. termine ou reprogramme l’entrée ;
10. déclenche la réconciliation appropriée.

Une lease expirée est reprise automatiquement seulement si request_started_at est absent. Si l’appel a commencé ou a pu commencer, l’entrée passe en unknown. Une opération completed ne peut jamais être réclamée de nouveau. Les écritures tardives d’un ancien worker sont refusées par claim_token et claim_version.

### 10.3 Timeout ambigu

Si la requête a pu atteindre Buffer mais qu’aucune réponse exploitable n’est reçue :

1. marquer l’outbox et la cible unknown ;
2. conserver idempotency_key, correlation_key et payload_hash ;
3. ne pas rappeler create ;
4. tenter une recherche ou réconciliation supportée par Buffer ;
5. si aucune corrélation fiable n’existe, créer une tâche opérateur ;
6. autoriser une nouvelle création uniquement après décision explicite et auditée ;
7. créer alors une recovery_generation ou une révision supérieure avec supersedes_outbox_id.

Le système ne promet pas exactly-once distant tant que Buffer ne fournit pas de preuve contractuelle ou de mécanisme de corrélation suffisant.

### 10.4 Taxonomie des erreurs

| Catégorie | Exemples | Action |
| --- | --- | --- |
| validation | format, capacité, média | Failed, correction utilisateur, aucun retry |
| authentication | 401, grant révoqué | reconnect_required, pause des livraisons |
| authorization | scope ou organisation refusée | Failed ou reconnexion selon diagnostic |
| rate_limit | 429 | Retry après Retry-After et budget |
| retryable | échec prouvé avant émission ou rejet garanti sans effet | Backoff borné |
| ambiguous | timeout, 5xx ou erreur système après un create possiblement accepté | Unknown et réconciliation, aucun create aveugle |
| permanent | canal supprimé, mutation refusée | Failed, action utilisateur |
| unexpected | erreur GraphQL système | Lecture retryable ; create unknown si l’effet distant reste possible |

La classification dépend de l’opération. Un 5xx peut être retryable pour une lecture et ambigu pour un create. Les erreurs réellement retryables remontent au worker ; elles ne sont jamais absorbées comme un échec terminal silencieux.

### 10.5 Quotas et équité

Le budget doit :

- lire les trois fenêtres documentées ;
- identifier chaque fenêtre par sa durée ;
- réserver une marge pour OAuth, reconnexion et actions manuelles ;
- répartir les appels entre tenants ;
- ralentir le polling avant le 429 ;
- respecter Retry-After ;
- exposer les seuils 20 % et 10 % ;
- empêcher un tenant bruyant de consommer tout le bucket ;
- conserver la création et l’approbation locales lorsque Buffer est limité.

### 10.6 Réconciliation

La réconciliation est :

- immédiate après une soumission lorsque nécessaire ;
- plus fréquente pour submitted, sending et unknown ;
- plus lente pour scheduled lointain ;
- arrêtée pour les états terminaux stables ;
- déclenchable manuellement ;
- budgetée selon les quotas ;
- tolérante aux événements hors ordre et aux réponses obsolètes.

## 11. Parcours métier et critères d’acceptation

| Parcours | Critère d’acceptation |
| --- | --- |
| Connexion OAuth | State consommé une seule fois, PKCE serveur, aucun secret dans le DTO |
| Multi-organisation | L’owner choisit explicitement une organisation et ne voit que ses canaux autorisés |
| Synchronisation | Ajout, mise à jour, déconnexion et tombstone sont idempotents |
| Canal avec approbation Buffer | Refus explicite ou état remote_approval_required supporté et visible |
| Création | Le brouillon ne produit aucun appel Buffer |
| Approbation | Une seule révision gelée et une seule outbox sous concurrence |
| Publication directe ou Autopilot | Approbation implicite ou de politique auditée dans la même transaction |
| Publication immédiate | La cible devient submitted, puis published seulement après statut sent |
| Programmation exacte | dueAt UTC est envoyé immédiatement et remote_scheduled_for est persisté |
| Prochaine plage | addToQueue est une action séparée et explicite |
| Modification | Une nouvelle révision est auditée et la mutation distante dépend du statut |
| Annulation | L’état local ne devient canceled qu’après résultat distant ou résolution explicite |
| Timeout ambigu | Unknown, aucun deuxième create automatique |
| 401 | Reconnexion visible, brouillons conservés, livraisons en pause |
| 429 | Retry-After respecté, aucune boucle agressive |
| Média inaccessible | Échec avant soumission lorsque détectable, action corrective visible |
| Canal supprimé | Canal désactivé localement, historique conservé |
| Mode dégradé | Création et approbation locales continuent, outbox visible |
| Signal après commit perdu | Le sweeper reprend l’outbox sans action utilisateur |

## 12. Sécurité, confidentialité et médias

### 12.1 OAuth

- Authorization Code + PKCE pour le client OAuth ;
- state fort, unique, expirant et consommé atomiquement ;
- verifier stocké uniquement côté serveur ;
- redirect URI en liste blanche ;
- scopes minimaux validés par le spike ;
- access et refresh tokens chiffrés ;
- refresh sous verrou distribué ;
- transaction et credential_version pour éviter l’écrasement ;
- révocation et reconnexion auditables.

### 12.2 Isolation tenant

Chaque action serveur et chaque worker vérifie :

- user_id du post ;
- user_id du canal ;
- user_id de la connexion provider ;
- organisation Buffer sélectionnée ;
- transport_generation ;
- permissions de l’acteur pour les actions interactives.

Une corruption de FK ou un import invalide doit échouer avant tout appel Buffer.

### 12.3 DTO et logs

- DTO en liste blanche ;
- aucun credentials ou metadata brut ;
- aucune réponse provider complète persistée par défaut ;
- redaction des tokens, URLs sensibles, contenu et identifiants inutiles ;
- codes d’erreur structurés ;
- logs corrélés par IDs internes et provider_post_id ;
- audit séparé des logs techniques.

### 12.4 Médias

Le service de livraison média doit fournir :

- URL opaque et non devinable ;
- HTTPS public sans cookie ni authentification ;
- type MIME et longueur cohérents ;
- probe avant soumission ;
- disponibilité jusqu’à remote_scheduled_for plus une période de grâce ;
- suppression différée ;
- révocation et purge auditables ;
- politique explicite de rétention et d’export.

### 12.5 Juridique et fournisseur

Avant GO lancement pilote :

- usage SaaS multi-tenant confirmé ;
- DPA et registre de sous-traitants mis à jour ;
- politique de confidentialité mise à jour ;
- suppression et export documentés ;
- clauses de disponibilité et changement d’API comprises ;
- règles de marque validées ;
- runbook d’incident fournisseur approuvé.

## 13. Expérience utilisateur cible

### 13.1 Six surfaces

1. **Aperçu** — santé Buffer, planning, validations, erreurs et quotas utiles.
2. **Publications** — brouillons, à valider, programmées, publiées et erreurs.
3. **Créer** — contenu de base et variantes facultatives.
4. **Calendrier** — intention Pulse, date distante et conflits.
5. **Bibliothèque** — médias, modèles et voix de marque.
6. **Canaux & Buffer** — connexion, organisation, canaux et diagnostic.

Campagnes et Autopilot deviennent des modes ou entrées contextuelles, pas nécessairement des destinations principales.

### 13.2 Contrat frontend de canal

Le serveur expose un DTO stable :

| Champ | Usage |
| --- | --- |
| id | Identifiant local |
| network | Réseau affiché |
| name et handle | Identité du canal |
| avatar_url | Présentation |
| timezone | Programmation |
| capabilities | Validation du composeur |
| active | Choix Pulse |
| connection_status | Santé du canal |
| sync_status | État local/distant |
| last_synced_at | Diagnostic |

Aucun champ GraphQL brut ou secret OAuth n’est exposé.

### 13.3 Corrections impératives

- Ajouter disabled à DropzoneInput et verrouiller tout média pending_approval.
- Afficher la révision approuvée et détecter une révision obsolète.
- Afficher status, failure_reason et action par cible dans l’historique.
- Afficher séparément statut éditorial, livraison et synchronisation.
- Afficher scheduled_for, remote_scheduled_for et le fuseau.
- Alimenter les capacités depuis le serveur.
- Remplacer la grille mobile de 42 cellules par une vue agenda.
- Remplacer window.confirm par la modale commune.
- Ajouter aria-live, aria-busy, focus restauré et navigation clavier.
- Ne jamais imbriquer bouton et lien interactifs.

### 13.4 Découpage frontend

Les composants monolithiques sont séparés au minimum en :

- PulseComposerForm ;
- PulseChannelPicker ;
- PulseVariantEditor ;
- PulseSchedulePanel ;
- PulseComposerActions ;
- PulsePostPreview ;
- PulseDeliveryStatus ;
- BufferConnectionCard ;
- BufferOrganizationPicker ;
- BufferChannelList ;
- PulseRecoveryAction.

Un client/composable Pulse unique centralise les appels, erreurs, refresh et normalisations.

## 14. Migration et cutover

### 14.1 Principes

- Ne jamais convertir les connexions directes en place.
- Créer de nouvelles lignes de canaux Buffer.
- Conserver les connexions legacy en lecture seule pour l’historique et le drain.
- Persister le transport sur chaque cible et outbox.
- Ne jamais faire partir une même cible par deux transports.
- Ne jamais réactiver automatiquement le direct pendant un rollback.

### 14.2 Références à remapper

Le mapping owner-validé doit couvrir :

- social_automation_rules.target_connection_ids ;
- social_post_templates.metadata.selected_target_connection_ids ;
- brouillons actifs ;
- posts futurs ;
- snapshots actifs ou futurs de brouillons et modèles ;
- données déterministes de démo.

Les snapshots historiques publiés restent inchangés.

Les jobs delayed déjà sérialisés ne sont jamais remappés. Ils sont inventoriés puis drainés, ou annulés et recréés explicitement après réconciliation. Les identités de routage de leurs cibles et connexions restent immuables tant que le code direct nécessaire au drain existe. Les credentials, token_expires_at et statuts de connexion restent toutefois rafraîchissables.

### 14.3 Séquence

1. Fermer les gates P0 fournisseur.
2. Inventorier connexions, cibles et jobs delayed legacy.
3. Ajouter les colonnes et FK nouvelles comme nullables, sans changer le transport.
4. Créer les révisions synthétiques, rattacher les approbations et initialiser le pointeur courant des cibles.
5. Backfiller delivery_provider=direct, transport_generation=direct_v1 et logical_destination_key sur les connexions et cibles legacy.
6. Valider les orphelins et incohérences, puis activer les contraintes applicables aux nouvelles données.
7. Figer les identités de routage déjà en queue, garder leurs credentials rafraîchissables et conserver leur worker direct pendant le drain.
8. Déployer gateway et fake Buffer.
9. Activer OAuth et synchronisation en lecture seule.
10. Créer de nouvelles lignes canal Buffer.
11. Faire valider le mapping par l’owner.
12. Exécuter une validation de capacités en shadow, sans publication.
13. Définir durée, seuils d’incident et critères du canary, puis signer le GO lancement pilote.
14. Choisir un tenant pilote et persister son transport Buffer par nouvelle cible.
15. Publier un scénario contrôlé.
16. Réconcilier et observer.
17. Drainer ou annuler/recréer explicitement les posts legacy delayed.
18. Étendre le canary puis réunir les preuves du GO général.
19. Fermer la création de connexions directes.
20. Découpler le service de suppression de données Facebook du transport Pulse.
21. Révoquer les secrets directs seulement après drain et fenêtre de retour.
22. Retirer le transport direct.

### 14.4 Feature flags et kill switches

Séparer :

- entitlement commercial social ;
- disponibilité globale de la connexion Buffer ;
- synchronisation de canaux ;
- livraison Buffer ;
- réconciliation ;
- connexions directes en lecture seule ;
- livraison directe interdite ;
- état de cutover par tenant.

Le flag mutable ne remplace jamais delivery_provider et transport_generation persistés.

### 14.5 Rollback

Le rollback :

- suspend les nouvelles soumissions Buffer ;
- conserve brouillons, approbations et outbox ;
- laisse les opérations inconnues en réconciliation ;
- empêche toute duplication sur le direct ;
- ne change pas le transport des cibles existantes ;
- exige une décision opérateur pour chaque opération ambiguë.

## 15. Stratégie de tests

### 15.1 Suites à préserver

Conserver les tests du :

- composeur ;
- historique ;
- calendrier ;
- approbations ;
- modèles ;
- préremplissage ;
- suggestions ;
- voix de marque ;
- médiathèque ;
- campagnes ;
- Autopilot ;
- permissions et feature tenant.

Les tests Auth/Social liés à la connexion utilisateur Malikia restent hors scope.

Les tests du transport et OAuth directs sont remplacés progressivement par les contrats Buffer.

### 15.2 Tests unitaires

- sérialisation GraphQL ;
- parsing data, errors et unions ;
- mapping des statuts ;
- mapping des capacités ;
- calcul des clés d’idempotence ;
- transitions d’outbox ;
- classification des erreurs ;
- lecture des trois fenêtres de quota ;
- conversion fuseau vers UTC ;
- payload hash et révision ;
- précédence des variantes canal, plateforme et contenu de base ;
- ordre de calcul de l’agrégat de livraison ;
- rétention média.

### 15.3 Tests Feature et concurrence

- outbox insérée dans la même transaction que l’approbation ;
- aucun dispatch avant commit ;
- deux approbateurs concurrents ;
- deux workers réclamant la même entrée ;
- ancien worker refusé par le fencing token après expiration de lease ;
- lease expirée et reprise ;
- crash avant l’appel ;
- crash après acceptation Buffer ;
- timeout ambigu sans retry create ;
- 5xx ambigu pendant create ;
- retry interne avec même clé ;
- récupération manuelle avec recovery_generation et supersession ;
- commit réussi mais signal after-commit perdu, puis reprise par sweeper ;
- worker tué après claim ou request_started_at, puis réparation par le reaper ;
- post et canal de tenants différents ;
- OAuth state expiré, rejoué et concurrent ;
- deux refreshs concurrents ;
- erreurs GraphQL sous HTTP 200 ;
- 401, 429, Retry-After et 5xx ;
- événements distants hors ordre ;
- modification et annulation par statut ;
- URL média inaccessible ou expirée ;
- transport stable malgré un toggle ;
- barrière anti-double sur une destination logique legacy/Buffer ;
- migration JSON des règles et modèles ;
- backfill des trois axes de social_posts et du fuseau IANA ;
- révisions synthétiques et rattachement des approval requests sans orphelin ;
- routage snapshoté malgré un changement ultérieur d’organisation sélectionnée ;
- drain d’un delayed job legacy ;
- rollback sans dual delivery ;
- aucune requête réseau non simulée.

### 15.4 Tests de contrat Buffer

Le spike et le fake doivent couvrir :

- deux organisations ;
- plusieurs canaux ;
- texte et média ;
- shareNow ;
- customScheduled et dueAt ;
- addToQueue séparé ;
- transitions draft à error ;
- canal produisant needs_approval et politique remote_approval_required ;
- edit et delete selon statut ;
- révocation ;
- quotas et 429 ;
- timeout après acceptation ;
- absence ou présence réelle d’un mécanisme de corrélation.

### 15.5 Frontend et E2E

- média immuable pendant approbation ;
- erreur visible par cible ;
- trois axes de statut ;
- action reconnecter, resynchroniser ou corriger ;
- capacités serveur ;
- fuseaux et DST ;
- agenda mobile ;
- clavier, focus et lecteurs d’écran ;
- parcours connecter, choisir organisation, synchroniser, créer, approuver, programmer et réconcilier ;
- mode dégradé et rollback visible.

### 15.6 Démos

- fake Buffer déterministe ;
- aucune credential réelle ;
- aucun appel réseau ;
- organisations, canaux, quotas et statuts reproductibles ;
- au moins un succès, un échec récupérable et un unknown ;
- mapping de démo vérifié.

## 16. Observabilité et exploitation

### 16.1 Métriques

- connexion active ou reconnexion requise ;
- canaux actifs, supprimés, verrouillés ou en pause ;
- taille et âge maximal de l’outbox ;
- nombre de claims expirés ;
- cibles submitted et remote_approval_required ;
- outbox submitting, unknown, completed et dead ;
- temps approbation vers acceptation Buffer ;
- temps acceptation vers sent ;
- taux d’échec par mutation, réseau et code ;
- quotas restants sur trois fenêtres ;
- retries et réconciliations ;
- écarts entre scheduled_for et remote_scheduled_for ;
- doublons évités ou suspectés ;
- médias inaccessibles.

### 16.2 Alertes

- quota sous 20 %, puis 10 % ;
- plusieurs 401 ;
- plusieurs 429 ;
- unknown au-delà du SLA ;
- outbox trop ancienne ;
- claim expiré répété ;
- réconciliation en retard ;
- hausse des erreurs unexpected ;
- média inaccessible ;
- post distant sans mapping local ou inversement ;
- suspicion de double publication.

### 16.3 Runbooks

- Buffer indisponible ;
- quota épuisé ;
- refresh token révoqué ;
- timeout ambigu ;
- média inaccessible ;
- post bloqué ou suspecté dupliqué ;
- organisation ou canal supprimé ;
- événement distant hors ordre ;
- drain legacy ;
- rollback du cutover ;
- incident de sécurité fournisseur.

## 17. Lots d’implémentation

| Lot | Dépendances | Livrables | Definition of Done |
| --- | --- | --- | --- |
| WP0 — stabilisation legacy | Aucune | Approval lock, afterCommit, erreurs retryables, invariant tenant, média verrouillé, erreurs par cible visibles | Régressions actuelles couvertes, aucun changement Buffer |
| WP1 — spike Buffer | Accès fournisseur | OAuth réel, organisations, mutations, quotas, timeout, matrice edit/delete | BUF-P0-01 à 10 fermés ou NO-GO |
| WP2 — fondation | GO fondation | Migrations, client, gateway, fake, DTO et error mapper | Contrats unitaires et migrations rollbackables |
| WP3 — connexion et canaux | WP2 | OAuth, refresh lock, organisations, sync et capacités | Deux organisations testées, aucun secret frontend |
| WP4 — livraison fiable | WP2 + WP3 | Outbox, claim, média, quotas, soumission et réconciliation | Tests concurrence, ambiguous et cross-tenant verts |
| WP5 — UX | DTO WP2 + statuts WP4 | Six surfaces, composeur scindé, agenda et récupération | E2E, responsive et accessibilité verts |
| WP6 — pilote et migration | GO lancement pilote + WP1 à WP5 | Mapping, shadow, canary, drain et rollback | Preuves réunies pour le GO général |
| WP7 — retrait direct | GO général + drain terminé | Routes, providers, config et secrets retirés | Aucun tenant/post actif sur le direct |

Chaque lot possède :

- une migration ou stratégie de rollback ;
- des tests ciblés ;
- une preuve attachée ;
- un propriétaire ;
- un statut dans le journal de décision.

## 18. Gates GO / NO-GO

### 18.1 GO fondation

- OAuth et refresh validés sur deux organisations ;
- scopes et rôles compris ;
- mutations et erreurs réelles comprises ;
- quota partagé connu et capacité estimée avec marge ;
- stratégie de timeout ambigu acceptée ;
- usage SaaS, DPA et modèle commercial compatibles.

### 18.2 GO lancement pilote

- outbox transactionnelle et réconciliation opérationnelles ;
- concurrence approval, claim et refresh testée ;
- aucune seconde création automatique après timeout ambigu ;
- invariant tenant démontré ;
- URL média stable démontrée ;
- quotas et polling mesurés ;
- observabilité et runbooks disponibles ;
- rollback testé ;
- aucun dual delivery ;
- durée minimale du canary et seuils d’incident fixés avant la première publication.

### 18.3 GO général

- quotas contractuellement suffisants ;
- canary sans incident critique pendant la durée décidée ;
- support et diagnostics validés ;
- migration des références actives terminée ;
- aucun post legacy à risque ;
- sécurité, DPA, confidentialité et rétention approuvées ;
- changelog et contract tests intégrés au processus.

### 18.4 NO-GO

Le projet s’arrête ou change de fournisseur si :

- OAuth tiers ou usage SaaS est refusé ;
- les quotas sont insuffisants sans accord possible ;
- le risque de timeout ambigu est jugé inacceptable et aucune corrélation n’est possible ;
- le refresh rotatif ne peut pas être sécurisé ;
- aucune URL média stable ne peut être garantie ;
- les conditions juridiques sont incompatibles ;
- l’isolation tenant ou le rollback ne peut pas être démontré ;
- le modèle commercial ne peut pas être rendu transparent au client.

### 18.5 Statut courant

**BLOCKED_P0** : architecture retenue, aucun GO fondation, pilote ou général.

## 19. Risques résiduels

| Risque | Impact | Mitigation |
| --- | --- | --- |
| Buffer point unique de panne | Retard de livraison | Outbox, mode dégradé et réconciliation |
| Quotas partagés trop faibles | Blocage multi-tenant | Gate fournisseur, budget et équité |
| Timeout ambigu | Double publication ou opération bloquée | Unknown, corrélation et décision opérateur |
| API récente et changeante | Régression | Client isolé, contract tests et veille changelog |
| Aucun webhook confirmé | Polling coûteux | Polling adaptatif et sync manuelle |
| Refresh à usage unique | Grant révoqué | Lock, transaction et version |
| Média public requis | Risque de confidentialité ou expiration | URL opaque, rétention et purge |
| Mauvais mapping legacy | Publication sur le mauvais canal | Validation owner, shadow et audit |
| Feature flag mutable | Changement de transport inattendu | Provider persisté sur cible et outbox |
| Confusion avec le social login | Régression d’authentification | Périmètre et tests séparés |
| Analytics incomplets | Promesse produit non tenue | KPIs opérationnels seulement |
| Dépendance fournisseur | Coût de sortie | Gateway, données et historique locaux |

## 20. Gouvernance documentaire

Ce document est la référence active pour le transport Buffer-first.

Il remplace les sections de connexion, providers et publication directs de :

- [Documentation technique Pulse](MALIKIA_PULSE_DOCUMENTATION_TECHNIQUE_2026-04-25.md) ;
- [Backlog Pulse](MALIKIA_PULSE_DEV_BACKLOG_2026-04-22.md) ;
- [User story Pulse](MALIKIA_PULSE_USER_STORY_2026-04-22.md).

Il précise aussi la portée des documents encore à classer :

- [Spécification Autopilot](MALIKIA_PULSE_AUTOPILOT_SPEC_2026-04-25.md) : intentions métier conservées, handoff vers PublishSocialPostTargetJob supersédé ;
- [Plan AI Creative et Autopilot](MALIKIA_PULSE_AI_CREATIVE_AUTOPILOT_PLAN_2026-04-26.md) : génération et gouvernance conservées, transport direct supersédé ;
- [Roadmap Pulse](MALIKIA_PULSE_ROADMAP_8_AMELIORATIONS_3_ETAPES_2026-04-26.md) : intentions produit à remapper sur les lots WP0 à WP7.

Le document [Social Auth + onboarding](SOCIAL_AUTH_ONBOARDING_USER_STORY_2026-04-23.md) reste hors scope.

Leur classement effectif doit être enregistré dans document-status.json puis vérifié par le générateur d’index. Le fichier 00_INDEX.md ne doit jamais être édité à la main.

Toute nouvelle modification Pulse doit respecter :

- aucun publisher direct supplémentaire ;
- aucune réécriture des migrations historiques ;
- aucun couplage du domaine au schéma GraphQL ;
- aucun statut de livraison inventé dans le frontend ;
- aucune garantie exactly-once sans preuve ;
- aucune suppression du social login ou de la suppression de données Facebook sans découplage explicite.

## 21. Journal de décisions

| ID | Décision | Statut | Preuve | Responsable | Date |
| --- | --- | --- | --- | --- | --- |
| ADR-PULSE-001 | Buffer devient l’unique transport cible | Acceptée sous gates P0 | Ce document | À assigner | 2026-08-26 |
| ADR-PULSE-002 | Pulse conserve contenu, approbation et historique | Acceptée | Audit du domaine | À assigner | 2026-08-26 |
| ADR-PULSE-003 | Aucun fallback direct automatique | Acceptée | Invariant 16 | À assigner | 2026-08-26 |
| ADR-PULSE-004 | Outbox insérée dans la transaction métier | Acceptée | Invariant 3 | À assigner | 2026-08-26 |
| ADR-PULSE-005 | Timeout ambigu sans retry create | Acceptée | Section 10.3 | À assigner | 2026-08-26 |
| ADR-PULSE-006 | Compte Buffer détenu par le client pour le MVP | À valider P0 | BUF-P0-10 | Produit | — |
| ADR-PULSE-007 | Polling tant qu’aucun webhook n’est confirmé | À valider P0 | BUF-P0-03 | Backend | — |
| ADR-PULSE-008 | Analytics avancés hors MVP | Acceptée | Contrat public Buffer | Produit | 2026-08-26 |
| ADR-PULSE-009 | WP0-S se déploie atomiquement sous maintenance ; le rolling exige un pont en trois phases | Acceptée pour le lot courant | Gate 0.1 et EV-PULSE-006 | DevOps | 2026-08-27 |
| ADR-PULSE-010 | Les capacités Buffer sont bornées par statut/opération/canal ; `move@draft/bottom` reste une frontière négative provisoire et ne vaut ni replanification ni incapacité globale | Acceptée pour WP1 ; à reconfirmer sur un post réellement en file | WP1-I, BUF-P0-06/07 | Backend + produit | 2026-08-27 |

## 22. Conclusion

La valeur de Pulse n’est pas le transport social. Sa valeur est de transformer les données réelles d’une entreprise en contenu cohérent, validé, planifié et traçable.

Buffer peut retirer à Malikia la maintenance des intégrations réseau, mais seulement si la livraison est traitée comme un système distribué soumis aux quotas, aux erreurs ambiguës, à la rotation de tokens et à la réconciliation.

La règle finale est :

> **Pulse décide quoi publier, sur quels canaux, quand et après quelle validation. Buffer exécute la livraison. L’outbox et la réconciliation rendent l’incertitude visible, bloquent le retry automatique ambigu et réduisent le risque de double publication, sans garantir l’exactly-once distant.**

WP0-S est fermé sur le plan du code et des validations locales. Son gate de déploiement production reste ouvert jusqu’à l’approbation opérationnelle, la répétition MySQL et l’exécution de la procédure atomique retenue par ADR-PULSE-009. Si le rolling devient une exigence, cette décision devra être rouverte et le pont en trois phases implémenté. En parallèle, WP1 doit encore produire des preuves authentifiées propres au client OAuth Buffer Malikia. Les fondations métier WP2, le pilote et le cutover restent interdits avant la fermeture documentée de ces gates P0.
