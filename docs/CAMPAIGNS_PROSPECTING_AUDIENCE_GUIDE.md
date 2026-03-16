# Campaigns Prospecting Audience Guide

Derniere mise a jour: 2026-03-16

## Goal
Expliquer de facon claire comment fonctionne l audience dans le module `campaigns`, avec un focus sur les campagnes de prospection.

Cette doc repond a une confusion frequente:
- en marketing client, l audience ressemble a une cible marketing classique
- en prospection, l audience n est pas simplement "tout ce qui a ete importe"

Dans une campagne de prospection, l audience signifie:

`les prospects actuellement eligibles a un envoi`

Ce point est central pour comprendre pourquoi le volume importe, le volume approuve, le volume estimatif et le volume reellement envoyable peuvent etre differents.

## Public vise
Cette doc est utile pour:
- produit
- support
- operations marketing
- sales ops
- developpement

## Resume executif
Le module gere 2 logiques d audience differentes.

### 1. Campagne marketing client
L audience provient principalement de:
- segment dynamique
- mailing lists
- clients ajoutes manuellement

Le systeme combine ces sources avec une logique `UNION` ou `INTERSECT`, puis applique les controles d eligibilite par canal.

### 2. Campagne de prospection outbound
L audience provient principalement de:
- prospects importes dans la campagne
- batches analyses
- prospects approuves ou en suivi a relancer

Le systeme ne cible pas automatiquement "tout le fichier". Il ne garde que les prospects encore envoyables au moment du calcul.

## 1. Ou se decide le mode de campagne
Dans le wizard de campagne, une campagne passe en logique prospecting quand:
- `prospecting_enabled` est active
- `campaign_direction` n est plus `customer_marketing`

En pratique, la prospection outbound correspond a la direction:
- `prospecting_outbound`

Tant que la campagne reste en `customer_marketing`, l etape Audience fonctionne comme une campagne marketing classique.

Quand la campagne passe en mode prospecting, l etape Audience change completement:
- import manuel ou CSV
- creation de batchs
- analyse
- review
- approbation
- echantillons et details prospect

## 2. Ce que veut dire "audience"
Le mot `audience` peut etre interprete de deux manieres. Il faut bien separer ces concepts.

### Audience source
Ce sont les donnees que l utilisateur met a disposition de la campagne:
- un segment
- une ou plusieurs mailing lists
- des clients ajoutes a la main
- ou, en prospection, un ensemble de prospects importes dans des batchs

### Audience eligible
C est la vraie audience utile pour l envoi.

Elle represente:
- les personnes ou prospects que le systeme accepte encore de contacter
- sur au moins un canal actif
- au moment ou l estimation, la preview ou le run sont calcules

Autrement dit:

`source audience != audience eligible`

## 3. Comment fonctionne l audience en marketing client
En mode marketing client, le moteur part du profil campagne et assemble plusieurs sources.

### Sources prises en charge
- segment dynamique
- filtres du segment
- exclusions du segment
- clients ajoutes manuellement
- mailing lists incluses
- mailing lists exclues

### Logique de combinaison
Le systeme supporte 2 logiques:

#### `UNION`
On additionne les sources:
- clients du segment
- clients des mailing lists incluses
- clients ajoutes manuellement

Puis on retire les exclusions et les doublons.

#### `INTERSECT`
On garde l intersection entre:
- clients issus du segment dynamique
- clients issus des mailing lists incluses

Ensuite, on ajoute quand meme les clients ajoutes manuellement.

Cette logique est utile quand on veut cibler un sous-ensemble tres precis, par exemple:
- clients correspondant a un segment
- et appartenant deja a une liste marketing donnee

### Eligibilite finale en marketing client
Une fois la liste de clients resolue, le systeme verifie pour chaque canal:
- destination disponible
- consentement
- fatigue marketing
- dedupe de destination

Un client peut donc etre:
- eligible sur email
- bloque sur SMS
- ou totalement exclu si aucun canal actif n est envoyable

## 4. Comment fonctionne l audience en prospection
En prospection, la logique est differente.

La campagne n utilise pas l audience comme une simple selection marketing. Elle suit un pipeline operationnel:

`draft -> import -> analyse -> review -> approval -> eligible audience -> outreach -> follow-up -> reply/qualification/conversion`

### Etape 1 - Creation du draft
Avant d importer des prospects, la campagne doit exister en brouillon.

Pourquoi:
- le batch doit etre rattache a une campagne
- les prospects doivent etre scopes au tenant et a cette campagne
- l interface de review a besoin de cet identifiant de campagne

### Etape 2 - Import de prospects
Le wizard permet:
- import manuel
- import CSV

L import cree des batchs de prospects associes a la campagne.

Le fichier importe ne correspond pas encore a l audience finale.

Il faut d abord passer par:
- normalisation
- analyse
- dedupe
- scoring
- review

### Etape 3 - Analyse et review
Les prospects recoivent ensuite des informations de qualification, par exemple:
- score
- resume de qualification
- match potentiel avec customer ou lead existant
- statut de doublon ou blocage

