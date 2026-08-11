# G03 — Créer un client

Dernière mise à jour : 2026-08-11<br>
Niveau : débutant<br>
Public : accueil, vente, opérations, propriétaire<br>
Durée du master pédagogique : 10 à 12 minutes<br>
Durée de la capsule dérivée : 3 à 4 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Règles de l'interface | Auditées | `Create.vue`, `CustomerRequest.php` et `CustomerController.php` |
| Exemple de données | Prêt | Nora Bouchard, données fictives ci-dessous |
| Script détaillé | Prêt | [Scénario de tournage](03-creer-client/scenario-detaille.md) |
| Plan des captures | Prêt | [Shot-list G03](03-creer-client/shot-list.csv) |
| PNG de l'interface | À produire | [Dossier des captures G03](../captures/G03/README.md) |
| QA finale | En attente des captures | [Checklist G03](03-creer-client/qa.md) |

Le mot **capture** dans le script désigne une cible tant que le PNG correspondant n'existe pas et n'est pas marqué `validee` dans `capture-plan.csv`. Cette distinction évite de confondre un plan de tournage avec une image réellement disponible.

## Question et résultat promis

**Question :** comment créer une fiche client propre, faire les bons choix et vérifier qu'elle est réutilisable ?

À la fin du master, **Nora Bouchard** existe dans `/customer`, sa fiche contient des coordonnées de démonstration cohérentes et elle apparaît dans le sélecteur d'une nouvelle réservation.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. vérifier qu'un client n'existe pas déjà ;
2. choisir entre **Particulier** et **Entreprise** ;
3. distinguer les champs obligatoires, optionnels et conditionnels ;
4. décider consciemment d'activer ou non l'accès portail ;
5. comprendre pourquoi certaines options varient selon les modules actifs ;
6. corriger une erreur de courriel ou de validation ;
7. enregistrer, retrouver puis réutiliser le client.

## Situation métier

Nora Bouchard appelle Salon Éclat pour une première consultation couleur. La réception veut créer sa fiche avant de prendre le rendez-vous, sans envoyer d'invitation portail pendant la démonstration.

| Avant | Après |
| --- | --- |
| Nora est absente de la recherche Clients. | Nora possède une fiche interne et peut être choisie dans une réservation. |

## Périmètre

Le master couvre la création initiale, les décisions du formulaire, une erreur utile, le succès dans la liste, l'ouverture de la fiche et la preuve dans une réservation.

Il ne couvre pas l'historique complet, la fusion de doublons, la fidélité, les tags, l'import en masse, l'envoi réel d'une invitation ni l'édition approfondie. Ces éléments pourront devenir des capsules séparées.

## Préparation reproductible

- Utiliser un workspace de démonstration **Salon Éclat** ; ne jamais enregistrer dans un compte client réel.
- Se connecter comme propriétaire, administrateur, ou membre possédant `customers.create`.
- Ouvrir `/customer` et rechercher `Nora Bouchard`, puis `nora.bouchard@example.test`.
- Si Nora existe, provisionner un clone propre ou préparer une alternative dont le courriel a été contrôlé juste avant la prise. Le même nom doit rester utilisé partout.
- Garder l'accès portail désactivé pour le parcours principal afin qu'aucune invitation ne parte.
- Vérifier que le module Factures est actif. Dans Salon Éclat, les modules Devis, Jobs et Tâches ne le sont pas : les options conditionnelles doivent donc être expliquées sans être inventées à l'écran.
- Conserver le navigateur en français, thème clair, zoom 100 %, viewport 1920 × 1080.

## Exemple concret — Nora Bouchard

| Champ visible | Valeur saisie | Statut | Pourquoi ce choix |
| --- | --- | --- | --- |
| Type de client | Particulier | Choix requis | Nora réserve pour elle-même. |
| Photo de profil | Icône prédéfinie | Optionnel | Évite d'utiliser une vraie photo et illustre l'identification visuelle. |
| Prénom | Nora | Obligatoire | Identification principale. |
| Nom | Bouchard | Obligatoire | Identification et recherche. |
| Date de naissance | 1993-04-18 | Optionnel | Montre le format et la règle « jamais dans le futur ». |
| Téléphone | +1 514 555-0147 | Optionnel | Coordonnée fictive pour le scénario d'accueil. |
| Adresse email | nora.bouchard@example.test | Obligatoire et unique | Domaine réservé à la démonstration ; aucun destinataire réel. |
| Donner accès à la plateforme | Non | Décision explicite | Empêche la création d'un accès portail et l'envoi d'une invitation pendant la prise. |
| Validation auto des factures | Non | Conditionnel | Garde une validation humaine pour une nouvelle cliente. |
| Description | Nouvelle cliente intéressée par une consultation couleur; préfère les rendez-vous en fin de journée. | Optionnel, 5 à 255 caractères | Donne un contexte opérationnel sans donnée sensible. |
| Référé par | Instagram | Optionnel | Montre comment conserver la provenance. |
| Remise fidélité | 0 | Optionnel, 0 à 100 | Une remise permanente n'est pas une promotion ponctuelle. |
| Rue 1 | 245 rue Démonstration | Optionnel | Adresse fictive. |
| Ville | Montréal | Déclenche la création de l'adresse | Sans ville, la propriété/adresse n'est pas créée côté serveur. |
| État / province | Québec | Optionnel | Complète l'adresse de démonstration. |
| Code postal | H2X 3K4 | Optionnel, 10 caractères max. | Format canadien plausible pour la démonstration. |
| Pays | Canada | Optionnel | Cohérent avec le workspace. |
| Adresse de facturation identique | Oui | Conditionnel | Évite une deuxième adresse inutile lorsque l'option est visible. |

