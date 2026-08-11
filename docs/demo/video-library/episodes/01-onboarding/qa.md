# G01 — Checklist de validation

Dernière mise à jour : 2026-08-11

G01 n'est pas « prêt à publier » parce que les textes existent. La validation exige un compte jetable, des captures réelles, un checkout honnête lorsqu'il est montré et une preuve du challenge 2FA avant le tableau de bord.

## Statuts indépendants

| Lot | État actuel | Condition pour passer à validé |
| --- | --- | --- |
| Règles produit | Audité | Refaire l'audit si l'onboarding, le billing ou la 2FA change. |
| Données Salon Éclat | Prêtes | Vérifier l'unicité du courriel avant la prise. |
| Script master | Prêt | Lecture à voix haute et minutage final. |
| Captures G01-S01 à S21 | À produire | PNG présents, lisibles et revus. |
| Galerie Markdown | En attente | Images réellement intégrées avec légende. |
| Vidéo master | À produire | Export regardé intégralement. |
| Coupe rapide | À produire | Dérivée du master sans fausse étape Stripe. |
| Sous-titres | À produire | Synchronisés et relus. |

## Prévol fonctionnel

- [ ] Le tournage principal utilise un compte jetable neuf, pas le preset Salon Éclat déjà complété des épisodes suivants.
- [ ] `amina.diallo.onboarding@example.test` est absent de la table Utilisateurs.
- [ ] Le rôle owner existe ou peut être créé automatiquement.
- [ ] La langue est Français, le thème clair, le zoom 100 % et le viewport 1920 × 1080.
- [ ] L'autoremplissage personnel, les notifications, les extensions et les onglets privés sont masqués.
- [ ] Le mot de passe est stocké hors dépôt et ne sera jamais affiché en clair.
- [ ] La boîte de test reçoit les notifications et les codes 2FA.
- [ ] Le plan `starter` possède un prix CAD mensuel dans l'environnement de tournage.
- [ ] Stripe est en mode test si G01-S15 doit être produit.
- [ ] La carte de test et le retour checkout ont été rejoués avant l'enregistrement.
- [ ] Aucun `session_id`, cookie, token OAuth, secret TOTP ou URL signée ne pourra apparaître.
- [ ] Un état isolé est prêt pour chaque annexe ; aucun reset destructif d'un workspace partagé n'est prévu.

## QA du compte et des branches d'accès

- [ ] G01-S01 montre sept étapes et seule Compte est accessible.
- [ ] Le compte utilise Amina Diallo et le courriel canonique example.test.
- [ ] Le mot de passe et sa confirmation sont masqués à l'écran et absents de la narration.
- [ ] Après inscription, G01-S03 montre six étapes avec Entreprise en premier.
- [ ] La vidéo ne prétend pas que les fournisseurs sociaux sont disponibles s'ils ne sont pas visibles.
- [ ] G01-S18 est tourné avec un vrai membre non propriétaire et montre PendingOwner.
- [ ] Aucun membre n'est présenté comme capable de finaliser à la place du propriétaire.

## QA de l'entreprise

- [ ] Le nom visible est exactement Salon Éclat.
- [ ] La description correspond au scénario et ne contient aucune donnée réelle.
- [ ] Aucun logo non validé n'est importé.
- [ ] Montréal, Québec et Canada sont visibles dans la saisie manuelle.
- [ ] La narration dit que seuls ville, région et pays sont enregistrés ici.
- [ ] Aucun plan ne prétend conserver une rue ou un code postal depuis ce formulaire.
- [ ] CAD est visible comme devise principale.
- [ ] Le texte anglais de la devise n'est pas corrigé artificiellement dans la capture brute.

## QA type, secteur et configuration

- [ ] Entreprise de services est sélectionné.
- [ ] Salon de coiffure / beauté est le secteur visible.
- [ ] La narration cite seulement les catégories réellement préparées pour Salon.
- [ ] La taille prévue vaut 3.
- [ ] Le passage de l'audience Solo à Team est visible.
- [ ] La ligne Léa utilise uniquement `lea.moreau.onboarding@example.test`.
- [ ] La ligne Léa est supprimée avant la soumission finale.
- [ ] Le payload principal ne contient aucune invitation.
- [ ] Aucun mot de passe temporaire d'équipe n'apparaît au retour.

## QA forfait et checkout

- [ ] Les périodes Mensuel et Annuel sont montrées sans montant figé dans la voix.
- [ ] La devise affichée est CAD.
- [ ] La date d'essai est lue comme une valeur du jour, pas inscrite dans un texte durable.
- [ ] Team Core est réellement disponible et convient à trois personnes lors de la prise.
- [ ] La modale des conditions est ouverte avant l'acceptation.
- [ ] La case des conditions est cochée avant la soumission finale.
- [ ] G01-S19 contient une vraie erreur Solo/Team, jamais un message fabriqué au montage.
- [ ] Si Stripe s'ouvre, G01-S15 mentionne visiblement **mode test**.
- [ ] Aucun numéro de carte, nom de porteur, CVC ou identifiant de session n'est visible.
- [ ] Le retour Stripe provient d'une vraie session de test synchronisée.
- [ ] Si Stripe ne s'ouvre pas, G01-S15 est omis et la narration ne parle pas de carte confirmée.
- [ ] G01-S20 montre qu'une annulation garde l'onboarding incomplet.

