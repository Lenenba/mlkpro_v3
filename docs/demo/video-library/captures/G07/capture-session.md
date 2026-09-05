# G07 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

Ce runbook transforme la [shot-list G07](../../episodes/07-ajouter-membre-equipe/shot-list.csv) en captures réelles, tout en empêchant une invitation externe et l'exposition d'un token.

## 1. Préparer un espace jetable

1. Provisionner un clone `salon_eclat_complete` réservé au tournage.
2. Vérifier le module Membres d'équipe et la limite disponible du plan.
3. Se connecter comme propriétaire.
4. Rechercher Emma par nom et courriel; elle doit être absente.
5. Préparer une adresse `example.test` déjà utilisée pour l'erreur contrôlée.
6. Ne jamais enregistrer de mot de passe ou d'identifiant dans ce dossier.

## 2. Préparer le rôle

1. Ouvrir `/settings/roles-permissions`.
2. Vérifier le rôle Praticienne salon — accès limité dans le bon workspace.
3. Relire toutes ses permissions.
4. Confirmer l'absence de Finance, Réglages, Rôles, gestion d'équipe et vue globale des réservations.
5. Capturer G07-S02 avant de commencer le formulaire.

Si le rôle n'existe pas ou contient un droit inattendu, arrêter la session. Sa création ou sa correction est une opération préparatoire distincte.

## 3. Confiner l'invitation

1. Vérifier la configuration du transport local ou inerte.
2. Vérifier qu'aucun worker de cette session n'utilise un fournisseur externe.
3. Effectuer un contrôle local selon la procédure de l'environnement.
4. Confirmer que le capteur est vide ou que les messages précédents sont clairement séparés.
5. Utiliser exclusivement `emma.laurent@example.test`.
6. Ne cliquer Ajouter qu'après ces contrôles.

Le formulaire n'a pas d'opt-out d'invitation. Un domaine fictif seul n'est pas une garantie suffisante.

## 4. Fixer le cadre

- viewport applicatif : 1920 × 1080 ;
- zoom : 100 % ;
- thème : clair ;
- langue : français ;
- extensions et notifications système masquées ;
- autoremplissage personnel désactivé ;
- aucun gestionnaire de mots de passe ouvert ;
- curseur placé dans une zone neutre.

## 5. Capturer dans l'ordre

| Lot | IDs | État de base |
| --- | --- | --- |
| Avant | G07-S01 à S02 | Emma absente, rôle validé. |
| Formulaire | G07-S03 à S08 | Identité, profil, rôle, fonction, règles et avis. |
| Erreur | G07-S09 | Adresse test existante, aucun membre créé. |
| Correction | G07-S10 | Courriel Emma restauré, rôle intact. |
| Création | G07-S11 à S13 | Flash réel, ligne active, permissions effectives. |
| Sécurité | G07-S14 | Liste du capteur local, sans ouvrir le message. |
| Preuve aval | G07-S15 | Emma dans le sélecteur, aucune soumission. |

Après G07-S09, vérifier qu'Emma est toujours absente. Après G07-S11, ne refaire aucun clic Ajouter si le flash semble lent : chercher d'abord Emma pour éviter un second compte.

## 6. Capturer le capteur local

1. Fermer ou masquer tous les messages non liés à la prise.
2. Cadrer uniquement la ligne Emma.
3. Vérifier que le destinataire est `emma.laurent@example.test`.
4. Ne pas ouvrir le message.
5. Vérifier le PNG immédiatement pour détecter tout token ou autre destinataire.
6. Supprimer de la sélection toute image qui révèle davantage que destinataire, sujet et état local.

## 7. Enregistrer les fichiers

1. Utiliser le nom canonique du CSV.
2. Mettre l'original dans `desktop/`.
3. Vérifier le fichier à sa taille réelle.
4. Passer l'état à `capturee` seulement.
5. Produire une annotation après validation de l'original.
6. Obtenir une seconde revue sur les permissions et la confidentialité.

## 8. Vérifications finales

- Emma existe une seule fois et est active.
- Profil : Membre d'équipe.
- Rôle : Praticienne salon — accès limité.
- Les quatre règles de planning sont exactes.
- Les permissions effectives correspondent au rôle préparé.
- L'invitation se trouve uniquement dans le capteur local.
- Aucun token ou lien de réinitialisation n'apparaît dans les PNG.
- Emma est proposée comme membre actif, mais aucun rendez-vous ni disponibilité n'a été créé.

Réinitialiser ou retirer le clone seulement dans le cadre d'une opération explicitement décidée; ce runbook n'autorise aucune suppression.
