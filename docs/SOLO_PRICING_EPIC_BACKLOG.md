# Solo Pricing - Epic Backlog

## Objectif
Decouper le chantier `solo_pricing` en petits epics terminables, testables, et assez courts pour garder un bon rythme d execution.

## Regles de decoupage
- un epic doit produire un resultat visible ou verifiable
- un epic ne doit pas melanger plusieurs gros sujets techniques
- un epic doit pouvoir etre ferme sans attendre tout le programme solo
- chaque epic doit avoir une definition de done simple

## Statut global
- `done`: termine et verifie
- `next`: prochain bon lot
- `later`: a faire ensuite
- `blocked`: depend d une decision produit ou technique

---

## EPIC 01 - Cataloguer les 3 plans solo
Statut: `done`

But:
- ajouter `solo_essential`, `solo_pro`, `solo_growth` au billing applicatif

Livrables:
- codes de plans en config
- metadata `audience`, `owner_only`, `recommended`
- limites et modules par defaut
- seed de base coherent

Definition of done:
- les 3 plans existent dans le catalogue billing
- les prix par devise sont prevus
- les seeders connaissent les plans solo

---

## EPIC 02 - Rendre l onboarding compatible solo
Statut: `done`

But:
- orienter un owner seul vers les plans solo

Livrables:
- logique de recommandation solo vs team
- selection de plan solo dans l onboarding
- blocage si un profil equipe essaie de prendre un plan solo

Definition of done:
- un profil `1 owner` voit les plans solo
- un profil avec equipe ne peut pas choisir un plan solo
- `solo_pro` est le plan recommande

---

## EPIC 03 - Nettoyer le billing settings pour les plans solo
Statut: `done`

But:
- enlever le discours `team_members` de la souscription solo

Livrables:
- cartes billing compatibles owner-only
- quantite de checkout forcee a `1`
- pas de `team_members_limit` visible pour les plans solo

Definition of done:
- les plans solo ne parlent plus d employes inclus
- la quantite facturee est `1`
- la selection de plan solo est coherente cote settings

---

## EPIC 04 - Bloquer `team` et `presence` pour la gamme solo
Statut: `done`

But:
- rendre les surfaces collaboratives inaccessibles sur les plans solo

Livrables:
- modules `team_members` et `presence` forces a `off`
- menus caches
- routes bloquees
- settings company nettoye pour `presence`

Definition of done:
- aucun acces `team` ou `presence` en solo
- aucune section presence visible en settings solo
- tests owner-only verts

---

## EPIC 05 - Finir le nettoyage du wording solo
Statut: `done`

But:
- supprimer les derniers libelles ou indicateurs qui parlent encore equipe dans les surfaces solo

Livrables:
- dashboard
- onboarding restant si besoin
- billing restant si besoin
- global search
- dashboard pourboires owner
- labels `employees`, `team members`, `all team members`

Definition of done:
- aucun ecran solo ne parle de `team_members`
- aucun wording `included employees` n apparait en solo
- audit texte et UI fait sur les ecrans cibles

Note:
- `planning` et `reservations` restent traites dans leurs epics dedies et ne sont pas fermes par cet epic de wording

---

## EPIC 06 - Simplifier `jobs` en mode owner-only
Statut: `done`

But:
- rendre les jobs propres pour `solo_pro` sans logique equipe inutile

Livrables:
- retrait des selecteurs d assignation membre
- fallback `owner-only` ou `non assigne`
- create / edit / show verifies

Definition of done:
- un compte solo peut creer et editer un job sans `team_member`
- aucun selecteur equipe n apparait
- tests create/edit passent

Note:
- create/edit ignorent maintenant toute assignation `team_members` sur les plans owner-only
- le scheduling ne retombe plus sur des membres historiques quand la feature `team_members` est inactive

---

## EPIC 07 - Simplifier `tasks` en mode owner-only
Statut: `done`

But:
- rendre les taches propres pour `solo_pro`

Livrables:
- retrait des assignees equipe
- retrait des filtres equipe inutiles
- index + create + edit compatibles solo

Definition of done:
- un compte solo peut gerer ses taches sans membre d equipe
- aucune colonne ou filtre equipe parasite ne reste
- tests taches owner-only passes

