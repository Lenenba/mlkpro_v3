# G01 — Terminer l'onboarding

Dernière mise à jour : 2026-08-11<br>
Niveau : débutant<br>
Public : propriétaire d'entreprise, responsable du déploiement<br>
Durée du master pédagogique : 14 à 18 minutes<br>
Durée de la capsule dérivée : 5 à 6 minutes

## État réel de production

| Élément | État | Preuve |
| --- | --- | --- |
| Règles de l'interface | Auditées | `Onboarding/Index.vue`, `OnboardingController.php`, authentification, facturation et 2FA |
| Exemple de données | Prêt | Salon Éclat et Amina Diallo, données fictives ci-dessous |
| Script détaillé | Prêt | [Scénario de tournage](01-onboarding/scenario-detaille.md) |
| Guide des étapes | Prêt | [Guide exhaustif](01-onboarding/guide-etapes.md) |
| Plan des captures | Prêt | [Shot-list G01](01-onboarding/shot-list.csv) |
| PNG de l'interface | À produire | [Dossier des captures G01](../captures/G01/README.md) |
| QA finale | En attente des captures | [Checklist G01](01-onboarding/qa.md) |

Le mot **capture** désigne une cible de production tant que le PNG correspondant n'existe pas. Aucun écran n'est présenté ici comme déjà capturé ou validé.

## Question et résultat promis

**Question :** comment créer le compte propriétaire, configurer correctement l'entreprise, choisir un forfait cohérent et atteindre le premier tableau de bord sécurisé ?

À la fin du master :

- Amina Diallo possède le compte propriétaire de **Salon Éclat** ;
- l'entreprise est configurée comme entreprise de services du secteur Salon de coiffure / beauté ;
- la taille prévue de l'équipe, la devise, le forfait et la période sont cohérents ;
- la méthode 2FA par courriel est enregistrée ;
- le checkout Stripe de test est réellement confirmé s'il est requis ;
- la 2FA est réellement franchie avant d'afficher le tableau de bord.

## Objectifs pédagogiques observables

Après la vidéo, la personne doit pouvoir :

1. distinguer le parcours invité en sept étapes du parcours propriétaire en six étapes ;
2. renseigner les champs obligatoires sans confondre adresse affichée et données réellement conservées ;
3. comprendre l'effet du type et du secteur sur l'espace ;
4. choisir entre profil Solo et Team à partir de la taille prévue ;
5. éviter un forfait Solo incompatible avec une équipe ou des invitations ;
6. distinguer période, devise, essai et checkout ;
7. choisir une méthode 2FA en connaissant la limite actuelle du choix « application » ;
8. reconnaître les sorties réelles : checkout, challenge 2FA, tableau de bord ou écran d'attente du propriétaire.

## Branches réelles à annoncer

| Personne qui ouvre `/onboarding` | Écran réel | Ce qu'elle peut faire |
| --- | --- | --- |
| Visiteur non connecté | Sept étapes, dont **Compte** en premier | Créer ou connecter le compte propriétaire ; les six autres étapes restent verrouillées avant authentification. |
| Propriétaire connecté, onboarding incomplet | Six étapes, de **Entreprise** à **Sécurité** | Compléter et soumettre l'onboarding. |
| Membre d'équipe connecté | Écran **Espace en cours de configuration** | Attendre que le propriétaire termine ; il ne peut pas soumettre à sa place. |
| Propriétaire déjà onboardé | Formulaire prérempli | Mettre à jour les données ; la soumission ne relance pas le checkout initial. |

Le master principal suit la première puis la deuxième ligne. La branche membre est une annexe, pas une interruption du parcours Amina.

## Situation métier

Amina Diallo veut préparer Salon Éclat pour une équipe de trois personnes. Elle crée d'abord son compte, renseigne l'identité du salon, choisit le contexte **services + salon**, puis sélectionne un forfait Team. Les invitations sont expliquées, mais aucune n'est conservée dans la soumission principale afin de ne pas afficher de mot de passe temporaire dans la vidéo et de garder `G07` comme épisode d'ajout d'un membre.

| Avant | Après |
| --- | --- |
| Aucun compte Amina de tournage et aucun espace Salon Éclat associé. | Compte propriétaire actif, onboarding terminé, 2FA franchie et tableau de bord accessible. |

## Exemple concret — Salon Éclat

Les mots de passe, codes 2FA et identifiants Stripe ne sont jamais écrits dans Git.

