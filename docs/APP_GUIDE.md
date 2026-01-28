# MLK Pro - Guide d utilisation (MVP)

Derniere mise a jour: 2026-01-27

Ce document est vivant. Mettez le a jour a chaque changement fonctionnel.

## 1. Objectif
MLK Pro permet a plusieurs entreprises de gerer leurs clients, devis, jobs, taches et factures avec une base scalable et simple.

## 2. Demarrage rapide (utilisateur final)
1. Creez un compte utilisateur.
2. Lancez l onboarding pour creer l entreprise.
3. Ajoutez des clients et leurs proprietes.
4. Ajoutez des produits ou des services selon votre type d entreprise.
5. Creez un devis, acceptez le, puis convertissez le en job.
6. Suivez le job, validez, puis generez la facture.

## 3. Roles et acces
Chaque entreprise a un compte proprietaire (account owner). Les membres d equipe se connectent avec leur propre compte et sont lies a un proprietaire via Team Members.

Roles internes:
- Proprietaire: acces complet a tous les modules et parametres.
- Admin (team member): peut gerer les jobs et les taches selon ses permissions.
- Membre (team member): acces limite (jobs/taches assignes).

Role client (portail):
- Peut accepter/refuser un devis, valider un job, payer une facture, noter un devis ou un job.
- Acces limite aux actions de workflow; pas de gestion interne (clients, produits, jobs, etc.).

Roles plateforme (admin global):
- Superadmin / Platform admin: gere le contenu public (Welcome, Pages, Sections, Assets) et les parametres plateforme.

Permissions actuelles (team):
- jobs.view, jobs.edit
- tasks.view, tasks.create, tasks.edit, tasks.delete

Notes:
- Les permissions sont appliquees via TeamMember et WorkPolicy/TaskPolicy.
- Le proprietaire voit tout. Les membres voient uniquement leurs jobs assignes.

## 4. Onboarding (creation d entreprise)
Ecran: `/onboarding`

Champs principaux:
- Company name (obligatoire)
- Logo (upload image)
- Description courte
- Pays / Province / Ville
- Type: services ou products
- Est ce que vous etes le proprietaire ?

Si le createur n est pas le proprietaire:
- creer un compte proprietaire (nom + email)
- le createur devient membre admin

Invitations d equipe:
- ajouter des emails et roles (admin ou member)
- le systeme genere des mots de passe temporaires

## 5. Clients et proprietes
Module: Customers

Fonctions:
- creer un client (nom, email, telephone, societe)
- ajouter plusieurs proprietes (adresse, ville, pays)
- definir une propriete par defaut

Bonnes pratiques:
- toujours definir au moins une propriete physique
- utiliser la propriete par defaut pour accelerer la creation des devis

## 6. Produits et services
Module: Products / Services

Regle:
- si l entreprise est de type "services", les items sont des services
- si l entreprise est de type "products", les items sont des produits

Champs typiques:
- nom, description
- prix
- stock (surtout pour produits)
- categorie

## 7. Requests (leads)
Modele: Request (lead)

Statuts:
- REQ_NEW
- REQ_CONVERTED

Champs utiles:
- client lie (customer_id) ou client externe
- type de service, urgence
- coordonnees (pays, ville, lat, lng)

Flux:
1. Creer une request.
2. Convertir en devis (Quote).
3. La request passe a REQ_CONVERTED.

## 8. Quotes (devis)
Module: Quotes

Statuts:
- draft, sent, accepted, declined

Etapes:
1. Choisir un client + propriete.
2. Ajouter des lignes (produits/services).
3. Ajouter taxes si besoin.
4. Definir un acompte (initial_deposit).
5. Envoyer ou accepter.

Notes:
- Les lignes sont snapshottees dans quote_products.
- Un devis accepte peut creer un job automatiquement.
- Un devis enfant (parent_id) sert aux extras (change order).

## 9. Jobs (works)
Module: Jobs (Work)

Statuts principaux:
- to_schedule, scheduled
- en_route, in_progress
- tech_complete
- pending_review, validated, auto_validated, dispute
- closed, cancelled, completed

Regles:
- Demarrer un job (in_progress) requiert au moins 3 photos "before".
- Passer en tech_complete requiert:
  - toutes les checklist items terminees
  - au moins 3 photos "after"
- Un job valide ou auto valide genere une facture.

Checklist:
- creee automatiquement a partir des lignes de devis (quote_products).
- chaque item peut etre marque done/pending.

## 10. Tasks (taches)
Module: Tasks

Statuts:
- todo, in_progress, done

Fonctions:
- creer une tache, assigner un membre
- lier une tache a un client ou un produit
- filtrer par statut et recherche