Note:
- les payloads `tasks` n exposent plus d assignee ni de vue equipe quand `team_members` est inactive
- create, edit et assign ignorent maintenant toute tentative d assignation `team_member`

---

## EPIC 08 - Definir le MVP `planning` pour `solo_growth`
Statut: `done`

But:
- fixer la version minimale exploitable du planning sans equipe

Livrables:
- decision produit: vue owner-only
- retrait des filtres `all members`
- suppression du selecteur `team_member_id`

Definition of done:
- le planning solo affiche une vue owner-only sans membres
- aucun filtre ou selecteur `team_member` n apparait
- les mutations de shifts sont bloquees tant qu un vrai fallback owner n existe pas

Note:
- le MVP retenu est `view-only`
- en `solo_growth`, le planning montre les jobs et taches sans notion d equipe
- les absences, conges et shifts equipe historiques ne sont plus exposes
- la creation, modification, suppression et approbation de shifts restent bloquees jusqu a un futur epic dedie

---

## EPIC 09 - Definir le fallback `reservations` pour `solo_growth`
Statut: `done`

But:
- rendre `reservations` exploitable en solo sans promettre un booking equipe qui n existe pas encore

Livrables:
- mode limite owner-only documente et implemente
- back-office `reservations` en consultation + waitlist + settings globaux
- booking manuel staff bloque
- client booking et reschedule par creneau bloques
- queue / kiosk desactives
- settings reservations nettoyes des sections equipe

Definition of done:
- une strategie unique `mode limite` est retenue
- aucune surface solo n expose un faux booking par membre
- les routes critiques sont bloquees ou degradent proprement
- tests owner-only reservations verts

Note:
- la vraie logique `owner resource` reste un sujet `later`
- en phase 1, `solo_growth` garde `reservations` surtout pour la consultation, la liste d attente et les reglages globaux

---

## EPIC 10 - Exposer le packaging solo cote marketing
Statut: `done`

But:
- rendre la gamme solo visible cote acquisition

Livrables:
- cartes marketing finales
- wording FR/EN
- decision UX retenue:
  - page pricing unique
  - toggle `Solo / Equipe`

Definition of done:
- le contenu marketing final est fige
- l experience publique solo est lisible
- `solo_pro` est clairement mis en avant

Note:
- la page pricing publique expose maintenant deux catalogues `solo` et `team`
- `solo_pro` est le plan mis en avant sur la vue solo
- le comparatif solo n utilise plus un vocabulaire `employes inclus`

---

## EPIC 11 - Ajouter le support de provisionnement Stripe
Statut: `done`

But:
- permettre a la commande Stripe de provisionner les 3 plans solo

Livrables:
- support `--solo` ou `--plans=solo_essential,solo_pro,solo_growth`
- mapping des variables env
- documentation de provisioning

Definition of done:
- la commande gere les 3 plans solo
- les price IDs Stripe peuvent etre crees ou reutilises
- la doc d usage est a jour

Note:
- la commande `billing:stripe-plan-prices` supporte maintenant `--solo`
- l alias `--plans=solo` est aussi pris en charge en plus de la liste explicite des 3 plans
- le coverage de base existe cote tests commande et service

---

## Ordre recommande
1. `EPIC 05 - Finir le nettoyage du wording solo`
2. `EPIC 06 - Simplifier jobs en mode owner-only`
3. `EPIC 07 - Simplifier tasks en mode owner-only`
4. `EPIC 08 - Definir le MVP planning pour solo_growth`
5. `EPIC 09 - Definir le fallback reservations pour solo_growth`
6. `EPIC 10 - Exposer le packaging solo cote marketing`
7. `EPIC 11 - Ajouter le support de provisionnement Stripe`

## Cloture pratique de la phase 1
On pourra considerer la phase 1 vraiment propre quand:
- `EPIC 05`, `EPIC 06` et `EPIC 07` seront fermes
- `EPIC 08` sera ferme avec un MVP planning stable
- la decision `reservations` sera prise

Apres ca, le reste devient un rollout marketing et technique de phase suivante, pas un flottement de cadrage.