| Étape | Champ ou choix | Valeur de démonstration | Règle ou raison |
| --- | --- | --- | --- |
| Compte | Nom complet | Amina Diallo | Propriétaire narratif de la série. |
| Compte | Courriel | `amina.diallo.onboarding@example.test` | Domaine réservé ; vérifier son unicité juste avant la prise. |
| Compte | Mot de passe | Secret local non versionné | Doit respecter la politique courante et correspondre à la confirmation. |
| Entreprise | Nom | Salon Éclat | Obligatoire, 255 caractères maximum. |
| Entreprise | Logo | Aucun dans le parcours principal | Optionnel ; évite un asset non validé. |
| Entreprise | Description | Salon de coiffure et de beauté spécialisé en coupe, coloration et soins capillaires. | Optionnelle, 2 000 caractères maximum. |
| Entreprise | Ville | Montréal | Saisie manuelle reproductible. |
| Entreprise | Province / Région | Québec | Saisie manuelle reproductible. |
| Entreprise | Pays | Canada | Saisie manuelle reproductible. |
| Entreprise | Devise principale | CAD | Utilisée pour le catalogue, les factures et les frais Stripe en ligne. |
| Type | Profil | Entreprise de services | Déclenche la préparation de catégories de services. |
| Secteur | Activité | Salon de coiffure / beauté | Prépare Coupe, Coloration, Coiffage, Soin capillaire et Barbier. |
| Configuration | Taille prévue | 3 | Fait apparaître les forfaits Team et la section d'invitations. |
| Configuration | Invitations soumises | Aucune | Les membres seront ajoutés dans G07. |
| Forfait | Audience | Team | Cohérent avec trois personnes. |
| Forfait | Plan | Team Core (`starter`) s'il est disponible dans le catalogue de tournage | Capacité courante jusqu'à cinq membres ; toujours relire la carte visible. |
| Forfait | Période | Mensuel | Choix simple pour le master ; ne pas figer un montant dans la voix. |
| Forfait | Conditions | Acceptées après ouverture de la modale | Obligatoires au premier onboarding. |
| Sécurité | Méthode 2FA | Code par courriel | Parcours réellement vérifiable pour un nouveau compte. |

L'adresse d'onboarding ne conserve actuellement que **ville, province/région et pays**. La recherche peut afficher une rue et un code postal dans la carte « Adresse validée », mais ces deux valeurs ne font pas partie de la requête enregistrée. La vidéo ne doit donc pas promettre une adresse postale complète.

## Parcours de tournage en 17 plans principaux

| Temps | Capture | Action et point à expliquer | Preuve attendue |
| --- | --- | --- | --- |
| 00:00–00:45 | G01-S01 | Ouvrir `/onboarding` en visiteur et présenter les sept étapes. | Seule l'étape Compte est accessible. |
| 00:45–01:50 | G01-S02 | Créer Amina par courriel, sans montrer le mot de passe. | Redirection vers l'onboarding authentifié. |
| 01:50–02:20 | G01-S03 | Montrer le retour à six étapes et l'étape Entreprise. | Compte propriétaire connecté. |
| 02:20–03:30 | G01-S04 | Saisir le nom et la description ; expliquer le logo optionnel. | Identité Salon Éclat cohérente. |
| 03:30–04:35 | G01-S05 | Utiliser la saisie manuelle pour Montréal, Québec, Canada et choisir CAD. | Les trois valeurs persistables et la devise sont lisibles. |
| 04:35–05:10 | G01-S06 | Choisir Entreprise de services. | Le choix Services est actif. |
| 05:10–06:00 | G01-S07 | Choisir Salon de coiffure / beauté et expliquer les catégories préparées. | Secteur exact visible. |
| 06:00–07:00 | G01-S08 | Saisir une taille prévue de 3. | Profil Team et recommandation recalculés. |
| 07:00–07:55 | G01-S09 | Ajouter temporairement une ligne membre, expliquer les rôles, puis la retirer. | Aucune invitation ne reste dans le parcours final. |
| 07:55–08:40 | G01-S10 | Montrer la recommandation liée à trois personnes. | Carte Team recommandée et capacité visibles. |
| 08:40–10:00 | G01-S11 | Comparer Mensuel et Annuel, puis revenir à Mensuel. | Période, devise, date de fin d'essai et cartes actuelles. |
| 10:00–11:05 | G01-S12 | Sélectionner Team Core et accepter les conditions après les avoir ouvertes. | Plan sélectionné et case cochée. |
| 11:05–12:10 | G01-S13 | Choisir Code par courriel et expliquer la branche application. | Méthode email active. |
| 12:10–13:00 | G01-S14 | Relire le résumé, retirer toute invitation et cliquer Démarrer l'essai. | Soumission sans secret ni donnée réelle. |
| 13:00–14:00 | G01-S15 | Si requis, terminer un vrai Stripe Checkout en mode test. | Retour de checkout authentique, aucune fausse confirmation. |
| 14:00–15:10 | G01-S16 | Montrer le challenge 2FA après finalisation ; saisir le code hors capture. | Route `/two-factor-challenge`, aucun code visible. |
| 15:10–16:00 | G01-S17 | Afficher le tableau de bord. | Salon Éclat et navigation principale visibles. |