## 11. Invoices et paiements
Module: Invoices

Statuts:
- draft, sent, partial, paid, overdue, void

Generation:
- une facture est creee a partir d un job valide.
- total = somme des devis acceptes lie au job - acompte deja paye.

Paiements:
- ajouter un paiement met a jour le statut de la facture.
- si paid, le job passe a closed.

## 12. Workflow unifie (end to end)
Ce workflow decrit la logique cible et ce qui est deja active cote back end.

Phase 1 - Acquisition (Request)
- Creer une request (REQ_NEW).
- Convertir en devis (REQ_CONVERTED).

Phase 2 - Quote
- Le devis snapshotte les prix dans quote_products.
- Acceptation: status accepted + creation du job + checklist.
- Acompte: enregistre dans transactions.
- Le client peut accepter ou refuser le devis via le portail.

Phase 3 - Job setup
- Job passe a to_schedule puis scheduled.
- Assignation des membres d equipe.

Phase 4 - Execution
- Photos before requises pour in_progress.
- Checklist et photos after requises pour tech_complete.
- Extras: creer un devis enfant lie au job (parent_id).

Phase 5 - QA
- pending_review, validated, auto_validated, dispute.
- Commande cron: `php artisan workflow:auto-validate`.
- Le client peut valider un job (ou le marquer en dispute).

Phase 6 - Facturation
- Facture generee sur validated / auto_validated.
- Paiement complet => job closed.
- Le client peut payer la facture depuis le portail.

## 13. Mini CMS (Welcome + Pages + Sections + Assets)
Le mini CMS sert a gerer le contenu public de la plateforme (welcome page + pages publiques).
Acces: SuperAdmin (ou Platform admin) avec permission `pages.manage` / `welcome.manage`.

### 13.1 Welcome Builder (page d accueil publique)
Ecran: `SuperAdmin > Welcome Builder`
Objectif: editer la landing page (hero, features, workflow, CTA, footer, etc.).

Utilisation simple:
1. Choisir la langue (FR/EN) en haut.
2. Remplir les textes par section.
3. Ajouter les images (hero/workflow/field) si besoin.
4. Sauvegarder.

Notes:
- Les modifications ne concernent que la langue selectionnee.
- Les listes (highlights, trust items, field items) se font 1 ligne = 1 item.

### 13.2 Pages publiques (Pages)
Ecran: `SuperAdmin > Pages`
Objectif: creer des pages publiques custom (ex: /pricing, /about, /terms).

Etapes rapides:
1. Creer une page (slug + title + active).
2. Choisir la langue (FR/EN).
3. Ajouter des sections (manuelles ou depuis la bibliotheque).
4. Configurer le theme (couleurs, fonts, boutons).
5. Sauvegarder et tester le lien public.

Sections (bloc de contenu):
- Options de layout: split / stack, alignement, densite, ton.
- Image: URL + alt (ou Asset Picker).
- CTA: boutons primaire et secondaire.
- Visibilite par section: auth/guest, device, locales, roles, plans, dates (start/end).

### 13.3 Bibliotheque de sections (Sections)
Ecran: `SuperAdmin > Sections`
Objectif: creer des blocs reutilisables pour gagner du temps.

Usage:
- Creer une section une seule fois.
- Dans une page, selectionner la section via "Source" puis:
  - "Use source" pour lier la section.
  - "Copy" pour dupliquer et personnaliser dans la page.

### 13.4 Assets (mediatheque)
Ecran: `SuperAdmin > Assets`
Objectif: stocker les images, PDFs et videos reutilisables.

Fonctions:
- Upload de fichiers (images, PDF, video).
- Tags + texte alternatif (alt).
- Recherche par nom ou tag.
- Utilisation via le Asset Picker dans Pages/Sections.

### 13.5 Traductions (contenu public)
- FR et EN sont supportes.
- Le selecteur de langue est dans chaque editeur (Welcome/Pages/Sections).
- Pensez a modifier chaque langue separément.

### 13.6 Rendre l usage simple et user friendly (bonnes pratiques)
1. Commencer par la version FR, puis adapter EN.
2. 1 idee par section, titres courts, textes clairs.
3. Utiliser la bibliotheque de sections pour reutiliser les blocs.
4. Ne pas toucher aux regles de visibilite si non necessaire (par defaut: visible partout).
5. Ajouter des tags aux assets pour les retrouver rapidement.
6. Tester le lien public apres chaque modification importante.

## 14. Donnees de demo (LaunchSeeder)
Seeder: `Database\\Seeders\\LaunchSeeder`

Execution:
```
php artisan db:seed --class=Database\\Seeders\\LaunchSeeder
```

