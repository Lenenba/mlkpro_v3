# Audit de couverture — démo Salon Éclat

- Date : 2026-08-07
- Document de référence : `docs/DEMO_VIDEO_SALON_COIFFURE.md`
- Cible métier documentée : complète
- Validation exécutable de la démo : en cours

## Verdict

La démo salon actuelle ne couvre pas encore toutes les options prévues pour Salon Éclat et Amina Diallo.

Son infrastructure est plus robuste pour créer, cloner, réinitialiser et purger un workspace. En revanche, sa couverture métier et commerciale est moins complète que le scénario Salon Éclat. Le preset actuel démontre surtout les réservations et la file.

## Couverture actuelle

| Domaine | Couverture | Écart avec Salon Éclat |
| --- | --- | --- |
| Provisioning, clone, reset, purge et rôles | Couvert | Plus robuste que le scénario manuel. |
| Identité Salon Éclat et Amina Diallo | Partiel | Le builder accepte le nom et le branding, mais aucun preset prêt à l'emploi ne les fournit. |
| Équipe | Partiel | Owner et trois employés génériques, sans les profils Sophie, Karim et Léa. |
| Prestations | Partiel | Cinq prestations génériques dans une catégorie, contre dix prestations et cinq rubriques métier. |
| Produits et POS | Manquant par défaut | Le salon ne peut pas démontrer la revente de shampoings, huiles et accessoires. |
| Packs, cartes et abonnements | Manquant | Aucun forfait ni consommation de séance n'est provisionné. |
| Clients et historiques | Partiel | Douze clients génériques, sans les cinq histoires métier du scénario. |
| Réservations, file, chaises et waitlist | Partiel fort | Le cœur existe, mais plusieurs réglages diffèrent de la cible. |
| Acompte et frais d'absence | Manquant | Les deux sont désactivés dans les données provisionnées. |
| Notifications, rappels et avis | Manquant | Aucun scénario prêt à démontrer n'est provisionné. |
| Réservation publique et portail client | Manquant dans le dataset | Les fonctions existent, mais aucun accès client prêt à utiliser n'est fourni. |
| Fin de service, facture et paiement | Partiel | Les paiements de démo ne prouvent pas le parcours réservation → service → encaissement. |
| Taxes, pourboires et reçu email/SMS | Manquant dans le dataset | Aucun flux complet et traçable n'est provisionné. |
| Fidélité et VIP | Manquant par défaut | Un palier générique peut être créé si le module est activé. |
| Promotions | Manquant dans le dataset | Activer le module ne crée pas la promotion `RENTREE20`. |
| Campagne WINBACK | Manquant par défaut | Aucun segment 90 jours ni scénario Claire n'est prêt. |
| Réseaux sociaux | Manquant | Le module `social` est absent du catalogue de démo. |
| Assistant et base de connaissances | Manquant par défaut | Le module est sélectionnable, sans contenu salon prêt à utiliser. |
| Dépenses | Partiel | Des dépenses et pièces jointes texte existent, sans scan IA ni scénario de petite caisse avec mouvement et clôture. |
| Comptabilité | Partiel | Disponible uniquement si elle est activée manuellement. |
| Planning, présence et performance | Partiel | Shifts et disponibilités existent, sans historique complet de pointage et congés. |

## Modules du preset actuel

Le preset salon active par défaut :

- `services` ;
- `invoices` ;
- `expenses` ;
- `team_members` ;
- `performance` ;
- `reservations` ;
- `planning` ;
- `presence`.

Les modules `requests`, `quotes`, `plan_scans`, `jobs` et `tasks` doivent rester désactivés : ils ne correspondent pas au parcours normal d'un salon. `products` est également désactivé dans le preset actuel, même si la revente de produits appartient bien au scénario Salon Éclat.

## Amélioration recommandée

Créer un preset distinct `salon_eclat_complete` qui :

1. conserve Devis, Demandes, Scan de plans, Chantiers et Tâches désactivés ;
2. active Produits, Ventes/POS, Promotions, Fidélité, Campagnes et Assistant ;
3. ajoute d'abord Social au catalogue et au provisioner, puis l'active dans ce preset ;
4. provisionne Amina, Sophie, Karim, Léa et les cinq clients du scénario ;
5. crée les dix prestations, cinq rubriques, cinq produits et trois forfaits ;
6. configure acompte, frais d'absence, rappels, avis et accès public/client ;
7. relie une réservation à la file, au service, au paiement, à la facture, aux taxes, au pourboire et au reçu ;
8. ajoute un test fonctionnel couvrant ce parcours complet ;
9. audite le middleware du mode sécurisé pour les mutations de réservation, les réglages réservation, planning/présence, campagnes, social et fidélité. `settings.loyalty.*` est actuellement explicitement bloqué quand ce mode est actif.

## Preuves de validation manquantes

Les tests actuels vérifient principalement les modules sélectionnés et les volumes de données provisionnés. Aucun test ne rejoue encore le scénario Salon Éclat de bout en bout.

La validation finale doit prouver au minimum :

- réservation publique et connexion du client ;
- check-in, pré-appel, appel et démarrage du service ;
- disponibilité de l'employé et des chaises ;
- fin du service puis encaissement Stripe ;
- taxes, pourboire et total de facture cohérents ;
- génération et envoi du reçu par email ou SMS ;
- historique visible côté salon et côté client ;
- fonctionnement identique lorsque le mode sécurisé des workspaces de démo est activé.

## Critère de fermeture

Le statut pourra passer à `terminé` seulement lorsqu'un workspace créé avec ce preset permet d'exécuter le scénario vidéo de bout en bout sans saisie manuelle structurelle et avec les tests de non-régression verts.