Les variantes membre, erreur Solo/Team, checkout annulé et méthode application occupent G01-S18 à S21. Elles sont décrites dans [Variantes et erreurs](01-onboarding/variantes-erreurs.md).

## Les huit subtilités à ne pas rater

1. **La création du compte et la création de l'espace sont deux soumissions.** Après l'inscription Amina est connectée, puis l'interface revient sur une version à six étapes.
2. **“Continuer” ne valide pas chaque étape.** La validation serveur a lieu à la soumission finale ; une erreur peut concerner un écran précédent.
3. **La recherche d'adresse affiche plus qu'elle n'enregistre.** Seuls ville, province/région et pays sont conservés par ce formulaire.
4. **Le secteur Services prépare des catégories.** Pour Salon, cinq catégories sont créées ; Produits suit une autre branche et active Ventes.
5. **Solo signifie propriétaire uniquement.** Une taille supérieure à 1 ou une invitation rend un forfait Solo invalide.
6. **Les invitations ne sont matérialisées qu'à la finalisation.** Elles créent des comptes d'équipe avec des identifiants temporaires ; ce n'est pas un simple courriel décoratif.
7. **Choisir “Application d'authentification” ne provisionne pas l'application.** Sans secret TOTP déjà configuré, le prochain challenge retombe sur le courriel. Le master utilise donc Courriel.
8. **Le tableau de bord vient après la 2FA.** Une fois l'onboarding marqué terminé, le propriétaire devient soumis au challenge ; le premier accès au dashboard est intercepté tant que le code n'est pas validé.

## Frontière Stripe et environnement local

Lorsque Stripe est configuré et prêt, la première soumission ouvre un checkout externe avec un essai d'un mois. La réussite doit revenir par `/onboarding/billing?status=success&session_id=…`, synchroniser l'abonnement, puis finaliser l'onboarding.

Lorsque le fournisseur n'est pas prêt ou que l'environnement ne requiert pas Stripe, l'application complète directement l'onboarding. Ce comportement est utile en développement, mais ne doit pas être monté comme preuve d'un paiement ou d'une carte validée.

Un checkout annulé renvoie à l'onboarding et laisse `onboarding_completed_at` vide. Les comptes d'équipe en attente ne sont pas créés avant une finalisation réussie.

## Version courte dérivée

La capsule de 5 à 6 minutes conserve G01-S01 à S03, S04–S08 en accéléré, S10–S13, puis S15–S17. Elle omet la ligne d'invitation temporaire et les annexes, mais garde trois explications obligatoires :

- services + salon prépare le contexte métier ;
- trois personnes nécessitent un forfait Team ;
- la 2FA précède le premier tableau de bord.

Le master reste la source pédagogique ; la capsule n'invente pas un deuxième résultat.

## Dossier de production

- [Scénario détaillé et narration](01-onboarding/scenario-detaille.md)
- [Guide exhaustif des étapes et champs](01-onboarding/guide-etapes.md)
- [Variantes, erreurs et décisions](01-onboarding/variantes-erreurs.md)
- [Shot-list CSV](01-onboarding/shot-list.csv)
- [QA fonctionnelle et média](01-onboarding/qa.md)
- [Galerie des captures G01](../captures/G01/README.md)
- [Données communes de la série](../shared-data.md)

## Références croisées

- Avant : `F00 — Présentation du fondateur`
- Après : `G02 — Se repérer dans Malikia Pro`
- Équipe après onboarding : `G07 — Ajouter un membre`
- Réutilisé par : démos Salon Éclat et parcours d'installation généralistes.