Comptes demo:
- owner.services@example.com / password
- admin.services@example.com / password
- member.services@example.com / password
- owner.products@example.com / password
- client.north@example.com / password
- client.products@example.com / password

Ce seeder cree:
- entreprises services + products
- clients + proprietes
- produits/services
- requests + devis + jobs + checklist
- transactions + facture + paiement partiel
- taches assignees

## 15. Commandes utiles (dev)
```
php artisan migrate
php artisan storage:link
php artisan db:seed
php artisan db:seed --class=Database\\Seeders\\LaunchSeeder
php artisan workflow:auto-validate
```

## 16. Maintenance du document
Quand une page, un statut ou une regle change:
- mettre a jour ce guide
- ajuster la section "Workflow unifie" si necessaire
- ajouter les nouvelles commandes ou seeders

## 17. Abonnement plateforme (Paddle / Stripe)
Le compte proprietaire gere l abonnement mensuel dans `Settings > Billing`.

Provider (env):
- BILLING_PROVIDER=paddle|stripe (par defaut: stripe)

Champs env requis (Paddle):
- PADDLE_SANDBOX (true/false)
- PADDLE_CLIENT_SIDE_TOKEN (Paddle.js)
- PADDLE_API_KEY (ou PADDLE_AUTH_CODE)
- PADDLE_WEBHOOK_SECRET (prod)
- PADDLE_PRICE_FREE / PADDLE_PRICE_FREE_AMOUNT
- PADDLE_PRICE_STARTER / PADDLE_PRICE_STARTER_AMOUNT
- PADDLE_PRICE_GROWTH / PADDLE_PRICE_GROWTH_AMOUNT
- PADDLE_PRICE_SCALE / PADDLE_PRICE_SCALE_AMOUNT

Champs env requis (Stripe):
- STRIPE_KEY
- STRIPE_SECRET
- STRIPE_WEBHOOK_SECRET
- STRIPE_ENABLED (true/false)
- STRIPE_PRICE_FREE / STRIPE_PRICE_FREE_AMOUNT
- STRIPE_PRICE_STARTER / STRIPE_PRICE_STARTER_AMOUNT
- STRIPE_PRICE_GROWTH / STRIPE_PRICE_GROWTH_AMOUNT
- STRIPE_PRICE_SCALE / STRIPE_PRICE_SCALE_AMOUNT

Notes:
- BILLING_PROVIDER controle le provider actif (stripe ou paddle).
- Stripe: checkout Stripe + portail client (payment method). Webhook: `/api/stripe/webhook`.
- Paddle: le bouton "Gerer le paiement" redirige vers Paddle (update payment method).

## 18. Scenarios de test (LaunchSeeder)
Seeder: `Database\\Seeders\\LaunchSeeder`

Preparation:
1. `php artisan migrate:fresh`
2. `php artisan db:seed --class=Database\\Seeders\\LaunchSeeder`

Comptes:
- owner.services@example.com / password
- admin.services@example.com / password
- member.services@example.com / password
- owner.products@example.com / password
- client.north@example.com / password (portail client)
- client.products@example.com / password (portail client)

Donnees a tester:
- Leads:
  - Lead - Window cleaning (converti)
  - Lead - Gutter cleaning (nouveau)
  - Lead - Supply order (nouveau)
- Quotes:
  - Window cleaning package (accepted)
  - Seasonal maintenance quote (sent)
  - Draft - Exterior prep (draft)
  - Declined - Fence wash (declined)
  - Extra - Screen repair (change order)
  - Starter supply pack (product, sent)
- Jobs:
  - Window cleaning package (validated)
  - Review - Exterior refresh (pending_review)
  - Scheduled - Seasonal checkup (scheduled)
  - In progress - Driveway wash (in_progress)
  - Dispute - Balcony cleanup (dispute)
  - Cancelled - Patio wash (cancelled)
  - Closed - Full service (closed)
- Invoices:
  - Window cleaning package (partial)
  - Scheduled - Seasonal checkup (sent)
  - Dispute - Balcony cleanup (overdue)
  - Closed - Full service (paid)
- Tasks:
  - Prepare follow up call (todo)
  - Upload before photos (in_progress)
  - Send thank you note (done)
- Ratings:
  - Quote and job rated by client.north@example.com

Tests rapides:
1. Login owner.services@example.com, verifier dashboard + filtres jobs/quotes/invoices.
2. Ouvrir Seasonal maintenance quote et tester accept/decline via portail client.
3. Login client.north@example.com, valider ou mettre en dispute "Review - Exterior refresh".
4. Login owner.products@example.com, verifier quote "Starter supply pack".
