# Standard de production des démonstrations vidéo

Dernière mise à jour : 2026-08-11

## Règle éditoriale

Une vidéo répond à une seule question et montre un seul résultat. Elle commence par le résultat attendu, exécute le geste, puis confirme visuellement que l'action a réussi. Une question unique n'oblige pas à survoler le formulaire : le master doit expliquer les décisions, conditions et erreurs nécessaires pour réussir ce résultat.

Structure recommandée :

1. **Promesse** — « Dans cette vidéo, nous allons créer un client prêt à être utilisé dans les réservations et la facturation. »
2. **Préparation** — prérequis visibles en moins de 10 secondes.
3. **Action** — aucun détour par des menus non nécessaires.
4. **Preuve** — montrer la ligne, la fiche ou le statut créé.
5. **Prochaine étape** — citer un autre identifiant de la série.

La narration Malikia Pro reste claire, professionnelle, simple, rassurante et orientée résultat. Éviter les promesses vagues comme « révolutionnaire », « magique » ou « tout devient facile ».

## Deux coupes issues d'un même master

Pour une fonction riche, enregistrer une seule prise pédagogique complète, puis en dériver :

- un **master détaillé** de 8 à 15 minutes avec champs, variantes, erreur utile et preuve aval ;
- une **capsule rapide** de 2 à 5 minutes avec le chemin essentiel et un lien vers le master.

La coupe rapide ne possède pas un jeu de données différent et ne contredit jamais le master. Un écran simple peut rester dans une seule vidéo courte si aucune décision importante n'est masquée.

## Formats maîtres

| Usage | Cadre | Résolution | Cadence | Durée |
| --- | --- | --- | --- | --- |
| Master pédagogique | 16:9 | 1920 × 1080 | 30 fps | 8 à 15 min selon la fonction |
| Capsule rapide | 16:9 | 1920 × 1080 | 30 fps | 2 à 5 min |
| Extrait social | 9:16 | 1080 × 1920 | 30 fps | 20 à 45 s |
| Capture mobile | appareil iPhone 14 ou équivalent | 390 × 844 minimum | — | selon le plan |
| Miniature | 16:9 | 1280 × 720 | — | image fixe |

Pour l'interface : zoom navigateur à 100 %, thème clair, barre de favoris masquée, aucune extension visible et aucune notification système. Enregistrer l'écran et la voix sur deux pistes séparées.

## Cadre visuel commun

- Garder le menu latéral visible quand il aide à comprendre l'emplacement de la fonction.
- Recadrer sur le formulaire au moment de la saisie, puis revenir à une vue plus large pour la preuve finale.
- Ne pas accélérer une saisie à plus de 2× ; couper les attentes réseau et les chargements inutiles.
- Utiliser le même style de curseur, la même couleur d'accent et la même transition dans toute la série.
- Limiter les textes incrustés à une idée et deux lignes.
- Afficher l'identifiant de l'épisode dans l'intro ou la description, jamais comme un élément dominant de l'image.

## Convention de fichiers

Format :

```text
EPISODE__SNN-DESCRIPTION__viewport__langue__vNN.extension
```

Exemples :

```text
G01-ONBOARDING__S04-SECTEUR-SALON__desktop__fr__v01.png
G05-RESERVATION__S05-CONFIRMATION__desktop__fr__v01.png
G05-RESERVATION__S06-PARCOURS-PUBLIC__mobile__fr__v01.png
```

Utiliser uniquement des lettres ASCII majuscules, des chiffres et des tirets dans les noms de médias. Le [plan de captures](capture-plan.csv) contient les noms canoniques.

## États des captures

- `a_produire` : cible documentée, aucun fichier validé ;
- `capturee` : fichier brut disponible ;
- `a_reprendre` : défaut de données, cadrage, confidentialité ou interface ;
- `validee` : capture utilisable au montage ;
- `publiee` : visuel présent dans un contenu public.

Le statut est conservé dans `capture-plan.csv`. Une capture remplacée reçoit une nouvelle version ; ne jamais écraser silencieusement une capture déjà utilisée dans une vidéo publiée.

## Une cible n'est pas une image

Un identifiant comme `G03-S05` reste une **capture attendue** tant que son fichier n'existe pas. Pour qu'une image soit présentée comme disponible :

1. le PNG porte le nom canonique ;
2. son statut est `validee` ;
3. elle est intégrée dans la galerie de l'épisode avec un texte alternatif et une légende ;
4. la légende décrit l'état, le point pédagogique et les données masquées.

Une capture ne regroupe qu'un état précis. Séparer notamment : formulaire vide, formulaire rempli, message d'erreur, confirmation dans la liste, fiche détail et preuve dans l'étape suivante.

## Statuts de production séparés

Chaque épisode suit indépendamment :

- audit de l'interface ;
- données de démonstration ;
- script ;
- captures ;
- QA ;
- vidéo ;
- publication.

Ne jamais employer « épisode complet » lorsque seuls le brief ou le script sont prêts. Le catalogue peut utiliser des valeurs comme `outline_ready_captures_pending`, `detailed_script_ready_captures_pending`, `qa_ready` et `published`.

## Données et confidentialité

- Utiliser uniquement les données de [l'espace de démonstration](shared-data.md).
- Ne jamais afficher une adresse, un courriel, un téléphone ou un paiement réel.
- Utiliser le domaine réservé `example.test` pour les adresses de démonstration.
- Masquer les clés, cookies, identifiants techniques, URL signées et références Stripe.
- Ne pas montrer un vrai paiement par carte comme validé sans test Stripe de bout en bout.
- Fermer les boîtes courriel, messageries et onglets personnels avant l'enregistrement.

## Audio et présence du fondateur

- Micro à distance constante, pièce calme et niveau stable.
- Débit cible : 125 à 145 mots par minute.
- Laisser une seconde de silence avant et après chaque prise.
- Garder les hésitations naturelles si elles donnent de la chaleur, mais retirer les répétitions et les attentes.
- Pour `F00`, utiliser un plan caméra simple, regard objectif, arrière-plan propre et lumière douce. La présentation du fondateur reste séparée des tutoriels fonctionnels.

## Réutilisation dans les démos métier

Une démo métier peut :

- montrer un extrait de 3 à 8 secondes d'un épisode général ;
- afficher une carte « Voir G03 — Créer un client » ;
- ajouter le lien dans la description et le chapitre concerné ;
- résumer l'action en une phrase avant de poursuivre son histoire.

Elle ne doit pas recopier plusieurs minutes du tutoriel général. La démonstration métier vend le flux et le résultat ; la bibliothèque explique le geste.

## Assets de marque

Référencer les fichiers existants depuis [le mode d'emploi](README.md#assets-existants-à-référencer-sans-les-dupliquer). Ajouter dans `assets/` uniquement les éléments absents du dépôt : portrait validé, animation de titre, musique avec preuve de licence et éventuels modèles de miniatures.

Les rushs, masters, projets de montage et pistes audio lourdes restent hors Git. Le `.gitignore` local protège ces dossiers.
