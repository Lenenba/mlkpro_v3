# G02 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G02](../02-reperes-interface.md)

## Pas-à-pas

| Étape | Capture | Action exacte | Réponse attendue | Narration principale | Reprise sûre |
| --- | --- | --- | --- | --- | --- |
| 1 | G02-S01 | Ouvrir `/dashboard` comme Amina. Ne pas faire défiler immédiatement. | Tableau de bord, en-tête et barre latérale visibles. | « Après l'onboarding, retenez quatre repères : le tableau de bord, les hubs, la recherche et les actions rapides. » | Si une notification masque l'écran, la fermer avant de reprendre. |
| 2 | G02-S02 | Pointer successivement recherche, messages, notifications, réglages et compte sans ouvrir les menus sensibles. | En-tête lisible. | « L'en-tête garde la recherche au centre et les outils de compte à droite. Les réglages sont visibles ici parce que je suis propriétaire. » | Ne pas ouvrir le menu du compte si des identifiants temporaires sont affichés. |
| 3 | G02-S03 | Parcourir lentement la barre latérale. | Hubs visibles avec infobulles/libellés. | « Le menu ne liste plus tout à plat. Il regroupe les fonctions par usage : revenus, croissance, opérations, finance, catalogue et espace de travail. » | Si un hub manque, relever les modules et permissions avant de continuer. |
| 4 | G02-S04 | Ouvrir le hub Revenus et repérer Clients. | Carte/lien Clients visible. | « Revenus regroupe le début du parcours commercial. Dans Salon Éclat, j'y retrouve les clients. » | Ne pas annoncer Devis ou Demandes s'ils ne sont pas actifs. |
| 5 | G02-S05 | Ouvrir le hub Opérations. | Réservations, présence ou équipe selon accès. | « Opérations rassemble ce qui fait vivre la journée : réservations, présence, planning et équipe lorsque ces modules sont accessibles. » | Lire seulement les entrées visibles sur cette prise. |
| 6 | G02-S06 | Ouvrir le hub Catalogue. | Prestations, catégories, produits et forfaits visibles selon le preset. | « Catalogue contient ce que l'entreprise vend ou planifie. Ici, les prestations et produits du salon restent au même endroit. » | Ne pas confondre Prestation et durée de réservation. |
| 7 | G02-S07 | Cliquer le logo. | Retour `/dashboard`. | « Le logo reste le chemin le plus rapide pour revenir au tableau de bord. » | Valider l'URL avant le PNG. |
| 8 | G02-S08 | Appuyer `Ctrl + K` sous Windows/Linux ou `Cmd + K` sous macOS. | Palette ouverte et actions rapides visibles. | « La recherche globale s'ouvre aussi au clavier. Les actions rapides proposées dépendent encore du rôle et des modules. » | Si le navigateur intercepte le raccourci, utiliser le bouton visible puis conserver la narration générique. |
| 9 | G02-S09 | Saisir `Marie`, attendre la fin du chargement. | Groupe Clients et Marie Lefebvre. | « La recherche démarre à partir de deux caractères. Marie apparaît dans le groupe Clients avec un lien direct vers sa fiche. » | Si Marie n'apparaît pas, confirmer le preset et l'autorisation de recherche. |
| 10 | G02-S10 | Appuyer `Esc`, rouvrir la palette, cliquer l'action Client. | Palette fermée puis formulaire rapide Client ouvert. | « Escape ferme et nettoie la recherche. L'action Client ouvre ensuite une saisie compacte, utile quand les options par défaut suffisent. » | Ne rien saisir de réel. |
| 11 | G02-S11 | Fermer le formulaire rapide. | Retour à l'écran précédent, aucune création. | « Pour comprendre tous les choix du formulaire, nous utiliserons la page complète dans G03. Je ferme donc sans enregistrer. » | Rechercher Nora pour confirmer qu'aucune fiche n'a été créée si une saisie a eu lieu par erreur. |
| 12 | G02-S12 | Montrer une capture préparée du propriétaire et une du membre réception, avec labels. | Différences visibles et attribuées au rôle. | « Deux personnes dans la même entreprise peuvent voir des entrées différentes. Modules et permissions travaillent ensemble ; l'absence d'une icône ne veut pas dire que les données ont disparu. » | Les deux captures doivent porter le nom du rôle, jamais des identifiants de connexion. |

## Script continu — master

> Après l'onboarding, il n'est pas nécessaire de mémoriser toute l'application. Retenez quatre repères : le tableau de bord, les hubs, la recherche globale et les actions rapides.
>
> Le tableau de bord est le point de retour. Il résume les informations disponibles pour votre entreprise et votre rôle. Une carte vide peut simplement signifier qu'aucune activité n'a encore été enregistrée. Le contenu peut aussi varier avec les modules actifs.
>
> Dans l'en-tête, la recherche globale se trouve au centre. À droite, les messages, notifications, réglages et le menu du compte sont affichés selon vos droits. Comme Amina est propriétaire, elle peut ouvrir les réglages de l'entreprise.
>
> La barre latérale regroupe les modules dans six hubs. Revenus contient le début du parcours commercial, notamment Clients dans ce salon. Opérations rassemble les réservations, le planning, la présence et l'équipe lorsqu'ils sont accessibles. Catalogue regroupe les prestations, catégories, produits et forfaits. Croissance, Finance et Espace de travail suivent la même logique.
>
> Je ne visite pas chaque page : les épisodes suivants expliquent une action à la fois. Pour revenir au point de départ, je clique simplement sur le logo et retrouve le tableau de bord.
>
> J'ouvre maintenant la recherche avec Contrôle ou Commande K. Avant même de saisir, elle propose les actions rapides disponibles pour mon contexte. Dans Salon Éclat, le propriétaire peut notamment ouvrir les formulaires rapides Client, Prestation et Produit.
>
> Je saisis Marie. La recherche attend au moins deux caractères, puis regroupe les résultats par type. Marie Lefebvre apparaît dans Clients. Je pourrais ouvrir sa fiche directement, sans parcourir plusieurs menus.
>
> La touche Escape ferme la palette et efface la requête. Je la rouvre puis je choisis l'action Client. Ce formulaire compact convient à une création rapide avec des options simples. Dans G03, nous utiliserons la page complète pour expliquer le portail, les validations et l'adresse ; je ferme donc sans enregistrer.
>
> Enfin, la navigation dépend à la fois des modules de l'entreprise et des permissions de la personne connectée. Un propriétaire, une réceptionniste et un client portail ne voient pas la même chose. Il faut filmer et expliquer le rôle réellement utilisé, sans activer artificiellement un module pour obtenir une icône.

## Script continu — coupe rapide

> Après l'onboarding, retenez trois chemins. Le logo revient au tableau de bord. La barre latérale regroupe les fonctions dans des hubs comme Revenus, Opérations et Catalogue. Enfin, Contrôle ou Commande K ouvre la recherche globale.
>
> Je saisis Marie et retrouve directement sa fiche dans le groupe Clients. Les actions rapides permettent aussi d'ouvrir une création compacte. Les entrées visibles dépendent des modules et de votre rôle : il est normal que deux entreprises ou deux utilisateurs n'aient pas exactement le même menu.

## Notes de montage

- Ajouter des repères numérotés uniquement sur une copie annotée de G02-S01.
- Garder le nom du rôle dans les captures comparatives G02-S12.
- Ne pas accélérer l'apparition des résultats au point de masquer l'état Chargement.
- Ne jamais utiliser une capture propriétaire pour expliquer ce qu'un membre peut faire.
