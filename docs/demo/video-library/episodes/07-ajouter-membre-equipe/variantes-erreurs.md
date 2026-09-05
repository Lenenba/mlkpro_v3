# G07 — Variantes, erreurs et décisions

Dernière mise à jour : 2026-08-11

## Arbre de décision d'accès

| Question | Décision |
| --- | --- |
| La personne gère-t-elle toute l'entreprise ? | Réserver Administrateur à un besoin démontré; ne pas l'utiliser par défaut. |
| Travaille-t-elle comme collaboratrice opérationnelle ? | Choisir Membre d'équipe. |
| Le rôle d'accès exact existe-t-il ? | Le relire et le sélectionner. |
| Le rôle manque-t-il ? | Le préparer dans Rôles et permissions avant G07. |
| L'acteur ne possède pas `assign_roles` ? | Utiliser un propriétaire ou déléguer correctement; ne pas contourner par un profil plus large. |
| Un accès supplémentaire est demandé plus tard ? | Modifier le rôle de référence ou attribuer un rôle adapté après revue, pas cocher au hasard. |

## Courriel déjà utilisé

Le courriel est unique dans toute la table des utilisateurs. Il peut donc appartenir à :

- un autre membre du même compte ;
- un compte d'un autre contexte ;
- un client portail ;
- un compte créé lors d'une ancienne prise.

Pour l'erreur contrôlée, utiliser une adresse `example.test` préparée. Après le message, restaurer `emma.laurent@example.test` et revérifier le rôle.

## Limite du plan atteinte

Le contrôleur vérifie la limite `team_members` avant validation métier complète. Si elle est atteinte :

1. ne pas désactiver un membre réel pour faire de la place ;
2. utiliser un clone ou un plan de démonstration approprié ;
3. refaire la prise après préparation.

## Rôle invalide

Le rôle doit être actif et être soit système, soit appartenir au compte. Un identifiant d'un autre tenant ou un rôle désactivé est rejeté. Ne jamais copier un identifiant de rôle entre workspaces.

## Acteur sans permission d'attribution

Le sélecteur Rôle d'accès est masqué sans `assign_roles`. Une requête qui tente malgré tout de changer le rôle est interdite. L'accès à la page de réglages des rôles nécessite encore une permission distincte : `manage_roles_permissions`.

## Profil Administrateur avec rôle limité

Cette combinaison est trompeuse : le rôle fin paraît minimal, mais le profil opérationnel Administrateur possède des raccourcis dans certaines politiques. Pour Emma, garder Membre d'équipe. La QA doit contrôler le profil et le rôle, pas seulement les badges de permissions.

## Règles de planning invalides

| Erreur | Limite |
| --- | --- |
| Pause négative ou supérieure à 240 | 0 à 240 minutes |
| Minimum ou maximum journalier hors plage | 0 à 24 heures |
| Maximum hebdomadaire hors plage | 0 à 168 heures |
| Maximum journalier inférieur au minimum | Refus métier sur Max heures/jour |

Ces valeurs ne vérifient pas automatiquement la cohérence entre 32 heures par semaine et le nombre de jours réellement planifiés. Elles restent des règles, pas un contrat de disponibilité.

## Photo invalide

Le téléversement accepte JPG, JPEG et PNG jusqu'à 2 Mo. Pour la série : préférer une icône prédéfinie. Cela évite les droits à l'image, les métadonnées et les fichiers personnels.

## Dispatch d'invitation en échec

Le compte et le membre sont créés avant la tentative d'invitation. Si le transport échoue :

- l'application affiche un avertissement ;
- Emma reste présente dans la liste ;
- il ne faut pas dire que la création a échoué ;
- il ne faut pas non plus dire que l'invitation a été livrée.

Le scénario principal préfère un dispatch capté localement. L'avertissement est un plan de secours honnête, pas un résultat à dissimuler.

## Invitation captée localement

La capture autorisée montre seulement :

- le destinataire `emma.laurent@example.test` ;
- le sujet ;
- l'heure ou le statut local.

Elle ne montre jamais :

- le corps ;
- le token ;
- l'URL de réinitialisation ;
- les en-têtes complets ;
- des identifiants de fournisseur.

## Membre actif sans disponibilité

La création fixe `is_active = true`, ce qui permet à Emma d'apparaître dans les listes de membres. Les créneaux de réservation exigent en plus une disponibilité hebdomadaire couvrant la plage. G07 montre le sélecteur puis ferme la modale sans enregistrer.

## Désactivation

L'action Désactiver détache le membre des jobs puis fixe son statut inactif. Elle ne supprime ni l'utilisateur ni l'historique. Cette action est hors périmètre et ne doit pas être utilisée pour nettoyer le clone sans décision explicite.