L interface de prospection expose:
- le resume des batchs
- les compteurs
- les details par prospect
- des actions d approbation ou rejet

### Etape 4 - Approbation
En V1, la prospection outbound suit une logique de validation humaine.

Cela veut dire qu un prospect importe n est pas automatiquement envoyable.

Le systeme attend typiquement que le prospect ou le batch arrive dans un etat acceptable avant de le considerer comme audience utilisable.

### Etape 5 - Resolution de l audience eligible
Au moment de l estimation, de la preview ou de l execution, le systeme calcule l audience prospecting comme:
- prospects de la campagne
- encore envoyables
- sur un canal actif
- sans blocage de destination, consentement ou fatigue

Ce calcul est dynamique. Il peut changer entre deux moments, meme sans reimport.

## 5. Quels prospects peuvent entrer dans l audience de prospection
Le moteur prospecting ne part pas de tous les statuts.

Il charge d abord uniquement les prospects dont le statut est:
- `approved`
- `contacted`
- `follow_up_due`

Ensuite, il applique des controles supplementaires pour savoir si le prospect est reellement envoyable maintenant.

### Cas envoyable immediat
Un prospect `approved` est consideres comme envoyable, sous reserve des controles de canal.

### Cas envoyable en sequence
Un prospect `contacted` ou `follow_up_due` peut redevenir envoyable si:
- la sequence de follow-up est active
- le prospect n a pas atteint son nombre max d etapes
- une date `next_follow_up_at` existe
- cette date est echue

Cela permet de gerer des relances automatiques sans reimporter le prospect.

### Cas non envoyable
Un prospect est exclu de l audience s il est dans un statut terminal ou bloque.

Exemples:
- `replied`
- `qualified`
- `converted_to_lead`
- `converted_to_customer`
- `duplicate`
- `blocked`
- `disqualified`
- `do_not_contact`

Un prospect est aussi exclu si:
- la sequence est arretee
- le nombre max d etapes a ete atteint
- la prochaine relance n est pas encore due

## 6. Filtres d eligibilite appliques pendant la resolution
Meme si un prospect semble valide metier, il peut encore sortir de l audience finale pendant la resolution.

Le moteur applique plusieurs filtres.

### Destination manquante
Si aucun canal actif ne dispose d une vraie destination exploitable:
- pas d email exploitable
- pas de telephone exploitable

alors le prospect est bloque avec une raison de type `missing_destination`.

### Do not contact
Si le prospect est marque `do_not_contact`, il ne doit plus entrer dans l audience.

### Consentement
Le moteur reutilise le service de consentement.

Consequence:
- un prospect peut etre bloque si la destination ou le contexte de contact n est pas autorise
- l audience finale peut donc etre inferieure au nombre de prospects approuves

### Fatigue marketing / outreach
Le moteur reutilise aussi le limiteur de fatigue.

Consequence:
- un prospect contactable en theorie peut etre temporairement non envoyable
- selon la pression d envoi, la fenetre horaire ou les regles anti fatigue

### Dedupe de destination
Le systeme evite d envoyer plusieurs fois vers la meme destination normalisee dans le meme calcul d audience.

Consequence:
- si deux prospects partagent la meme destination
- un seul peut rester eligible
- les autres peuvent etre bloques pour `duplicate_destination`

## 7. Les statuts importants a connaitre
Tous les statuts prospect n ont pas le meme role.

### Statuts de preparation
- `new`
- `enriched`
- `scored`

Ces statuts signifient que le prospect existe, mais pas encore qu il est pret a etre envoye.

### Statut pret pour premier outreach
- `approved`

C est le point d entree normal dans l audience prospecting.

### Statuts de sequence
- `contacted`
- `follow_up_due`

Ces statuts servent aux relances.

### Statuts terminaux ou de sortie
- `replied`
- `qualified`
- `converted_to_lead`
- `converted_to_customer`
- `duplicate`
- `blocked`
- `disqualified`
- `do_not_contact`

Un prospect dans ces statuts n est plus considere comme cible active pour un outreach standard.

## 8. Pourquoi un volume importe n egale pas un volume envoyable
Exemple:
- 100 prospects importes
- 82 correctement normalises
- 64 approuves apres review
- 51 avec une destination exploitable sur les canaux actifs
- 46 autorises apres consentement et fatigue
- 43 uniques apres dedupe de destination

Resultat:
- `100` importes
- `43` dans l audience eligible

Ce n est pas une anomalie. C est le comportement attendu.

## 9. Ce que montrent l estimation et la preview
Le module expose plusieurs vues du volume.

### Estimate
L estimation calcule les compteurs a partir de l audience resolue:
- total eligible
- eligible par canal
- bloque par canal
- bloque par raison

### Preview
La preview:
- resolve l audience
- prend un petit echantillon de clients ou prospects eligibles
- rend le contenu avec le moteur de template

Pour les prospects, le contexte de rendu est construit a partir de donnees prospect telles que:
- `firstName`
- `lastName`
- `companyName`
- `city`
- `preferredLanguage`

