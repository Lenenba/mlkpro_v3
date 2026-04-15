# Expenses Module - Nice To Have Backlog

Last updated: 2026-04-14

## Purpose
Cette doc sert de backlog vivant pour les `nice to have` du module `Expenses`.

Elle permet de:
- garder la user story principale concentree sur le scope et le `done`
- stocker les extensions produit non bloquantes dans un seul endroit
- ajouter de nouvelles idees au fur et a mesure sans surcharger la story principale

## How To Use This Doc
- chaque nouvel item doit etre ajoute ici plutot que dans la user story principale
- on garde un format simple: `id`, `title`, `why it matters`, `scope hint`, `status`
- par defaut, un nouvel item commence en `candidate`
- quand un item devient prioritaire, il peut etre promu en mini-story ou en nouvelle phase

## Status Legend
- `candidate`: idee validee mais non planifiee
- `next`: bon candidat pour la prochaine iteration
- `in discovery`: besoin de cadrage avant implementation
- `implemented`: livre et a retirer ensuite si besoin

## Current Backlog

### NTH-EXP-001 - Surface Linked Costs Inside Core Business Modules
- Status: `candidate`
- Why it matters:
  - aujourd hui, les couts lies sont visibles dans `Expenses`, mais pas encore directement dans les fiches metier
  - afficher ces couts dans `Work`, `Sale`, `Customer` et `Campaign` accelererait la lecture de rentabilite
- Scope hint:
  - afficher un bloc `linked expenses`
  - afficher un total cumule et un lien vers la liste filtree
  - eviter de dupliquer toute la logique du module `Expenses`

### NTH-EXP-002 - Portal And Public Finance Approval Gating
- Status: `candidate`
- Why it matters:
  - certaines surfaces portail/public peuvent encore demander un durcissement si l approbation finance doit etre visible plus tot dans le cycle
  - cela permettrait de garder une regle uniforme entre les flux internes et externes
- Scope hint:
  - revoir les points d envoi ou d exposition publique des factures
  - bloquer ou adapter les surfaces qui ne devraient pas avancer tant que `approval_status` n est pas valide

### NTH-EXP-003 - Richer Finance Approval Inbox
- Status: `next`
- Why it matters:
  - l inbox actuelle couvre bien la V1, mais peut monter en gamme
  - une version plus riche aiderait les equipes finance a traiter plus vite et avec moins d ambiguite
- Scope hint:
  - affectation nominative
  - SLA ou ageing des documents en attente
  - vues par role, par montant, par anciennete
  - bulk actions si pertinent

### NTH-EXP-004 - Multi-level Approval Chain
- Status: `in discovery`
- Why it matters:
  - le moteur actuel gere bien `solo`, `team`, les seuils et l escalade simple
  - certaines organisations voudront une vraie chaine a plusieurs niveaux
- Scope hint:
  - plusieurs etapes obligatoires
  - approbation sequentielle
  - passage automatique au niveau suivant
  - historique plus riche des escalades et handoffs

## Additions Over Time
Quand on ajoute un nouvel item, on garde ce format:

### NTH-EXP-XXX - Short Title
- Status: `candidate`
- Why it matters:
  - impact metier
  - probleme resolu
- Scope hint:
  - ecrans, flux, contraintes, garde-fous

