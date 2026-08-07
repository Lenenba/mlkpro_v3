# Audit de couverture — démo Salon Éclat

- Date : 2026-08-07
- Statut : en cours de validation
- Preset : `salon_eclat_complete`
- Document de référence : [Démo vidéo — Salon de coiffure / beauté](../../DEMO_VIDEO_SALON_COIFFURE.md)

## Verdict

Le preset immersif comble les principaux trous du précédent preset centré sur la file. Il fournit désormais un Salon Éclat exploitable avec son identité, son équipe, son catalogue, ses produits, son POS, ses offres récurrentes, sa fidélité et ses scénarios de croissance.

La preuve financière seedée est réelle au niveau métier : un ticket de file terminé est relié à une facture taxée, un paiement local comptant, un pourboire, son attribution et un reçu. Elle ne simule jamais Stripe. Le paiement par carte reste une validation E2E séparée, à exécuter avec une vraie configuration Stripe de test.

## Ce que le preset provisionne

| Domaine | Couverture du preset immersif | Preuve attendue |
| --- | --- | --- |
| Identité | Seedé | **Salon Éclat**, owner **Amina Diallo**, français et `America/Toronto`. |
| Équipe | Seedé | Sophie Tremblay, Karim Benali et Léa Moreau, soit 3 employés en plus d'Amina. |
| Catalogue services | Seedé | 5 rubriques métier et 10 prestations avec des prix et durées crédibles. |
| Produits et POS | Seedé | 5 produits de revente avec stock, plus des traces de ventes POS rattachées aux clients. |
| Clients narratifs | Seedé | Marie, Julie, Fatou, Thomas et Claire portent les histoires fidélité, VIP, abonnement et winback. |
| Forfaits et fidélité | Seedé | 3 forfaits, attribution et consommations, points et historique fidélité. |
| Promotion et campagne | Seedé | Promotion `RENTREE20` et campagne `WINBACK`. |
| Assistant | Seedé | Réglages et contenu de connaissance adaptés au salon. |
| Social | Seedé | Module Social actif et contenu de publication prêt à présenter. |
| Réservation publique | Seedé | Lien public actif relié aux prestations du salon. |
| Réservations et file | Seedé | Réservations, ressources, disponibilités et tickets représentant plusieurs états de la file. |
| Encaissement local | Seedé et relié | Ticket terminé → facture → paiement comptant → pourboire → reçu. |
| Comptabilité | Seedée | Journal, lots, taxes et contrôle financier issus des factures, paiements, ventes et dépenses de la démo. |
| Modules hors métier | Exclus | Devis, Demandes, Scan de plans, Chantiers et Tâches restent désactivés. |

## Preuve financière seedée

Le scénario de référence doit conserver toute la chaîne de traçabilité suivante :

1. un ticket passe réellement par la fin de service dans la file ;
2. l'encaissement local est exécuté avec le moyen **comptant**, pas en modifiant directement le statut de la facture ;
3. la facture liée au ticket conserve le sous-total, le détail fiscal et le total avec les taxes Québec à **14,975 %** ;
4. le paiement lié conserve le montant de la facture, un pourboire de **18 %**, le total réellement encaissé et le membre bénéficiaire ;
5. le ticket est clôturé et le reçu demeure consultable depuis la trace de paiement/facturation.

Ce scénario doit passer par les mêmes services applicatifs que l'encaissement local utilisé en production. Il valide donc les calculs, les relations et les transitions, sans dépendre d'un fournisseur externe.

## Frontière Stripe — règle de vérité

Le seed ne doit créer aucun des éléments suivants :

- fausse session Stripe Checkout ;
- faux PaymentIntent ;
- fausse référence de paiement Stripe ;
- tentative Stripe marquée artificiellement comme réussie.

Une démonstration de carte est valide seulement si le test E2E réalise réellement cette séquence :

1. Stripe ou Stripe Connect est configuré en mode test pour le workspace ;
2. le salon sélectionne **Carte** et reçoit une vraie URL Checkout Stripe ;
3. une carte de test Stripe termine le paiement ;
4. le webhook et/ou la vérification de retour confirme la transaction ;
5. le navigateur revient sur la plateforme ;
6. la facture est payée, les taxes et le pourboire sont cohérents, le ticket est clôturé et le reçu est accessible.

Cette validation dépend des clés, du compte connecté, du webhook et des URL de retour de l'environnement. Elle doit être rejouée avant le tournage, mais elle n'appartient pas aux données seedées.

## Couverture encore à valider

| Sujet | Statut | Condition de fermeture |
| --- | --- | --- |
| Paiement Stripe réel et retour plateforme | E2E requis | Test Stripe de bout en bout vert sur l'environnement de démonstration. |
| Envoi réel du reçu par email ou SMS | Intégration requise | Transport de test configuré, envoi reçu et contenu vérifié. |
| Portail client complet | Partiel | Réservation, facture, paiement, reçu, fidélité et forfaits vérifiés avec un accès client. |
| Rappels, avis et no-show | Partiel | Jobs/notifications et règles d'absence rejoués dans un scénario horodaté. |
| Packs récurrents | Partiel | Renouvellement, facturation et annulation vérifiés au-delà des données initiales. |
| Campagne et Social externes | Partiel | Prévisualisation locale puis envoi/publication via des intégrations de test. |
| Assistant connecté | Partiel | Réponse publique et interne validée sans fuite de données. |
| Mode sécurisé des workspaces de démo | À vérifier | Mutations nécessaires autorisées sans ouvrir les fonctions sensibles. |

## Critères de validation automatisée

Les tests du preset doivent au minimum vérifier :

- l'identité et l'absence des modules de chantier/devis ;
- les 3 employés, 10 prestations et 5 produits ;
- les forfaits, usages, points, promotion, campagne, assistant et publication sociale ;
- le lien public et son rattachement aux prestations ;
- la facture issue de la file avec son instantané fiscal à 14,975 % ;
- le paiement local, le pourboire de 18 %, son bénéficiaire et le total encaissé ;
- le ticket clôturé, le reçu accessible et l'absence de tentative Stripe seedée ;
- le reset et la purge du workspace sans résidu ni erreur de clé étrangère.

## Critère de fermeture

Le preset sera considéré comme terminé lorsque ses tests automatisés seront verts et que le runbook pourra être joué sans saisie manuelle structurelle. La couverture Stripe ne pourra être déclarée terminée qu'après un test E2E réel sur un environnement correctement configuré ; le succès du checkout local seedé ne suffit pas à cette conclusion.
