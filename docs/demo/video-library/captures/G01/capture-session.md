# G01 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

Ce runbook transforme la [shot-list G01](../../episodes/01-onboarding/shot-list.csv) en PNG réels. Il ne contient aucun mot de passe, code 2FA, donnée de carte ou token.

## 1. Préparer le parcours principal

1. Vérifier que `amina.diallo.onboarding@example.test` n'existe pas.
2. Préparer le mot de passe dans un gestionnaire local non versionné.
3. Préparer une boîte de réception de test pour le code 2FA ; ne pas ouvrir cette boîte dans la zone capturée.
4. Vérifier que CAD Mensuel possède un prix Stripe pour `starter` si le checkout doit être montré.
5. Confirmer que Stripe utilise le mode test et connaître la carte de test officielle sans l'écrire dans le dépôt.
6. Ouvrir un contexte de navigateur vierge et déconnecté sur `/onboarding`.
7. Garder séparé le preset `salon_eclat_complete` des épisodes suivants : G01 crée un compte neuf et ne rejoue pas le preset immersif.

## 2. Fixer le cadre

- viewport : 1920 × 1080 ;
- zoom : 100 % ;
- thème : clair ;
- langue : français ;
- barre de favoris, extensions et notifications système masquées ;
- autoremplissage personnel désactivé ;
- gestionnaire de mots de passe hors capture ;
- curseur dans une zone neutre avant chaque PNG ;
- barre d'adresse exclue dès qu'elle peut révéler un identifiant.

## 3. Capturer le compte et l'entreprise

| Lot | IDs | État de base |
| --- | --- | --- |
| Visiteur | G01-S01 à S02 | Déconnecté, compte Amina absent. |
| Retour propriétaire | G01-S03 | Compte créé et session ouverte. |
| Entreprise | G01-S04 à S05 | Salon Éclat, saisie manuelle, CAD. |
| Contexte métier | G01-S06 à S07 | Services et secteur Salon. |

Après G01-S02, ne pas fermer la session : G01-S03 doit prouver que le même compte revient à six étapes.

Pour G01-S05, utiliser la saisie manuelle même si Geoapify fonctionne. Elle garantit que l'écran et les données persistées montrent exactement Montréal, Québec et Canada. Une capture distincte de la recherche d'adresse n'est pas requise dans le master.

## 4. Capturer l'équipe sans créer de membre

1. Saisir `3` dans Taille prévue de l'espace.
2. Capturer G01-S08 après recalcul du contexte Team.
3. Ajouter Léa Moreau avec `lea.moreau.onboarding@example.test` et le rôle Membre.
4. Capturer G01-S09.
5. Cliquer Supprimer.
6. Vérifier visuellement qu'aucune ligne ne reste.
7. Capturer G01-S10 avec la recommandation Team.

Ne jamais terminer le parcours tant que Léa apparaît dans `form.invites`.

## 5. Capturer plan, conditions et sécurité

1. Sur Forfait, capturer G01-S11 avec les commandes Mensuel/Annuel et la date d'essai.
2. Revenir à Mensuel.
3. Sélectionner Team Core uniquement si la carte est réellement disponible.
4. Ouvrir puis fermer les conditions.
5. Cocher l'acceptation et capturer G01-S12.
6. Sur Sécurité, sélectionner Code par email et capturer G01-S13.
7. Revenir à Configuration pour confirmer zéro invitation, puis revenir à Sécurité.
8. Capturer G01-S14 avant le clic final.

## 6. Capturer la sortie réelle

### Si Stripe Checkout s'ouvre

1. Confirmer visuellement le mode test.
2. Exclure les champs de carte et toute donnée de porteur du PNG.
3. Capturer seulement un récapitulatif test ne contenant aucun secret pour G01-S15.
4. Terminer le checkout avec les données officielles de test.
5. Au retour, attendre la navigation vers `/two-factor-challenge`.
6. Ne jamais capturer la barre d'adresse du callback contenant `session_id`.

### Si Stripe Checkout ne s'ouvre pas

1. Ne pas fabriquer G01-S15.
2. Noter dans la fiche de prise que l'environnement a finalisé directement.
3. Passer à G01-S16 et adapter une phrase de narration.

### Challenge et tableau de bord

1. Capturer G01-S16 avec le champ de code vide.
2. Récupérer le code dans la boîte de test hors zone capturée.
3. Arrêter la capture fixe avant la saisie.
4. Valider le code.
5. Capturer G01-S17 lorsque Salon Éclat est visible dans le tableau de bord.

## 7. Produire les annexes sans altérer le master

| Annexe | État à préparer | Précaution |
| --- | --- | --- |
| G01-S18 | Compte membre et propriétaire incomplet | Ne pas utiliser Amina ni l'espace déjà finalisé. |
| G01-S19 | Propriétaire incomplet avec incompatibilité Solo/Team réelle | Préparer la branche avant le clic ; ne pas injecter un faux message au montage. |
| G01-S20 | Checkout Stripe annulé sur un compte jetable séparé | Vérifier que l'onboarding reste incomplet. |
| G01-S21 | Nouveau compte sans secret TOTP | Capturer seulement le choix App et ajouter le cartouche après validation de l'original. |

## 8. Contrôle de chaque PNG

Pour chaque état :

1. enregistrer le PNG dans `desktop/` avec le nom canonique de la shot-list ;
2. passer son statut de `a_produire` à `capturee`, jamais directement à `validee` ;
3. ouvrir le fichier hors navigateur à sa taille réelle ;
4. contrôler données, route, langue, cadrage et confidentialité ;
5. produire une version annotée seulement après validation de l'original ;
6. faire revoir la capture par une deuxième personne.

## 9. Fermer la session

1. Exporter uniquement les PNG sélectionnés.
2. Fermer la session Amina, la boîte de test et Stripe Dashboard.
3. Ne pas versionner cookies, storage state, emails, reçus de test ou identifiants temporaires.
4. Faire valider les images avec [la checklist G01](../../episodes/01-onboarding/qa.md).
5. Conserver ou retirer le compte jetable seulement dans le cadre d'une opération explicitement décidée ; aucune commande destructive ne fait partie de ce runbook.