## QA sécurité et sortie

- [ ] Code par email est la méthode choisie dans le master.
- [ ] La narration n'affirme pas que le choix App crée un secret TOTP.
- [ ] G01-S21 porte un cartouche indiquant qu'il s'agit d'une préférence à configurer.
- [ ] G01-S16 apparaît après la finalisation et avant G01-S17.
- [ ] Le challenge ne contient aucun code saisi dans l'image fixe.
- [ ] Aucun courriel ou outil de capture de mail n'apparaît dans le master.
- [ ] Le code est saisi hors capture et reste absent des sous-titres.
- [ ] Le tableau de bord est réellement atteint après validation 2FA.
- [ ] Salon Éclat est visible dans G01-S17.

## QA des captures

Pour chaque ligne de la [shot-list G01](shot-list.csv) :

- [ ] Le fichier existe sous `captures/G01/desktop/` avec son nom canonique.
- [ ] L'ID, la route et l'état correspondent au CSV.
- [ ] Le viewport est 1920 × 1080 sans étirement.
- [ ] Le titre, le compteur d'étapes ou la navigation donnent assez de contexte.
- [ ] Le pointeur ne masque ni libellé, ni erreur, ni montant.
- [ ] Aucun secret ou donnée personnelle n'est visible dans l'image ou la barre du navigateur.
- [ ] Les textes restent lisibles à la résolution d'export.
- [ ] G01-S15 est marqué conditionnel si aucun checkout n'est requis dans ce run.
- [ ] G01-S18 à S21 sont identifiés comme annexes et ne sont pas montés dans le flux principal sans transition.
- [ ] Une version annotée reste séparée de l'original.
- [ ] Le statut ne passe à `validee` qu'après une revue humaine.

## QA de cohérence des données

- [ ] Le propriétaire s'appelle Amina Diallo sur tous les plans.
- [ ] Le courriel est toujours `amina.diallo.onboarding@example.test` lorsqu'il est visible.
- [ ] L'entreprise est toujours Salon Éclat.
- [ ] La description reste identique entre saisie et résumé.
- [ ] L'adresse persistée reste Montréal, Québec, Canada.
- [ ] La devise reste CAD.
- [ ] Le type reste Services et le secteur Salon.
- [ ] La taille reste 3 jusqu'à la soumission.
- [ ] Aucune invitation ne reste à G01-S14.
- [ ] La période finale reste Mensuel.
- [ ] Le plan final appartient bien à l'audience Team.
- [ ] La méthode finale reste Email.

## QA narration et accessibilité

- [ ] Débit moyen entre 125 et 145 mots par minute.
- [ ] Les libellés prononcés correspondent aux libellés réellement visibles, avec explication si le texte français contient de l'anglais.
- [ ] Obligatoire, optionnel et conditionnel sont distingués.
- [ ] Les conséquences Services, Salon, taille Team, invitations et 2FA sont expliquées avant la soumission.
- [ ] Les incrustations ne reposent pas uniquement sur la couleur.
- [ ] Les zooms gardent le nom de l'étape ou un contexte de navigation.
- [ ] Les sous-titres ne contiennent ni mot de passe, ni code 2FA, ni session Stripe.
- [ ] La coupe courte renvoie vers le master pour les erreurs et variantes.

## QA après export

- [ ] Regarder le master de bout en bout sans accélération.
- [ ] Vérifier le raccord exact sept étapes → six étapes.
- [ ] Vérifier le raccord exact finalisation → 2FA → tableau de bord.
- [ ] Vérifier qu'aucune frame transitoire ne révèle un secret ou la boîte de mail.
- [ ] Vérifier que Stripe test n'est jamais présenté comme un paiement de production.
- [ ] Tester les liens vers G02, G07, le guide et la galerie.
- [ ] Vérifier que la miniature dit « Configurer son espace » ou « Terminer l'onboarding » sans promettre un paiement instantané.
- [ ] Inscrire l'URL finale dans le catalogue de série.
- [ ] Mettre à jour les statuts des captures seulement après leur publication réelle.

## Critère de sortie

Le lot est livrable lorsque :

1. G01-S01 à S14 et S16 à S17 sont validées ;
2. G01-S15 est validée ou explicitement exclue parce que le checkout n'était pas requis ;
3. les annexes G01-S18 à S21 sont validées ou retirées de la publication avec une raison ;
4. le même compte Amina passe réellement de l'inscription au challenge puis au tableau de bord ;
5. aucune invitation, donnée personnelle ou information d'authentification n'a été exposée ;
6. la QA fonctionnelle, visuelle, confidentialité et sous-titres est verte.
