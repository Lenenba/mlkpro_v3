# G08 — Runbook de la session de captures

Dernière mise à jour : 2026-08-11

## Séquence irréversible

Le clic **Encaisser et terminer** crée un paiement et modifie le ticket. Il ne peut être enregistré qu'une fois. Préparer tous les cadrages avant de soumettre.

1. Provisionner un clone jetable Salon Éclat.
2. Avant tout rejeu, ouvrir Factures, rechercher Marie et identifier la preuve canonique par son statut Payée, son total 74,73, son paiement Espèces et son pourboire 11,70 ; relever le numéro `I…` affiché.
3. Créer un second rendez-vous Marie + Coupe femme + brushing + Karim.
4. Le faire progresser normalement jusqu'à En service.
5. Capturer S01, puis l'action S02 et l'état S03.
6. Ouvrir le checkout et capturer S04 à S10 sans soumettre.
7. Vérifier manuellement `65,00 + 9,73 = 74,73` et `74,73 + 11,70 = 86,43`.
8. Noter l'heure, confirmer une seule fois, puis capturer immédiatement S11. Le succès ne montre pas le numéro de facture.
9. Ouvrir Factures, rechercher Marie, identifier la nouvelle ligne par l'heure, le statut et 74,73, puis relever son numéro `I…`.
10. Produire S12 à S15 sur cette même facture.
11. Pour S16, cliquer Télécharger PDF, ouvrir le fichier dans un lecteur propre et recadrer sans chemin local.
12. Produire S17 seulement si l'allocation est clairement liée.
13. Pour S18, ouvrir directement dans Factures le numéro canonique `I…` relevé à l'étape 2 et ajouter le cartouche Lecture seule. Ne pas prétendre venir du ticket `SAL-ECLAT-PAID-001` : aucun lien UI n'existe.

## Règles

- Receipt : Ne pas envoyer maintenant.
- Moyen : Espèces uniquement dans ce master.
- Aucun onglet Stripe, email, SMS ou Super Admin dans les PNG.
- Si le succès échoue, ne pas refaire le clic à l'aveugle : vérifier d'abord si le paiement a malgré tout été créé.
- Les originaux vont dans `desktop/`, les cartouches dans `annotated/`.
- Passer `a_produire` → `capturee` → `validee` après revue, jamais directement.
