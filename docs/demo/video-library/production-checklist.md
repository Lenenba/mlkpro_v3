# Checklist de tournage et de validation

Dernière mise à jour : 2026-08-11

## La veille

- [ ] Choisir l'épisode et ouvrir sa fiche dans `episodes/`.
- [ ] Vérifier les dépendances dans `series-catalog.csv`.
- [ ] Créer ou réinitialiser volontairement le workspace depuis **Super Admin → Espaces de démo** ; ne jamais lancer un reset destructif comme simple diagnostic.
- [ ] Confirmer que le preset est `salon_eclat_complete` pour les épisodes `G02` à `G08`.
- [ ] Récupérer les identifiants temporaires sur la fiche du workspace, sans les copier dans Git.
- [ ] Vérifier les migrations avec `herd php artisan migrate:status`.
- [ ] Préparer le worker avec `herd php artisan queue:work` lorsque le provisioning, les invitations ou les notifications doivent être montrés.
- [ ] Préparer un rendu stable des assets avec `npm run build`, ou confirmer que Vite tourne correctement.
- [ ] Confirmer l'URL locale : `https://malikia.test`.
- [ ] Rejouer le parcours une fois sans enregistrer.
- [ ] Choisir une date future valide pour les réservations et promotions.
- [ ] Fermer les onglets, notifications et applications personnelles.

Le dépôt exige PHP 8.4. Utiliser les binaires Herd pour Artisan et Composer ; le PHP système peut être incompatible.

## Données

- [ ] Tous les courriels filmés utilisent `example.test` ou une boîte de test dédiée.
- [ ] Aucun client réel n'apparaît dans la recherche, les notifications ou l'historique.
- [ ] Le nom du client correspond entre le script, le formulaire et la preuve finale.
- [ ] La prestation existe avant la réservation.
- [ ] Le membre choisi possède les permissions nécessaires.
- [ ] Les données financières proviennent du scénario seedé ou d'un vrai test Stripe en mode test.
- [ ] Les textes dynamiques ne contiennent pas de date ou de prix déjà obsolète.

## Image et son

- [ ] Capture principale en 1920 × 1080, zoom navigateur 100 %, thème clair.
- [ ] La barre de favoris et les extensions sont masquées.
- [ ] Le curseur ne cache aucun libellé au moment de la capture fixe.
- [ ] La voix et l'écran sont enregistrés sur des pistes séparées.
- [ ] Le micro ne sature pas et le bruit de fond est acceptable.
- [ ] Le premier et le dernier plan gardent une seconde de marge silencieuse.
- [ ] Une capture mobile est produite quand elle figure dans `capture-plan.csv`.

## Pendant la prise

- [ ] Dire la promesse en moins de 12 secondes.
- [ ] Montrer uniquement les menus nécessaires.
- [ ] Laisser le résultat final visible au moins deux secondes.
- [ ] Ne pas lire tous les champs ; expliquer seulement ceux qui changent la décision.
- [ ] Si l'erreur est un cas pédagogique prévu par la shot-list, la montrer, l'expliquer puis la corriger ; sinon, reprendre la séquence depuis son début.
- [ ] Noter immédiatement le numéro de la meilleure prise.

## Contrôle des captures

Pour chaque ligne de `capture-plan.csv` :

- [ ] Le nom du fichier correspond exactement à `canonical_filename`.
- [ ] Le fichier est placé dans `captures/`.
- [ ] Le PNG existe réellement et s'ouvre ; une cible Markdown ou une ligne CSV ne compte pas comme capture.
- [ ] Le cadrage correspond au viewport demandé.
- [ ] Aucun secret, donnée personnelle ou URL signée n'est visible.
- [ ] L'état montré correspond à `state_to_show`.
- [ ] Le statut passe de `a_produire` à `capturee`, puis à `validee` après revue.
- [ ] Toute reprise incrémente la version `vNN`.

## QA éditoriale

- [ ] Le nom du produit est toujours écrit **Malikia Pro**.
- [ ] L'épisode répond à une seule question.
- [ ] La narration décrit ce qui est réellement visible.
- [ ] Aucune fonction partielle n'est présentée comme entièrement validée.
- [ ] Le paiement comptant seedé n'est jamais appelé « paiement Stripe ».
- [ ] La création d'une prestation ne prétend pas saisir une durée si le champ n'existe pas dans le formulaire.
- [ ] G01 montre le challenge 2FA avant le premier tableau de bord et ne présente pas la préférence « App » comme un TOTP déjà configuré.
- [ ] G05 dit qu'une réservation publique crée un prospect, pas automatiquement une fiche Client.
- [ ] G06 prouve la configuration et la réutilisation marketing de la promotion, sans inventer une application financière au checkout Réservations.
- [ ] G07 utilise une boîte locale ou un transport inerte pour l'invitation et distingue le profil opérationnel du rôle RBAC.
- [ ] G08 distingue le rejeu sur un clone jetable de la preuve canonique déjà payée et réconcilie 65,00 + 9,73 + 11,70 = 86,43 CAD.
- [ ] Les sous-titres correspondent à la version audio finale.
- [ ] Le CTA renvoie vers l'épisode suivant ou la démo métier pertinente.

## Avant publication

- [ ] Export principal en 1080p et lecture complète du fichier exporté.
- [ ] Miniature lisible sur mobile.
- [ ] Titre, description, chapitres et liens croisés préparés selon `publishing-plan.md`.
- [ ] Sous-titres FR attachés.
- [ ] URL finale ajoutée dans `series-catalog.csv`.
- [ ] Statut de la fiche de captures mis à jour.
- [ ] La démo Salon Éclat référence les épisodes généraux au lieu de les répéter.
