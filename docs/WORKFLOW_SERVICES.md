# WORKFLOW - ENTREPRISES DE SERVICES

Derniere mise a jour: 2026-01-29

Ce document decrit le workflow principal pour les entreprises de services.

## 1. Onboarding et roles
- Creer le compte utilisateur.
- Completer l onboarding et creer l entreprise.
- Definir le proprietaire et inviter les membres d equipe.
- Roles: Proprietaire, Admin, Membre.

## 2. Acquisition (Requests / leads)
1. Creer une request (REQ_NEW).
2. Renseigner le client ou client externe.
3. Ajouter type de service, urgence, localisation.
4. Convertir la request en devis (REQ_CONVERTED).

## 3. Devis (Quotes)
1. Choisir un client + propriete.
2. Ajouter lignes de services/produits.
3. Ajouter taxes si besoin.
4. Definir un acompte (optionnel).
5. Envoyer au client.
6. Statuts: draft, sent, accepted, declined.
7. Un devis accepte peut creer un job automatiquement.
8. Un devis enfant (parent_id) sert aux extras (change order).

## 4. Jobs (Work)
1. Conversion du devis accepte en job.
2. Statuts principaux:
   - to_schedule, scheduled
   - en_route, in_progress
   - tech_complete
   - pending_review, validated, auto_validated, dispute
   - closed, cancelled, completed
3. Regles:
   - in_progress requiert au moins 3 photos before.
   - tech_complete requiert checklist terminee + 3 photos after.
4. Checklist creee a partir des lignes de devis.
5. Extras via devis enfant lie au job.

## 5. QA et validation
1. Passage a pending_review apres tech_complete.
2. Le client peut valider ou mettre en dispute.
3. Cron auto validation: `php artisan workflow:auto-validate`.
4. Statuts: validated, auto_validated, dispute.

## 6. Facturation et paiements
1. Une facture est generee sur validated / auto_validated.
2. Paiement complet => job closed.
3. Statuts facture: draft, sent, partial, paid, overdue, void.
4. Paiements via portail client ou ajout manuel.

## 7. Portail client
- Voir devis, accepter/refuser.
- Valider job ou mettre en dispute.
- Payer factures.
- Noter devis et jobs.

## 8. Taches
- Statuts: todo, in_progress, done.
- Lier une tache a un client, job ou produit.

## 9. Mini CMS (site public)
- Welcome Builder, Pages, Sections, Assets.
- Traductions FR/EN.

## 10. Abonnement
- `Settings > Billing` (Stripe ou Paddle).
- Webhooks actifs pour paiements et portails.

## 11. Tests rapides (demo)
- Utiliser LaunchSeeder.
- Verifier quotes, jobs, factures et validation client.
