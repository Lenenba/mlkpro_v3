# [ID] — [Titre de l'épisode]

Dernière mise à jour : [AAAA-MM-JJ]<br>
Niveau : [débutant/intermédiaire/avancé]<br>
Public : [rôles]<br>
Durée du master : [durée]<br>
Durée de la capsule dérivée : [durée]

## État réel de production

| Élément | État | Preuve ou blocage |
| --- | --- | --- |
| Règles de l'interface | [à auditer/auditées] | [sources] |
| Exemple de données | [à préparer/prêt] | [lien] |
| Script détaillé | [brouillon/prêt/validé] | [lien] |
| Captures | [à produire/capturées/validées] | [galerie] |
| QA | [en attente/verte] | [checklist] |
| Vidéo | [à tourner/montée/publiée] | [URL] |

Ne jamais résumer ces états par un seul « prêt » : un script peut être prêt alors que les PNG n'existent pas encore.

## Question et résultat promis

**Question :** [une seule question utilisateur]

[Une phrase décrivant l'état vérifiable qui existera à la fin.]

## Objectifs pédagogiques observables

Après l'épisode, la personne doit pouvoir :

1. [verbe d'action observable] ;
2. [verbe d'action observable] ;
3. [vérifier un résultat].

## Situation métier

[Personne fictive], [besoin concret], [raison d'utiliser cette fonction].

| Avant | Après |
| --- | --- |
| [état initial] | [état final] |

## Périmètre et hors-périmètre

**Couvert :** [actions et décisions réellement montrées].

**Non couvert :** [fonctions proches réservées à d'autres épisodes].

## Source de vérité

| Élément | Valeur |
| --- | --- |
| Route de départ | [route] |
| Route finale | [route réelle après soumission] |
| Rôle/permission | [rôle et permission] |
| Workspace/preset | [nom] |
| Modules requis | [liste] |
| Langue et viewport | Français, 1920 × 1080 |
| Fichiers audités | [composant, requête, contrôleur, tests] |

## Préparation reproductible

- [ ] Donnée absente ou baseline contrôlée.
- [ ] Compte et permission vérifiés.
- [ ] Intégrations externes préparées ou explicitement hors périmètre.
- [ ] Mécanisme de remise à zéro connu.
- [ ] Notifications, autoremplissage et données personnelles masqués.

## Données exactes du scénario

| Champ | Valeur | Obligatoire/optionnel/conditionnel | Pourquoi | Conséquence | Capture |
| --- | --- | --- | --- | --- | --- |
| [champ] | [valeur] | [statut] | [raison] | [effet] | [ID-SNN] |

## Carte des champs et subtilités

| Section | Visible quand | Règles | Point à expliquer |
| --- | --- | --- | --- |
| [section] | [condition] | [validation] | [décision] |

Documenter aussi ce qui est présent dans le modèle mais absent de l'interface, et ce qui varie selon le rôle ou les modules.

## Galerie de captures

Une ligne n'est pas une capture. Chaque image validée doit être réellement intégrée :

```markdown
![Description précise de l'état visible](../captures/[ID]/desktop/[NOM-CANONIQUE].png)

**[ID-SNN] — [Légende].** [Point à observer, annotation et données masquées.]
```

| ID | Route | État exact | Fichier | Statut |
| --- | --- | --- | --- | --- |
| [ID-S01] | [route] | [un seul état pédagogique] | [nom] | À produire |

Ne jamais intégrer un lien vers un fichier absent. Tant que l'image n'existe pas, afficher clairement « À produire ».

## Pas-à-pas détaillé

| Étape | Capture | Action exacte | Valeur | Réponse attendue | Narration | Erreur/reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | [ID-S01] | [clic ou saisie] | [valeur] | [preuve UI] | « [texte] » | [solution] |

Une capture représente un seul état pédagogique. Séparer formulaire vide, formulaire rempli, erreur, succès et preuve finale.

## Script continu — master

> [Narration complète, fidèle aux libellés visibles et au débit cible.]

## Script continu — coupe rapide

> [Chemin essentiel dérivé du même master, sans variante ni contradiction.]

## Variantes et arbre de décision

```text
[Question de décision]
├── [choix A] → [effet]
└── [choix B] → [effet]
```

## Erreurs fréquentes et récupération

| Symptôme | Cause | Correction utilisateur | Capture éventuelle |
| --- | --- | --- | --- |
| [erreur] | [cause] | [solution] | [ID] |

Séparer ces erreurs utilisateur du plan de secours de production.

## Plan de secours de production

| Incident de tournage | Reprise sûre |
| --- | --- |
| [donnée déjà créée, service indisponible, etc.] | [alternative vérifiée sans simuler un succès] |

## Preuves finales

- Preuve immédiate : [message, ligne ou statut créé].
- Preuve détail : [fiche ou page récapitulative].
- Preuve aval : [élément disponible dans la prochaine fonction].

## QA

### Fonctionnel

- [ ] La route et la redirection réelles sont visibles.
- [ ] Le résultat existe après rechargement.
- [ ] Les variantes annoncées correspondent aux modules et au rôle.

### Données et confidentialité

- [ ] Toutes les valeurs sont fictives et cohérentes d'un plan à l'autre.
- [ ] Aucun secret, identifiant personnel, paiement réel ou URL signée n'est visible.
- [ ] Aucun envoi externe non préparé n'a été déclenché.

### Visuel et audio

- [ ] Chaque capture existe, porte le nom canonique et a été validée.
- [ ] Les textes importants sont lisibles à la résolution finale.
- [ ] La voix, les incrustations et l'écran ne se contredisent pas.

### Accessibilité et publication

- [ ] Sous-titres synchronisés et relus.
- [ ] Résultat final visible au moins deux secondes.
- [ ] Liens croisés, description, miniature et URL finale prêts.

## Références croisées

- Avant : [ID]
- Après : [ID]
- Démo métier : [ID]
- Sources produit : [liens]
