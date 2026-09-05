# G02 — Variantes, erreurs et récupération

Dernière mise à jour : 2026-08-11

## Arbre de diagnostic d'une entrée absente

```text
Une fonction manque dans le menu
├── Le module est-il actif pour l'entreprise ?
│   ├── Non → absence attendue
│   └── Oui → vérifier le rôle et les permissions
├── Le rôle est-il client portail ou vendeur ?
│   ├── Oui → navigation volontairement différente
│   └── Non → vérifier le hub concerné
└── La route directe est-elle autorisée ?
    ├── Non → demander l'accès à l'administrateur
    └── Oui mais entrée absente → anomalie produit à documenter
```

## Variantes de rôle

| Rôle | Navigation attendue | À ne pas promettre |
| --- | --- | --- |
| Propriétaire Amina | Hubs complets selon modules, réglages, actions rapides Client/Prestation/Produit | Que chaque membre voit les mêmes hubs. |
| Réception/admin | Hubs filtrés par permissions, recherche interne possible, actions rapides variables | Accès automatique aux réglages propriétaire. |
| Membre opérationnel | Modules nécessaires à son travail | Gestion des rôles, finance ou catalogue sans permission. |
| Vendeur | Parcours vente spécifique, aucune liste d'actions rapides globale | Formulaires rapides du propriétaire. |
| Client portail | Navigation portail, recherche globale masquée | Hubs internes ou données d'autres clients. |

## Erreurs et comportements normaux

| Symptôme | Cause possible | Vérification | Récupération |
| --- | --- | --- | --- |
| Rien ne se passe après une lettre | Recherche sous le seuil de 2 caractères | Saisir un deuxième caractère | Continuer sans présenter cela comme un bug. |
| « Aucun résultat » | Aucun élément correspondant ou erreur réseau | Essayer un élément connu puis vérifier la connexion | Ne pas affirmer la cause sur le seul libellé. |
| Action rapide absente | Rôle, type d'entreprise ou module incompatible | Comparer avec le guide | Utiliser la page complète autorisée. |
| Hub absent | Aucun de ses modules n'est visible | Contrôler modules et permissions | Ne pas activer un module pendant la prise. |
| Raccourci clavier intercepté | Navigateur ou système utilise la combinaison | Cliquer le bouton Recherche globale | Garder la narration « bouton ou raccourci ». |
| Menu compact masqué sur mobile | Barre latérale fermée | Utiliser le bouton de navigation | Capturer séparément le viewport mobile. |
| Résultat connu absent | Mauvais workspace ou permission insuffisante | Confirmer Salon Éclat et le rôle propriétaire | Reprendre dans le bon contexte. |

## Plan de secours de production

- Si Marie Lefebvre n'existe pas, provisionner un clone canonique plutôt que créer une fiche dans G02.
- Si des notifications contiennent des accès, les fermer avant la capture ; ne pas les flouter après coup si une reprise propre est possible.
- Si la navigation a changé depuis le script, mettre à jour les captures et le guide avant la narration.
- Si le rôle réception n'est pas prêt, omettre la comparaison G02-S12 et la laisser en annexe `a_produire`.
- Ne jamais emprunter une capture d'un autre type d'entreprise sans cartouche explicite.