La civilité « Mme » reste dans les données narratives communes, mais le formulaire de création actuel ne l'affiche pas : elle ne doit donc pas être annoncée comme un champ saisi.

## Parcours de tournage en 12 plans

| Temps | Capture | Action et point à expliquer | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:35 | G03-S01 | Rechercher Nora par nom puis courriel. | Aucun doublon. |
| 00:35–01:05 | G03-S02 | Ouvrir « Ajouter client » et présenter la carte du formulaire. | Route `/customer/create`. |
| 01:05–02:00 | G03-S03 à S04 | Choisir Particulier, une icône, puis remplir identité et contact. | Champs obligatoires identifiables. |
| 02:00–02:45 | G03-S05 | Désactiver l'accès portail et expliquer la conséquence. | Aucun accès/invitation dans ce scénario. |
| 02:45–03:35 | G03-S06 | Montrer l'auto-validation réellement disponible. | Seulement les options permises par les modules. |
| 03:35–04:25 | G03-S07 | Ajouter description, provenance et remise à 0. | Contexte utile sans remise involontaire. |
| 04:25–05:35 | G03-S08 à S09 | Rechercher ou saisir l'adresse ; insister sur la ville. | Adresse complète prête à persister. |
| 05:35–06:35 | G03-S10 | Provoquer une erreur avec un courriel déjà utilisé, puis remettre celui de Nora. | Message de validation puis correction. |
| 06:35–07:20 | G03-S11 | Comparer les deux boutons d'enregistrement. | Choix de sortie compris. |
| 07:20–08:05 | G03-S12 | Enregistrer et montrer le message de succès dans la liste. | Nora visible dans `/customer`. |
| 08:05–08:50 | G03-S13 | Ouvrir la ligne de Nora. | Fiche détaillée cohérente. |
| 08:50–09:35 | G03-S14 | Ouvrir une nouvelle réservation et rechercher Nora. | Preuve fonctionnelle en aval. |
| 09:35–11:30 | G03-S15 à S16 | Expliquer brièvement la variante Entreprise et les préférences de facturation conditionnelles. | Différences documentées sans altérer Nora. |

Le minutage détaillé, la narration et les reprises figurent dans [le scénario complet](03-creer-client/scenario-detaille.md).

## Les cinq subtilités à ne pas rater

1. **L'accès portail est activé par défaut.** Le laisser actif peut créer un utilisateur portail et déclencher une invitation. Il faut donc prendre une décision avant l'enregistrement.
2. **Le courriel doit être unique globalement dans Clients.** Avec le portail actif, il doit aussi ne pas appartenir à un utilisateur existant. Une reprise exige donc un clone remis à zéro ou une nouvelle adresse vérifiée.
3. **La ville rend l'adresse persistante.** Une rue seule peut donner l'impression que l'adresse est remplie, alors qu'aucune propriété n'est créée côté serveur sans ville.
4. **Les auto-validations suivent les modules actifs.** Salon Éclat permet d'afficher l'option Factures, mais pas d'inventer Devis, Jobs ou Tâches.
5. **Après “Enregistrer client”, l'application revient à la liste.** Il faut montrer le succès dans `/customer`, puis ouvrir la fiche ; le navigateur ne va pas directement au détail.

## Version courte dérivée

La capsule de 3 à 4 minutes conserve seulement G03-S01, S03–S05, S09, S12–S14. Elle explique le geste essentiel et renvoie vers le master pour les variantes, erreurs et règles conditionnelles. Le master reste la source pédagogique ; la capsule n'est pas un second tournage contradictoire.

## Dossier de production

- [Scénario détaillé et narration](03-creer-client/scenario-detaille.md)
- [Guide exhaustif des champs](03-creer-client/guide-champs.md)
- [Variantes, erreurs et décisions](03-creer-client/variantes-erreurs.md)
- [Shot-list CSV](03-creer-client/shot-list.csv)
- [QA fonctionnelle et média](03-creer-client/qa.md)
- [Galerie des captures G03](../captures/G03/README.md)
- [Données communes de la série](../shared-data.md)

## Références croisées

- Avant : `G02 — Se repérer dans Malikia Pro`
- Après : `G05 — Créer une réservation`
- Approfondissement : [script long du module Client](../../module-client-demo-20min.md)
- Réutilisé par : démos Salon Éclat, services terrain, commerce et CRM.
