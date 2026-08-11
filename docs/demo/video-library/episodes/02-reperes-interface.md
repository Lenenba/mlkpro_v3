# G02 — Se repérer dans Malikia Pro

Dernière mise à jour : 2026-08-11<br>
Niveau : débutant<br>
Public : tous les utilisateurs internes<br>
Durée du master pédagogique : 7 à 9 minutes<br>
Durée de la capsule dérivée : 2 à 3 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Navigation, recherche et permissions | Auditées | Sidebar, Header, GlobalSearch et workspaceHub |
| Parcours propriétaire Salon Éclat | Prêt | Amina Diallo et recherche de Marie Lefebvre |
| Script détaillé | Prêt | [Scénario de tournage](02-reperes-interface/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G02](02-reperes-interface/shot-list.csv) |
| PNG de l'interface | À produire | [Galerie G02](../captures/G02/README.md) |
| QA finale | En attente des captures | [Checklist G02](02-reperes-interface/qa.md) |

## Question et résultat promis

**Question :** comment retrouver rapidement une fonction ou une information après l'onboarding ?

À la fin, la personne sait revenir au tableau de bord, ouvrir l'un des six hubs visibles, utiliser la recherche globale et distinguer une action rapide d'un module complet.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. identifier le tableau de bord, l'en-tête et la barre latérale ;
2. comprendre pourquoi les entrées changent selon le rôle et les modules ;
3. ouvrir un hub puis revenir au tableau de bord ;
4. ouvrir la recherche avec le bouton ou `Ctrl/Cmd + K` ;
5. rechercher un élément avec au moins deux caractères ;
6. lancer ou fermer une action rapide sans se perdre.

## Situation métier

Amina Diallo vient de terminer l'onboarding de Salon Éclat. Avant de créer Nora, elle veut comprendre où se trouvent Clients, Réservations, Factures et Prestations, puis retrouver la cliente existante Marie Lefebvre.

| Avant | Après |
| --- | --- |
| Amina voit plusieurs icônes et blocs sans connaître leur logique. | Elle utilise hubs et recherche pour atteindre une fonction en quelques gestes. |

## Périmètre

Le master couvre le tableau de bord propriétaire, l'en-tête, les six hubs, la recherche globale, ses raccourcis et les actions rapides réellement visibles dans Salon Éclat.

Il ne visite pas chaque module, ne configure pas les permissions et ne crée aucune donnée. Le formulaire rapide Client est seulement ouvert puis fermé ; la création complète appartient à G03.

## Préparation reproductible

- Utiliser un clone prêt de `salon_eclat_complete` et le compte propriétaire Amina Diallo.
- Vérifier que Marie Lefebvre est présente ; elle sert de résultat de recherche, sans modification.
- Fermer toutes les notifications contenant un accès temporaire.
- Afficher la barre latérale et conserver le viewport 1920 × 1080, zoom 100 %, thème clair, français.
- Ne pas modifier les modules pour obtenir une navigation différente.
- Relever avant la prise les hubs réellement visibles ; un écart doit être expliqué, pas masqué.

## Carte des repères

| Repère | Fonction réelle | État Salon Éclat propriétaire |
| --- | --- | --- |
| Logo | Retour vers `/dashboard` | Visible dans la barre latérale. |
| Tableau de bord | Synthèse conditionnée par les fonctions et permissions | Les cartes sans donnée peuvent rester vides. |
| Hubs | Regroupent les modules visibles par usage | Revenus, Croissance, Opérations, Finance, Catalogue, Espace de travail. |
| Recherche globale | Recherche après 2 caractères, délai 250 ms | Disponible aux utilisateurs internes sauf clients portail. |
| Actions rapides | Ouvrent des formulaires compacts selon rôle/modules | Client, Prestation et Produit pour le propriétaire Salon Éclat. |
| Messages/notifications | Affichent alertes d'usage et notifications | Ne montrer aucun accès ou contenu sensible. |
| Réglages | Ouvre les réglages de l'entreprise | Icône visible pour le propriétaire. |
| Menu du compte | Profil et session | Ne pas ouvrir si une adresse ou un identifiant temporaire apparaît. |

## Parcours de tournage

| Temps | Capture | Action | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:35 | G02-S01 | Présenter le tableau de bord après onboarding. | Contexte Salon Éclat et navigation visibles. |
| 00:35–01:20 | G02-S02 | Distinguer en-tête, recherche, alertes, réglages et compte. | Chaque zone a une fonction claire. |
| 01:20–02:05 | G02-S03 | Montrer les hubs de la barre latérale. | Six regroupements visibles selon le workspace. |
| 02:05–02:45 | G02-S04 | Ouvrir Revenus et repérer Clients. | Module Client trouvé sans inventaire complet. |
| 02:45–03:25 | G02-S05 | Ouvrir Opérations et repérer Réservations/Équipe. | Fonctions métier regroupées. |
| 03:25–04:05 | G02-S06 | Ouvrir Catalogue et repérer Prestations/Produits. | Modules de catalogue visibles. |
| 04:05–04:40 | G02-S07 | Revenir au tableau de bord par le logo. | Retour `/dashboard`. |
| 04:40–05:20 | G02-S08 | Ouvrir Recherche globale avec `Ctrl/Cmd + K`. | Palette et actions rapides visibles. |
| 05:20–06:10 | G02-S09 | Saisir `Marie` et attendre les résultats. | Groupe Clients et Marie Lefebvre visibles. |
| 06:10–06:45 | G02-S10 | Fermer avec `Esc`, rouvrir et lancer Client rapide. | Formulaire compact ouvert. |
| 06:45–07:20 | G02-S11 | Fermer le formulaire sans enregistrer. | Aucune donnée créée. |
| 07:20–08:15 | G02-S12 | Comparer le principe propriétaire/membre. | Variabilité des accès expliquée. |

## Subtilités essentielles

1. Une fonction absente du menu peut être désactivée **ou** interdite par le rôle ; ne pas conclure immédiatement à une panne.
2. La recherche ne lance aucune requête avant deux caractères et attend environ 250 ms après la saisie.
3. `Ctrl/Cmd + K` ouvre la palette ; `Esc` la ferme et vide la requête.
4. Les clients portail ne voient pas cette recherche globale ; les vendeurs n'obtiennent pas les mêmes actions rapides.
5. Une action rapide utilise un formulaire compact. Elle ne remplace pas la page détaillée lorsqu'il faut expliquer toutes les options.
6. Dans la navigation actuelle, certaines entrées peuvent être plus restrictives que les permissions métier ; filmer l'état réel du rôle choisi.

## Version courte dérivée

La capsule conserve G02-S01, S03, S04, S08, S09 et S07 : tableau de bord, hubs, recherche et retour. Elle renvoie vers le master pour les différences de rôle, les raccourcis et les actions rapides.

## Dossier de production

- [Scénario détaillé](02-reperes-interface/scenario-detaille.md)
- [Guide des repères et conditions](02-reperes-interface/guide-reperes.md)
- [Variantes et erreurs](02-reperes-interface/variantes-erreurs.md)
- [Shot-list CSV](02-reperes-interface/shot-list.csv)
- [QA G02](02-reperes-interface/qa.md)
- [Galerie des captures](../captures/G02/README.md)

## Références croisées

- Avant : `G01 — Terminer l'onboarding`
- Après : `G03 — Créer un client` ou `G04 — Créer une prestation`
- Réutilisé par : toutes les démonstrations qui expliquent la navigation une seule fois.