La preview ne prouve donc pas seulement que le template est beau.
Elle prouve surtout:
- qu il existe une audience eligible
- que les variables prospect sont resolvables
- que le rendu colle au contexte outbound

## 10. Difference entre parametres marketing et audience de campagne
Un point de confusion classique vient du fait qu il existe:
- des parametres marketing globaux
- et une logique d audience propre a chaque campagne

Les parametres globaux donnent surtout un cadre general de comportement.

Mais l audience reellement envoyable est determinee:
- dans le wizard de la campagne
- par les donnees associees a cette campagne
- puis par les services de resolution au moment de l estimation, de la preview et du run

En pratique:
- les parametres ne remplacent pas la review des prospects
- les parametres ne remplacent pas l approbation
- les parametres ne garantissent pas qu un prospect sera envoyable

## 11. Exemples concrets

### Exemple A - Campagne marketing client
Objectif:
Relancer des clients inactifs depuis 90 jours.

Configuration:
- direction `customer_marketing`
- segment `winback_90d`
- mailing list VIP incluse
- logique `UNION`

Resultat:
- l audience est calculee a partir des clients
- pas a partir de prospects
- la resolution applique consentement, fatigue et dedupe

### Exemple B - Campagne de prospection outbound
Objectif:
Contacter de nouvelles entreprises ciblees par email.

Configuration:
- `prospecting_enabled = true`
- direction `prospecting_outbound`
- import CSV de 100 lignes
- analyse
- review
- approbation de 60 prospects

Resultat:
- l audience n est pas `100`
- l audience n est pas non plus automatiquement `60`
- l audience finale est le nombre de prospects encore envoyables au moment du calcul

### Exemple C - Follow-up prospecting
Objectif:
Envoyer une relance a des prospects deja contactes.

Configuration:
- prospect deja en statut `contacted`
- sequence active
- `next_follow_up_at` echu
- pas de reponse recue

Resultat:
- le prospect peut revenir dans l audience
- sans nouvel import
- a condition de rester conforme aux controles de consentement, fatigue et dedupe

## 12. FAQ

### Si j importe 100 prospects, est ce que les 100 sont dans l audience ?
Non.

Ils entrent d abord dans un pipeline de qualification et de review. Seuls les prospects encore eligibles au moment du calcul entrent dans l audience.

### Pourquoi l estimate est plus bas que mon fichier importe ?
Parce que l estimate mesure l audience eligible, pas le volume brut importe.

Le delta peut venir de:
- prospects non approuves
- destination manquante
- do not contact
- consentement refuse
- fatigue
- destination en doublon
- follow-up pas encore du

### Pourquoi un prospect disparait de l audience ?
Parce que son statut ou sa situation a change, par exemple:
- il a repondu
- il a ete qualifie
- il a ete converti
- il est passe en do not contact
- sa prochaine relance n est pas encore due
- il a atteint le max de sequence

### Pourquoi un prospect approuve n apparait pas en envoi ?
Parce que `approved` ne suffit pas toujours si:
- aucun canal actif n est exploitable
- la destination est bloquee
- le consentement est refuse
- le limiteur de fatigue refuse
- un autre prospect utilise deja la meme destination dans le calcul

### Est ce que les segments et mailing lists servent en prospection outbound ?
Pas comme source principale d audience active.

En prospection outbound, la source operationnelle de l audience est le stock de prospects rattaches a la campagne. Les segments et mailing lists restent une logique de ciblage marketing client.

## 13. Recommandations produit et operations
- toujours sauvegarder le draft avant l import prospecting
- ne pas interpreter un import comme une audience finale
- utiliser l etape review pour expliquer les baisses de volume
- distinguer visuellement `imported`, `approved`, `eligible now` et `sent`
- afficher les raisons de blocage principales dans les resumes
- utiliser la preview pour verifier a la fois le contenu et le contexte prospect

## 14. Schema mental recommande
Pour eviter la confusion, il faut presenter le module avec ce schema:

### Marketing client
`segment + mailing lists + manual customers -> audience resolution -> eligible customers -> send`

### Prospection outbound
`import prospects -> analyze -> review -> approve -> audience resolution -> eligible prospects now -> outreach -> follow-up -> reply/qualification/conversion`

## 15. References implementation
Principaux points de verite dans le code:
- `app/Services/Campaigns/AudienceResolver.php`
- `app/Services/Campaigns/CampaignProspectingOutreachService.php`
- `app/Models/Campaign.php`
- `app/Models/CampaignProspect.php`
- `app/Http/Controllers/CampaignRunController.php`
- `resources/js/Pages/Campaigns/Wizard.vue`

## 16. Conclusion
La meilleure facon de lire le mot `audience` dans une campagne de prospection est la suivante:

`audience = prospects actuellement envoyables, pas simplement prospects importes`

Tant que cette distinction est claire, les ecarts entre:
- volume importe
- volume approuve
- volume estime
- volume reellement envoye

deviennent logiques et explicables.
