# G03 — Variantes, erreurs et décisions

Dernière mise à jour : 2026-08-11

Ce fichier prépare ce que l'animateur doit expliquer lorsque l'écran ou le besoin diffère du parcours Nora Bouchard.

## Arbre de décision

```text
Le client réserve-t-il pour lui-même ?
├── Oui → Particulier
│   └── Date de naissance facultative
└── Non, il représente une organisation → Entreprise
    ├── Nom de l'entreprise obligatoire
    └── Immatriculation et secteur facultatifs

Le client doit-il se connecter maintenant ?
├── Non → Désactiver l'accès portail
└── Oui → Préparer une boîte de test et valider le parcours d'invitation

Une adresse doit-elle être conservée ?
├── Non → Laisser tout le bloc vide
└── Oui → Renseigner au minimum la ville, puis vérifier la fiche après création

Plusieurs créations sont-elles prévues ?
├── Non → Enregistrer client → retour à la liste
└── Oui → Enregistrer et créer un autre → nouveau formulaire
```

## Variante Entreprise complète

Cette variante est montrée sans être enregistrée dans le master principal.

| Champ | Valeur fictive | Pourquoi |
| --- | --- | --- |
| Type | Entreprise | Fait apparaître la branche organisation. |
| Entreprise | Studio Boréal Beauté inc. | Nom absent du jeu local lors de la préparation. |
| Numéro d'enregistrement | DEMO-QC-0001 | Valeur explicitement fictive. |
| Secteur | Soins esthétiques et beauté | Contexte de partenariat avec le salon. |
| Prénom | Camille | Contact principal. |
| Nom | Roy | Contact principal. |
| Courriel | camille.roy@studio-boreal.example.test | Domaine de démonstration. |
| Téléphone | +1 514 555-0156 | Coordonnée fictive. |
| Description | Partenaire local pour maquillages et préparations de mariées; demandes groupées en haute saison. | Explique le besoin métier. |
| Référé par | Partenariat local | Source cohérente. |
| Accès portail | Non | Aucun envoi durant la prise. |

Points à verbaliser :

- le nom de l'entreprise devient obligatoire ;
- la date de naissance disparaît ;
- l'image devient un logo ou une icône d'entreprise ;
- prénom et nom représentent le contact principal ;
- le formulaire ne crée toujours qu'une adresse principale initiale.

## Erreurs utiles à montrer

| Symptôme à l'écran | Cause | Correction utilisateur | Message pédagogique |
| --- | --- | --- | --- |
| Prénom, nom ou courriel signalé | Champ obligatoire vide | Renseigner le champ et soumettre de nouveau | Montrer les erreurs près de leur champ, sans recommencer toute la fiche. |
| Courriel refusé comme déjà utilisé | Adresse présente dans Clients, ou dans Utilisateurs avec portail actif | Rechercher la fiche existante ou utiliser une adresse unique | Un doublon ne se résout pas en changeant seulement la casse. |
| Nom d'entreprise demandé | Type Entreprise sans nom d'organisation | Ajouter le nom ou revenir à Particulier | Le contact principal ne remplace pas l'entreprise. |
| Date de naissance refusée | Date future | Choisir une date passée ou laisser le champ vide | Le champ est optionnel, mais sa valeur doit être plausible. |
| Description refusée | Moins de 5 ou plus de 255 caractères | Écrire une note concise de 5 à 255 caractères | La description n'est pas une note vide décorative. |
| Remise refusée | Nombre négatif ou supérieur à 100 | Saisir une valeur de 0 à 100 | Une remise fidélité permanente doit être contrôlée. |
| Image refusée | Format non accepté ou plus de 2 048 Ko | Utiliser un preset ou un fichier compatible plus léger | Pour la démo, un preset supprime aussi le risque de droit à l'image. |
| La fiche existe, mais l'adresse manque | Rue saisie sans ville | Modifier la fiche et renseigner la ville | C'est une subtilité de persistance, pas une erreur de validation visible. |
| Avertissement après création avec portail | Échec de l'invitation | Vérifier la fiche, puis reprendre l'invitation dans un parcours préparé | L'échec d'envoi ne signifie pas forcément que le client n'a pas été créé. |

## Erreur choisie pour le master

Le master utilise un **courriel déjà présent**, car cette erreur relie directement la recherche de doublon à la validation serveur.

1. Ouvrir la fiche Julie Nadeau du clone et relever son courriel exact ; le preset lui ajoute un suffixe dynamique.
2. Saisir temporairement ce courriel réellement présent dans le formulaire Nora, sans le figer dans le script.
3. Soumettre et cadrer l'erreur sans afficher d'autres données de Julie.
4. Restaurer `nora.bouchard@example.test`.
5. Vérifier que les autres valeurs n'ont pas été perdues avant la soumission finale.

Si la fiche Julie ou son courriel ne sont pas présents dans le clone de tournage, ne pas improviser. Utiliser une adresse existante relevée pendant la préproduction, ou remplacer cette scène par une date de naissance future puis corrigée.

## Différences selon les modules

| Contexte | Ce que l'écran doit montrer |
| --- | --- |
| Salon Éclat | Auto-validation Factures uniquement ; aucune préférence de facturation avancée. |
| Factures sans Jobs/Tâches | Case même adresse de facturation, mais pas la carte de modes/cycles. |
| Factures + Jobs | Titre Propriétés, modes liés aux segments/chantiers et cycles compatibles. |
| Factures + Tâches | Mode par tâche et cycle chaque N tâches disponibles. |
| Aucun module Devis/Jobs/Tâches/Factures | Carte d'auto-validation absente. |

Une capture issue d'un autre contexte doit toujours porter un cartouche indiquant le workspace ou les modules actifs.

## Plans de secours de production

| Incident de tournage | Reprise sûre |
| --- | --- |
| Nora existe déjà | Repartir d'un clone propre ou utiliser une alternative dont le courriel a été contrôlé; garder ensuite cette identité dans le script, les captures et les sous-titres. |
| Autocomplétion d'adresse indisponible | Utiliser immédiatement les champs manuels ; ne pas simuler une suggestion. |
| Invitation partie par erreur | Interrompre la prise, ne pas publier la capture, repartir d'un clone propre avec portail désactivé. |
| Formulaire prérempli par le mode guidé | Quitter le mode guidé ou expliquer explicitement qu'il s'agit d'un autre parcours. |
| Message de succès trop bref | Refaire la création avec une nouvelle adresse email, ou filmer la liste et ajouter une preuve fixe distincte. |
| Workspace Salon Éclat déjà modifié | Provisionner un clone jetable ; ne pas détruire ou réinitialiser un espace partagé sans décision explicite. |

## Anomalies produit à ne pas transformer en instructions

- Le bouton **Annuler** ne possède pas d'action vérifiée dans l'écran actuel.
- Une traduction peut afficher du texte anglais dans la zone de téléversement.
- Le formulaire possède une valeur interne de civilité, mais aucun champ visible ne permet de la choisir.

La vidéo doit rester fidèle à ce que l'utilisateur peut réellement faire. Une anomalie peut être cadrée hors champ ou signalée, mais jamais racontée comme une fonction fiable.
