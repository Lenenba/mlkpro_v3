# Démo vidéo — Salon de coiffure / beauté

Dernière mise à jour : 2026-08-11
Public visé : propriétaires de salons de coiffure, barbershops, instituts de beauté.
Double usage de ce document :

1. **Runbook de test** — parcourir et valider chaque fonction de la plateforme dans un contexte salon.
2. **Script de tournage** — narration mot à mot, textes à l'écran, transitions, découpage.

> Convention de lecture
> `🎬` = ce que tu filmes · `🎙️` = ce que tu dis · `📝` = texte à incruster à l'écran · `⏱️` = durée cible · `✅` = point de test à valider

> **Statut de validation : en cours.** Le preset `salon_eclat_complete` fournit maintenant le socle immersif de Salon Éclat : identité, équipe, catalogue, POS, fidélité, marketing, réservation publique et une preuve d'encaissement local complète. Le parcours Stripe réel reste volontairement hors seed et doit être validé avec une configuration Stripe de test et un scénario E2E. Voir l'[audit de couverture Salon Éclat](audits/demo-salon/2026-08-07-salon-eclat-demo-coverage.md).

> **Capsules réutilisables.** Les explications générales — onboarding, création d'un client, d'une prestation, d'une réservation, d'une promotion, ajout d'un membre et encaissement — sont organisées dans la [bibliothèque vidéo](demo/video-library/README.md). Pendant cette démo métier, cite l'identifiant de la capsule correspondante au lieu de refaire tout son tutoriel.

---

# PARTIE 0 — Ce que tu vas produire

## 0.1 Les trois livrables vidéo

| Format | Durée | Usage | Actes couverts |
|---|---|---|---|
| **Teaser** | 60-90 s | Instagram / TikTok / pub | Extraits des actes 3, 4, 5 |
| **Démo commerciale** | 4-5 min | Envoi à un prospect après appel | Actes 0, 3, 4, 5, 6 condensés |
| **Démo complète** | 13-16 min | Page site / webinaire / RDV démo | Actes 0 à 8 |

Tourne **une seule fois la version complète**, puis dérive les deux autres au montage. Le script ci-dessous est écrit dans cet esprit : chaque acte est autonome et peut être coupé.

## 0.2 Le fil rouge narratif

Ne fais pas une visite guidée de menus. Raconte **une journée au salon** :

```
Le problème   →  Installer le salon  →  Ouvrir l'agenda  →  Le client réserve
     ↓
Le jour J (accueil, file, service)  →  Encaisser  →  Faire revenir  →  Piloter
```

Chaque acte répond à une douleur réelle de salon :

| Acte | Douleur du salon | Ce que la plateforme montre |
|---|---|---|
| 1 | « J'ai pas le temps de paramétrer un logiciel » | Onboarding secteur Salon, rubriques pré-remplies |
| 2 | « Mon agenda papier me fait perdre des créneaux » | Chaises/postes, dispos, planning, buffers |
| 3 | « Je réponds au téléphone pendant que je coupe » | Lien de réservation public, portail client, liste d'attente |
| 4 | « Les walk-ins cassent mon planning » | Kiosque, file hybride, écran live, pointage |
| 5 | « Je perds du CA sur les no-shows et les pourboires » | Acompte, frais d'absence, pourboire, POS, fidélité |
| 6 | « Mes clients ne reviennent pas assez » | Avis, campagnes, réseaux sociaux, VIP, forfaits |
| 7 | « Je ne sais pas qui me rapporte quoi » | Performance équipe, pourboires, dépenses, compta |

---

# PARTIE 1 — Préparation technique (à faire la veille)

## 1.1 Démarrer l'environnement

```bash
# Terminal 1 — l'app est servie par Herd sur https://malikia.test
# Vérifier que la base est à jour
herd php artisan migrate
herd php artisan storage:link

# Terminal 2 — OBLIGATOIRE : le provisioning de démo, les emails et les
# notifications passent par la file (QUEUE_CONNECTION=database)
herd php -d memory_limit=512M artisan queue:workloads development --memory=512

# Terminal 3 — assets
npm run dev        # tournage en local
# ou npm run build  # si tu veux le rendu production (recommandé pour la vidéo)
```

> ⚠️ **Sans le worker `php -d memory_limit=512M artisan queue:workloads development --memory=512`, la création d'un espace de démo reste bloquée en « En attente » et aucun email ne part.** C'est le piège n°1 le jour du tournage.

## 1.2 Choisir ta stratégie d'espace de démo

### Option A — Onboarding réel *(recommandée pour la vidéo)*

Tu crées le salon en direct devant la caméra. C'est **le meilleur argument de vente** : le prospect voit qu'en 4 minutes son salon est opérationnel. En choisissant le secteur **Salon**, la plateforme pré-crée automatiquement les rubriques : *Coupe, Coloration, Coiffage, Soin capillaire, Barbier*.

- URL : `https://malikia.test/onboarding`
- Étapes : Compte → Entreprise → Type → Secteur → Équipe → Forfait → Sécurité

### Option B — Espace de démo pré-rempli *(recommandée pour tester vite)*

Pour parcourir une journée crédible de Salon Éclat sans saisie structurelle préalable. Ce preset fournit les données métier nécessaires aux actes catalogue, réservation, file, encaissement local, fidélisation et marketing.

1. Connecte-toi en `superadmin@example.com` / `password`
   (si le compte n'existe pas, un `herd php artisan app:launch-reset --force` peut reconstruire le socle local, mais cette commande est destructive et ne doit être lancée que volontairement)
2. Va dans **Super Admin → Demos** (`/super-admin/demo-workspaces`)
3. **Créer** → choisis le preset **`Salon Éclat - immersive`** (`salon_eclat_complete`).
   Il préremplit **Salon Éclat**, **Amina Diallo**, la langue française, le fuseau `America/Toronto` et le profil de données immersif.
   Il active : Prestations, Réservations & file, Planning, Présence, Factures, Dépenses, Équipe, Performance, Produits, Ventes/POS, Promotions, Fidélité, Campagnes, Assistant et Social.
   Il garde Devis, Demandes, Scan de plans, Chantiers et Tâches désactivés, car ces modules ne correspondent pas au parcours normal d'un salon.
4. Le provisioning crée notamment : les **3 employés** Sophie, Karim et Léa, **10 prestations**, **5 produits**, des ventes POS, **3 forfaits**, l'historique fidélité, `RENTREE20`, la campagne `WINBACK`, du contenu Assistant/Social et un lien public de réservation actif.
5. Langue : **fr** · Fuseau : **America/Toronto** · Équipe : **3**
6. Accès supplémentaires : prépare **Front desk** et **Staff**, les deux rôles supplémentaires fournis par ce preset
7. Lance le provisioning → attends la fin (queue worker actif)
8. Sur la fiche de l'espace, récupère **l'email + mot de passe owner** et les identifiants des 2 accès supplémentaires

### Le combo idéal pour la vidéo

Tourne **l'acte 1 en Option A** (onboarding live, effet « waouh, c'est déjà prêt »), puis utilise l'espace Option B pour les actes suivants. Le preset immersif évite la saisie manuelle du catalogue, des produits, des forfaits, de la fidélité, de la campagne, du contenu Social/Assistant et de la preuve d'encaissement local.

> **Stripe réel : étape séparée.** Le seed ne crée jamais de fausse session Checkout, de faux PaymentIntent ni de fausse référence Stripe. Pour filmer un paiement par carte, configure Stripe/Stripe Connect en mode test, le webhook et les URL de retour, puis exécute le parcours E2E sur un nouveau ticket. Si Stripe n'est pas configuré, montre la trace locale seedée, pas un paiement carte simulé.

## 1.3 Les 5 sessions à ouvrir

Le module réservation prend tout son sens quand on voit **plusieurs points de vue simultanément**. Prépare :

| # | Rôle | Navigateur / fenêtre | Écran de départ |
|---|---|---|---|
| 1 | **Propriétaire** | Chrome, profil principal | `/dashboard` |
| 2 | **Réception (front desk)** | Chrome, profil 2 | `/app/reservations` |
| 3 | **Coiffeuse (staff)** | Firefox ou fenêtre privée | `/presence` |
| 4 | **Cliente** | Fenêtre privée + **vue mobile** (F12 → iPhone 14) | lien public `/book/...`, puis `/client/reservations` |
| 5 | **Écran TV du salon** | Onglet plein écran | `/app/reservations/screen` |

> Le kiosque s'ouvre **depuis l'écran live** (bouton *Ouvrir le kiosque* en haut à droite). C'est une URL signée : ne la tape pas à la main, elle expirerait. Et elle n'apparaît **que si le mode file hybride est activé** dans les paramètres réservations.

## 1.4 Réglages de capture

- Résolution : **1920 × 1080**, zoom navigateur **100 %** (à 110 % les tableaux débordent)
- Masque la barre de favoris, les extensions, les notifications système
- Thème : reste en **clair** pour toute la vidéo, sauf si tu fais un plan « mode sombre » de 3 s (l'app gère les deux)
- Cache le curseur quand tu ne cliques pas, ou active la mise en évidence des clics
- Vue mobile : capture la fenêtre du navigateur en mode device, puis recadre en 9:16 au montage pour les extraits réseaux sociaux
- Enregistre l'écran **et** ta voix en pistes séparées : tu pourras réenregistrer la narration sans refilmer

## 1.5 Pièges connus le jour J

| Symptôme | Cause | Correctif |
|---|---|---|
| Espace de démo bloqué « en attente » | Pas de queue worker | `herd php -d memory_limit=512M artisan queue:workloads development --memory=512` |
| Pas de bouton « Ouvrir le kiosque » | Mode file hybride désactivé | Paramètres → Réservations → *Activer le mode file hybride* |
| Aucun créneau proposé au client | Pas de disponibilité hebdo définie | Paramètres → Réservations → Disponibilités hebdomadaires |
| Menu Réservations absent | Feature `reservations` inactive | Le secteur doit être `salon` (ou activer la feature côté Super Admin → Espaces → Features) |
| Emails non reçus | SMTP local | Utilise un catcher local, ou montre la notification in-app plutôt que la boîte mail |
| KPI vides | Données trop récentes | Utilise l'espace de démo Option B, dont l'historique est pré-généré |

---

# PARTIE 2 — Jeu de données « Salon Éclat »

Le preset `salon_eclat_complete` provisionne ce jeu de données. Il est calibré pour que chaque écran soit crédible sans être surchargé ; complète seulement les éléments externes propres au tournage, comme le logo final, une boîte de réception de test ou Stripe en mode test.

## 2.1 Entreprise

| Champ | Valeur |
|---|---|
| Nom | **Salon Éclat** |
| Type | Services |
| Secteur | **Salon** |
| Ville / Pays | Montréal, Canada |
| Fuseau | America/Toronto |
| Langue | Français |
| Description | Salon de coiffure et barbier — coupe, couleur, soin. |

## 2.2 Équipe (`/team`)

| Nom | Rôle | Ce qu'il démontre |
|---|---|---|
| Amina Diallo | Propriétaire | Vue complète, tous les KPI |
| Sophie Tremblay | Admin — Réception | File, check-in, encaissement |
| Karim Benali | Membre — Coiffeur | Pointage, ses RDV, ses pourboires |
| Léa Moreau | Membre — Coloriste | Deuxième chaise, pour la vue équipe |

## 2.3 Prestations (`/service`) — rubriques déjà créées par l'onboarding

| Rubrique | Prestation | Durée | Prix |
|---|---|---|---|
| Coupe | Coupe femme + brushing | 60 min | 65 $ |
| Coupe | Coupe homme | 30 min | 35 $ |
| Coupe | Coupe enfant (-12 ans) | 30 min | 25 $ |
| Coloration | Couleur racines | 90 min | 95 $ |
| Coloration | Balayage complet | 180 min | 210 $ |
| Coiffage | Brushing seul | 30 min | 35 $ |
| Coiffage | Chignon / événement | 60 min | 85 $ |
| Soin capillaire | Soin profond kératine | 45 min | 75 $ |
| Barbier | Taille de barbe | 20 min | 25 $ |
| Barbier | Rasage traditionnel | 30 min | 40 $ |

> Les durées variées (20 → 180 min) sont **volontaires** : elles rendent la démo du calcul de créneaux et de l'occupation beaucoup plus parlante qu'avec des durées uniformes.

## 2.4 Produits de revente (`/product`)

| Produit | Prix | Stock |
|---|---|---|
| Shampoing réparateur 250 ml | 28 $ | 24 |
| Après-shampoing hydratant | 26 $ | 18 |
| Huile capillaire argan 100 ml | 34 $ | 12 |
| Cire coiffante mate | 22 $ | 30 |
| Peigne bois artisanal | 18 $ | 8 |

## 2.5 Packs / Forfaits (`/offer-packages`)

| Pack | Contenu | Prix | Validité | Récurrent |
|---|---|---|---|---|
| Carte 10 brushings | 10 séances | 300 $ (au lieu de 350) | 12 mois | Non |
| Abonnement Barbe | 2 tailles / mois | 40 $ / mois | — | **Mensuel** |
| Forfait Couleur Trimestre | 3 couleurs racines | 255 $ | 6 mois | Non |

## 2.6 Clients (`/customer`)

| Cliente | Profil de démo |
|---|---|
| Marie Lefebvre | Cliente fidèle, forte en points fidélité, détentrice de la Carte 10 brushings |
| Julie Nadeau | **Nouvelle cliente** — c'est elle qui réserve en direct dans l'acte 3 |
| Fatou Camara | Cliente VIP, panier élevé, couleur régulière |
| Thomas Roy | Client barbier, abonnement mensuel |
| Claire Dubois | **Cliente perdue** (dernière visite il y a 5 mois) → cible de la campagne winback de l'acte 6 |

## 2.7 Promotion (`/promotions`)

| Code | Offre | Fenêtre |
|---|---|---|
| `RENTREE20` | -20 % sur **Couleur racines**, minimum 75 $ | Dates relatives : 7 jours avant → 30 jours après le provisioning |

---

# PARTIE 3 — Configuration complète (checklist de test)

Cette partie **est** le test de la plateforme. Chaque ligne cochée = une fonction validée. Fais-la en entier **avant** le tournage : tu découvriras les blocages à froid plutôt qu'en direct.

## 3.1 Fondations

- [ ] `/onboarding` — créer le compte, l'entreprise, choisir **Services** puis secteur **Salon**
- [ ] Vérifier que les rubriques *Coupe / Coloration / Coiffage / Soin capillaire / Barbier* existent dans `/services/categories`
- [ ] Uploader le logo du salon (onboarding ou `/settings/company`)
- [ ] `/settings/company` — devise, coordonnées, moyens de paiement acceptés
- [ ] `/settings/security` — activer la 2FA (obligatoire pour les propriétaires)
- [ ] `/team` — inviter les 3 membres, vérifier la réception des identifiants temporaires
- [ ] `/settings/roles-permissions` — créer un rôle **Réception** (réservations + file + facturation, sans accès finance) et l'assigner à Sophie

## 3.2 Catalogue

- [ ] `/service` — créer les 10 prestations avec durée + prix
- [ ] `/services/categories` — vérifier le rattachement des prestations aux rubriques
- [ ] `/product` — créer les 5 produits avec stock
- [ ] Tester l'ajustement de stock sur un produit (bouton *Ajuster le stock*)
- [ ] `/offer-packages` — créer les 3 packs, dont un **récurrent mensuel**
- [ ] Rendre un pack **public** et vérifier son affichage sur la vitrine

## 3.3 Réservations — le cœur du produit

- [ ] `/settings/reservations` — **Preset métier = Salon de coiffure / beauté**
- [ ] Règles entreprise :
  - Intervalle des créneaux : **15 min**
  - Minutes de buffer : **10 min** (nettoyage du poste entre deux clientes)
  - Préavis minimum : **60 min**
  - Réservation max à l'avance : **60 jours**
  - Limite d'annulation : **24 h**
  - Libération retard : **10 min**
- [ ] **Chaises / postes** — créer : `Fauteuil 1`, `Fauteuil 2`, `Fauteuil 3` (type *Fauteuil*), `Bac 1` (type *Bac*)
- [ ] Assigner les fauteuils aux membres d'équipe
- [ ] Activer **la liste d'attente**
- [ ] Activer **le mode file hybride**
  - Mode d'attribution : **File par employé**
  - Mode d'ordonnancement : **FIFO + priorité rendez-vous**
  - Délai de grâce appel : **5 min**
  - Seuil de pré-appel : **2 personnes devant**
  - Marquer absent si délai dépassé : **activé**
- [x] **Politique d'acompte** : **20 $** dans les réglages du salon. Le lien public immersif ne l'exige pas encore, car aucun acompte ne doit être présenté comme encaissé sans checkout Stripe E2E validé.
- [ ] **Frais d'absence** : activer, **25 $**
- [ ] Uploader une **image de kiosque** aux couleurs du salon
- [ ] **Notifications réservations** : activer email + in-app, cocher création / replanification / annulation / rappel, **rappels à 24 h et 2 h**, demande d'avis après la fin, et les alertes file (pré-appel, appelé, ETA 10 min)
- [ ] **Surcharges par membre** : donner à Léa (coloriste) un buffer de 15 min
- [ ] **Disponibilités hebdomadaires** : Mar-Ven 9h-19h, Sam 9h-17h, fermé Dim-Lun
- [ ] **Exception** : fermer le 15 août (congé), et ouvrir exceptionnellement un dimanche
- [ ] **Lien de réservation public** : créer, activer, copier l'URL `/book/{salon}/{slug}`
- [ ] `/planning` — créer un shift récurrent pour Karim, poser une **absence** et un **congé**
- [ ] `/presence` — vérifier les réglages de pointage (auto à la connexion / manuel)

## 3.4 Argent

- [ ] `/settings/company` → **Paramètres pourboires** : max 25 %, défaut 15 %, **répartition entre membres assignés**, remboursement au prorata
- [ ] `/settings/loyalty` — activer, **1 point par 1 $**, montant minimum 20 $, libellé « points »
- [ ] `/marketing/vip-tiers` — créer les paliers **Bronze / Argent / Or**
- [ ] `/promotions` — créer `RENTREE20`
- [ ] `/settings/billing` — vérifier le plan et, si dispo, **Stripe Connect** (encaissement direct)
- [ ] `/expenses` — créer 3 dépenses (produits fournisseur, loyer, électricité) et tester le **scan IA d'un reçu**
- [ ] `/expenses` → **Petite caisse** : ouvrir un compte, saisir un mouvement, faire une clôture
- [ ] `/accounting` — vérifier le journal, passer une période en revue

> **Preuve financière fournie par le preset.** Un ticket de file suit réellement la chaîne `service terminé → encaissement local comptant → facture payée`. La facture conserve le détail des taxes Québec à **14,975 %**, le paiement associé un pourboire de **18 %** attribué au membre, et un reçu consultable. Cette trace valide les calculs et les relations métier ; elle ne prétend pas valider Stripe.

## 3.5 Croissance

- [ ] `/settings/marketing` — canaux, consentement, heures calmes, anti-fatigue
- [ ] `/marketing/mailing-lists` — créer « Clientes couleur »
- [ ] `/marketing/segments` — segment « Pas venu depuis 90 jours » et vérifier le compteur
- [ ] `/marketing/templates` — créer un template email « Nous avons pensé à vous »
- [ ] `/campaigns` — créer une campagne **WINBACK**, estimer l'audience, prévisualiser, **envoi test**, puis planifier
- [ ] `/campaign-automations` — créer une règle automatique
- [ ] `/social` (Malikia Pulse) — parcourir : Comptes, Composer, Calendrier, Voix de marque, Médias, Templates, Autopilot, À valider
- [ ] Générer un post « Nouveau balayage », le programmer, le passer en approbation
- [ ] `/admin/ai-assistant/settings` + `/admin/ai-assistant/knowledge` — alimenter la base de connaissances, tester l'assistant IA
- [ ] Ouvrir l'assistant IA public `/public/ai-assistant/{salon}` et poser « Vous êtes ouverts samedi ? »

## 3.6 Vitrine publique

- [ ] `/services/{slug}` — vérifier la page vitrine du salon (prestations, packs publics)
- [ ] `/book/{salon}/{slug}` — vérifier le parcours de réservation public sur mobile
- [ ] Vérifier que le lien de réservation est partageable (Instagram bio, Google, QR code sur la vitrine)

---

# PARTIE 4 — Le script, acte par acte

---

## ACTE 0 — Le hook ⏱️ 0:00 → 0:30

**Objectif** : accrocher un propriétaire de salon en 10 secondes. Pas de logo, pas de « bonjour et bienvenue ».

🎬 **Plan** — Pas d'interface. Écran noir, puis 4 cartes de texte qui s'enchaînent au rythme de la voix, sur fond de bruit ambiant de salon (sèche-cheveux, ciseaux, conversation).

📝
```
Le téléphone sonne pendant une couleur.
Une cliente ne vient pas. Personne n'est prévenu.
Un walk-in attend 40 minutes et repart.
Et le soir, personne ne sait vraiment ce que la journée a rapporté.
```

🎙️
> « Dans un salon, ce n'est pas le talent qui manque. C'est le temps. Chaque appel pendant une couleur, chaque no-show non facturé, chaque client qui repart parce qu'il ne sait pas combien il doit attendre — c'est du chiffre d'affaires qui sort par la porte. On va voir comment reprendre tout ça en main, en une seule plateforme. »

🎬 **Transition** — Fondu au blanc, puis apparition du dashboard `/dashboard` du Salon Éclat, avec ses KPI remplis.

📝 *(en surimpression, coin bas)* `Salon Éclat — une journée type`

---

## ACTE 1 — Installer le salon ⏱️ 0:30 → 2:30

**Objectif** : détruire l'objection « c'est trop long à mettre en place ».

### Séquence 1.1 — L'onboarding (45 s)

🎬 Depuis `/onboarding`, en accéléré ×2 avec la voix en temps réel :
1. Nom : **Salon Éclat**, logo déposé en glisser-déposer
2. Ville : Montréal
3. Type : **Services**
4. Secteur : **Salon** ← **ralentis ici, plan serré sur le clic**
5. Équipe : ajouter les 3 emails
6. Forfait, puis 2FA

🎙️
> « On commence par le début. Nom du salon, logo, ville. Type d'activité : services. Et surtout — le secteur : salon de coiffure et beauté. Ce choix-là n'est pas décoratif. »

🎬 **Le moment clé** — Une fois l'onboarding terminé, va dans `/services/categories`. Les rubriques *Coupe, Coloration, Coiffage, Soin capillaire, Barbier* sont **déjà là**.

🎙️
> « Regardez : coupe, coloration, coiffage, soin capillaire, barbier. Je n'ai rien saisi. La plateforme connaît votre métier et a préparé la structure de votre catalogue. »

📝 `Secteur "Salon" → rubriques créées automatiquement`

✅ **Test** : les 5 rubriques existent · le logo s'affiche dans la barre latérale · les 3 invitations d'équipe sont parties

### Séquence 1.2 — Le catalogue (45 s)

🎬 `/service` → *Nouvelle prestation*. Crée **Coupe femme + brushing**, 60 min, 65 $ en direct. Puis coupe au montage : la liste complète des 10 prestations est déjà là.

🎙️
> « Une prestation, c'est trois informations : le nom, la durée, le prix. La durée, c'est ce qui va piloter tout votre agenda — un balayage à 3 heures ne bloque pas la même chose qu'une taille de barbe à 20 minutes. »

🎬 Puis `/product` — les 5 produits de revente avec leur stock.

🎙️
> « Et vous ne vendez pas que du service. Vos shampoings, vos huiles, vos cires : ils sont ici, avec leur stock. On les revendra à l'encaissement, en deux clics, sans caisse séparée. »

✅ **Test** : durée et prix corrects · stock affiché · une prestation apparaît bien dans sa rubrique

### Séquence 1.3 — L'équipe et les droits (30 s)

🎬 `/team` — les 4 membres avec leurs rôles. Puis `/settings/roles-permissions` : ouvre le rôle **Réception**.

🎙️
> « Sophie tient la réception. Elle doit voir l'agenda, encaisser, gérer la file. Elle n'a aucune raison de voir votre marge ou vos dépenses. Chaque rôle voit exactement ce dont il a besoin — ni plus, ni moins. »

📝 `Rôles sur mesure — chacun voit ce qui le concerne`

✅ **Test** : connecte-toi en Sophie → les menus Finance et Comptabilité sont absents

🎬 **Transition** — Zoom avant sur l'icône *Opérations* de la barre latérale, coupe sur `/settings/reservations`.

---

## ACTE 2 — Ouvrir l'agenda ⏱️ 2:30 → 4:30

**Objectif** : montrer que l'agenda **connaît les contraintes d'un salon** — ce qu'un Google Agenda ne fera jamais.

### Séquence 2.1 — Les règles du salon (50 s)

🎬 `/settings/reservations`, plan large puis serré sur chaque champ au fil de la voix.

🎙️
> « Voici ce qui différencie un agenda de salon d'un agenda tout court. Dix minutes de battement entre deux clientes, pour nettoyer le poste. Une heure de préavis minimum, pour ne pas recevoir une réservation cinq minutes avant l'ouverture. Vingt-quatre heures pour annuler. Et si la cliente a dix minutes de retard, le créneau se libère automatiquement. »

📝 `Buffer 10 min · Préavis 1 h · Annulation 24 h · Libération 10 min`

### Séquence 2.2 — Les chaises (30 s)

🎬 Fais défiler jusqu'à **Chaises / postes**. Montre `Fauteuil 1/2/3` et `Bac 1`, puis l'assignation à un membre.

🎙️
> « Votre capacité réelle, ce n'est pas votre nombre d'employés. C'est votre nombre de fauteuils et de bacs. La plateforme le sait : trois fauteuils, un bac, chacun rattaché à une personne. Aucune double réservation possible sur le même poste. »

✅ **Test** : crée deux réservations sur le même fauteuil au même horaire → la seconde doit être refusée

### Séquence 2.3 — Politique d'acompte et no-show (25 s)

🎬 Plan serré sur la politique *Acompte* = 20 $ et *Frais d'absence* = 25 $. Ne filme pas un encaissement d'acompte tant que le scénario Stripe E2E n'est pas vert.

🎙️
> « Le balayage à 210 $ qui ne se présente pas, c'est trois heures de fauteuil perdues. La politique d'acompte et les frais d'absence sont configurés ici. L'encaissement d'acompte sera montré uniquement après sa validation Stripe de bout en bout. »

📝 `Politique : acompte 20 $ · absence 25 $`

### Séquence 2.4 — Disponibilités et planning (35 s)

🎬 Section **Disponibilités hebdomadaires** : mardi-vendredi 9h-19h, samedi 9h-17h. Puis crée une **exception** de fermeture le 15 août.

🎬 Enchaîne sur `/planning` — vue Semaine, les shifts colorés par membre. Pose une **absence** pour Léa.

🎙️
> « Les horaires du salon, les horaires de chacun, les congés, les absences. Vous les posez une fois — et plus jamais un client ne pourra réserver un créneau qui n'existe pas. »

✅ **Test** : après la fermeture du 15 août, le lien public ne propose plus aucun créneau ce jour-là

🎬 **Transition** — Split-screen : à gauche l'agenda du salon, à droite un téléphone qui s'allume. Le côté gauche s'estompe.

---

## ACTE 3 — Le client réserve ⏱️ 4:30 → 7:00

**Objectif** : l'acte le plus vendeur. Filme-le entièrement **en vue mobile**.

### Séquence 3.1 — Le lien de réservation (20 s)

🎬 `/settings/reservations` → **Liens de réservation publics**. Copie l'URL.

🎙️
> « Ce lien, vous le mettez dans votre bio Instagram, sur votre fiche Google, en QR code sur la vitrine. C'est votre réception ouverte 24 heures sur 24. »

📝 `Un lien. Bio Instagram, Google, QR code en vitrine.`

### Séquence 3.2 — Julie réserve (60 s)

🎬 Fenêtre privée, **vue mobile**, sur `/book/salon-eclat/rendez-vous` (ou l'URL copiée depuis les réglages). Julie Nadeau :
1. Choisit **Balayage complet** — 180 min, 210 $
2. Choisit **Léa** (ou *N'importe quel membre disponible*)
3. Le calendrier charge les créneaux → **plan serré** : les jours fermés sont grisés, les créneaux tiennent compte des 3 heures et du buffer
4. Sélectionne samedi 10h00
5. Renseigne nom, email, téléphone, note « Je viens de la part de Marie »
6. **Confirmer la réservation**

🎙️
> « Julie choisit sa prestation. Un balayage, trois heures. Elle choisit sa coloriste. Et là, seuls les créneaux réellement disponibles s'affichent : les trois heures sont vérifiées, le buffer est ajouté, le samedi de fermeture n'existe même pas. Elle confirme. Ça lui a pris quarante secondes, à trois heures du matin s'il le fallait. Et vous, vous n'avez pas décroché. »

✅ **Test** : le créneau n'est plus proposé à un second client · la réservation apparaît en **En attente** côté salon · aucun acompte n'est annoncé ou débité par ce lien

### Séquence 3.3 — Côté salon (25 s)

🎬 Fenêtre Sophie (réception), `/app/reservations`. La réservation de Julie est en tête, statut **En attente**. Sophie clique **Confirmer**.

🎬 Montre les statistiques en haut : Total, En attente, Confirmées, Terminées, Aujourd'hui, À venir.

🎙️
> « Côté salon, la réservation tombe immédiatement. Sophie confirme. Julie reçoit sa confirmation, puis un rappel vingt-quatre heures avant, et un autre deux heures avant. Zéro appel, zéro post-it. »

📝 `Rappels automatiques : J-1 et H-2`

### Séquence 3.4 — Le portail client (25 s)

🎬 Fenêtre Julie → **Mes réservations**. Elle clique **Replanifier**, choisit un autre créneau, valide. Retour à la fenêtre Sophie : la réservation a bougé, statut **Replanifiée**.

🎙️
> « Un empêchement ? Julie déplace elle-même son rendez-vous, dans la limite que vous avez fixée. Le créneau se libère instantanément pour quelqu'un d'autre. Vous n'avez rien fait — et vous ne perdez pas la vente. »

✅ **Test** : la replanification respecte la limite de 24 h · l'ancien créneau redevient réservable

### Séquence 3.5 — La liste d'attente (20 s)

🎬 En vue mobile, cherche un samedi complet → le bloc **Rejoindre la liste d'attente** apparaît. Rejoins-la. Côté salon, `/app/reservations` → section **Liste d'attente** → **Libérer le créneau**.

🎙️
> « Et quand c'est complet ? Le client ne repart pas chez le concurrent : il entre en liste d'attente. Une annulation, un clic, et il est prévenu en premier. »

📝 `Complet ≠ perdu`

🎬 **Transition** — Time-lapse d'une vitrine de salon qui passe de la nuit au jour. 📝 `8h55 — Samedi matin`

---

## ACTE 4 — Le jour J ⏱️ 7:00 → 10:00

**Objectif** : le moment le plus spectaculaire de la démo. C'est ici que se joue la vente.

### Séquence 4.1 — Karim pointe (20 s)

🎬 Fenêtre Karim, `/presence`. Il clique **Pointer**. Le statut passe à **En service**, avec la mention *Vous êtes pointé sur Fauteuil 2*.

🎙️
> « Neuf heures. Karim arrive et pointe. Il est en service, sur le fauteuil 2. Ses heures sont comptées, sa disponibilité est visible par toute l'équipe. »

✅ **Test** : le statut remonte dans la vue **Présence équipe** côté propriétaire

### Séquence 4.2 — Le kiosque (60 s) ⭐ *le plan signature*

🎬 Depuis `/app/reservations/screen`, clique **Ouvrir le kiosque** → nouvel onglet plein écran, avec l'image du salon et le **temps d'attente estimé**.

Filme les **trois onglets** :

**a) Walk-in** — un client sans rendez-vous
1. Saisit son numéro
2. Prénom : *Nicolas*
3. Service souhaité : **Coupe homme**
4. Employé : *N'importe quel employé*
5. **Prendre mon ticket** → numéro de ticket + position + ETA

**b) Déjà client** — Marie Lefebvre a rendez-vous
1. Numéro de téléphone → **Trouver mon profil**
2. *« Vous avez une réservation proche prête pour check-in »*
3. **Faire le check-in**

**c) Suivre ticket** — Nicolas revient vérifier sa position

🎙️
> « Une tablette à l'entrée. Un client sans rendez-vous prend son ticket lui-même : il choisit sa prestation, et il connaît immédiatement sa position et son temps d'attente. Une cliente qui a réservé se signale toute seule, en tapant son numéro. Et n'importe qui peut suivre sa place. Pendant ce temps, votre réception n'a été interrompue zéro fois. »

📝 `Walk-in · Check-in · Suivi — sans mobiliser personne`

✅ **Test** : le ticket walk-in **et** le check-in apparaissent tous les deux dans la file · le ticket reçoit son SMS si les notifications file sont actives

### Séquence 4.3 — L'écran live (50 s) ⭐

🎬 Onglet `/app/reservations/screen`, **Mode TV**, plein écran. Montre :
- **En cours** — les fauteuils occupés, avec le timer qui tourne
- **Prochain** — le client suivant
- **File d'attente** — la liste, avec position et attente moyenne
- Les métriques : *Sièges actifs · Occupés · Prêts · Attente moy. · Prochaine réservation*
- Bascule **Afficher / Masquer les noms** (RGPD, salle d'attente)

🎙️
> « C'est l'écran que vous accrochez dans la salle d'attente. Qui est en train d'être servi, sur quel fauteuil, depuis combien de temps. Qui passe après. Et le temps d'attente réel — pas une estimation à la louche. Un client qui sait combien il attend, c'est un client qui reste. »

📝 `Le client sait. Il attend. Il ne repart pas.`

### Séquence 4.4 — Piloter la file (50 s)

🎬 Fenêtre Sophie, `/app/reservations`, section **File hybride**. Enchaîne les actions **en montrant l'écran TV réagir en direct** (split-screen recommandé) :

| Action | Ce qui se passe |
|---|---|
| **Pré-appel** | Le client est prévenu qu'il passe bientôt |
| **Appeler** | Le nom s'affiche sur l'écran TV, le délai de grâce démarre |
| **Démarrer** | Le client passe *En service*, le timer démarre |
| **Terminer** | Le fauteuil se libère, le suivant remonte |
| **Appeler le prochain** | Le système désigne lui-même qui passe |
| **Ignorer** | Le client absent recule sans bloquer la file |

🎙️
> « Sophie pilote tout depuis un seul écran. Pré-appel : le client est prévenu qu'il passe bientôt. Appel : son nom s'affiche sur l'écran, il a cinq minutes pour se présenter. Démarrage, service, terminé — et le suivant remonte tout seul. Le mode choisi ici, c'est FIFO avec priorité aux rendez-vous : ceux qui ont réservé passent avant les walk-ins. Votre agenda reste tenu. »

📝 `FIFO + priorité rendez-vous — les réservations passent devant`

✅ **Test** : chaque statut se propage à l'écran live en moins de 5 s · le délai de grâce expiré marque bien **Absent** si l'option est active · *Appeler le prochain* respecte la priorité rendez-vous

🎬 **Transition** — Le timer de l'écran live s'arrête, la carte devient verte. Coupe sur la fiche de la réservation.

---

## ACTE 5 — Encaisser ⏱️ 10:00 → 12:00

**Objectif** : montrer que la sortie de caisse capte **tout** le chiffre d'affaires possible.

Le preset contient déjà une transaction locale complète à auditer : ticket de file, facture avec taxes à 14,975 %, paiement comptant, pourboire de 18 %, attribution et reçu. Pour tourner la séquence en direct, crée un nouveau ticket et choisis l'une des deux preuves suivantes :

- **parcours local déterministe** : paiement comptant, puis vérification immédiate de la facture et du reçu ;
- **parcours carte réel** : Stripe/Stripe Connect configuré en mode test, passage sur Checkout Stripe, retour sur la plateforme, puis vérification de la facture payée et du reçu.

Le second parcours est un test E2E obligatoire avant de le filmer. Aucune session, référence ou réussite Stripe n'est simulée par les données de démo.

### Séquence 5.1 — Terminer et facturer (30 s)

🎬 `/app/reservations` → la réservation de Marie → **Marquer terminée**. Puis `/invoices` : la facture est là.

🎙️
> « Le service est terminé. La facture est déjà prête — vous n'avez rien saisi. »

### Séquence 5.2 — Vendre un produit (25 s)

🎬 `/sales/create` — le point de vente. Ajoute **Shampoing réparateur** + **Huile argan**, rattache à Marie.

🎙️
> « Marie repart avec son shampoing et son huile. Deux clics, le stock se décrémente, la vente est rattachée à sa fiche. Pas de caisse séparée, pas de double saisie le soir. »

✅ **Test** : le stock passe de 24 à 23 · la vente apparaît sur la fiche client

### Séquence 5.3 — Le pourboire (30 s) ⭐

🎬 Fenêtre Marie (portail client / lien de paiement). Le bloc **« Ajouter un pourboire ? (optionnel) »** apparaît avec les boutons de pourcentage. Elle choisit **18 %**, puis paie. Si tu montres la carte, filme aussi Checkout Stripe et le retour vers la plateforme ; sinon sélectionne comptant et présente clairement ce mode.

🎙️
> « Au moment de payer, Marie se voit proposer un pourboire. Elle choisit dix-huit pour cent. Et ce pourboire va directement à la personne qui l'a coiffée — c'est la plateforme qui fait la répartition, selon la règle que vous avez fixée. »

📝 `Pourboire → attribué automatiquement au bon membre`

🎬 Enchaîne sur `/payments/tips` (vue propriétaire) : total, moyenne par réservation, top 3 membres. Puis fenêtre Karim → `/my-earnings/tips`.

🎙️
> « Vous suivez le total et la répartition. Karim, lui, voit ses propres pourboires, en toute transparence. Fini le pot commun et les discussions du vendredi soir. »

✅ **Test** : taxes à 14,975 % conservées sur la facture · pourboire de 18 % rattaché au paiement et au bon membre · total encaissé cohérent · reçu accessible · l'export CSV fonctionne

### Séquence 5.4 — Les points de fidélité (20 s)

🎬 Fiche de Marie ou `/loyalty` : les points viennent d'être crédités.

🎙️
> « Et le paiement lui a rapporté des points, automatiquement. Un dollar dépensé, un point. Elle n'a rien demandé, vous n'avez rien fait. »

### Séquence 5.5 — Le forfait qui se consomme (25 s)

🎬 Fiche client → **Forfaits** → la Carte 10 brushings de Marie : 7 séances restantes, l'historique des consommations. Puis fenêtre Marie → `/portal/packages` (**Mes forfaits**).

🎙️
> « Marie a une carte de dix brushings. À chaque passage, une séance se décompte automatiquement. Elle voit son solde de son côté, vous voyez le vôtre. Et quand elle arrive au bout, elle peut demander le renouvellement d'un bouton. C'est ça, du revenu récurrent dans un salon. »

📝 `Cartes, abonnements, forfaits — du revenu prévisible`

✅ **Test** : la séance se décompte à la fin du service · la demande de renouvellement remonte côté salon · un pack **récurrent mensuel** génère bien sa facture

🎬 **Transition** — La fiche de Marie se réduit et rejoint une grille de fiches clients. 📝 `Et après la visite ?`

---

## ACTE 6 — Faire revenir ⏱️ 12:00 → 14:00

**Objectif** : passer du logiciel de gestion à l'outil de croissance. C'est ce qui justifie le prix.

### Séquence 6.1 — L'avis client (20 s)

🎬 Fenêtre Marie → **Mes réservations** → **Laisser un avis** → 5 étoiles + commentaire. Puis côté salon, l'avis remonte.

🎙️
> « Après le service, Marie reçoit automatiquement une demande d'avis. Elle note, elle commente. Vous récoltez de la preuve sociale sans jamais avoir à la réclamer. »

### Séquence 6.2 — La campagne de reconquête (45 s)

🎬 `/marketing/segments` — le segment **« Pas venu depuis 90 jours »**, avec son compteur. Puis `/campaigns` → *Nouvelle campagne* :
1. Type : **WINBACK**
2. Canal : **Email** (+ SMS si dispo)
3. Audience : le segment
4. Offre : **-20 % coloration** (`RENTREE20`)
5. **Estimer l'audience** → le nombre s'affiche
6. **Prévisualiser** → le message rendu, avec les variables remplacées
7. **Envoi test** → vers ta propre adresse
8. **Planifier** — mardi 10h

🎙️
> « Claire n'est pas revenue depuis cinq mois. Elle n'est pas la seule. Un segment, une offre, un message — et la plateforme s'occupe du reste. Elle respecte les consentements, elle évite les heures creuses, elle ne sature personne. Et surtout : elle vous dit combien de rendez-vous cette campagne a réellement générés. »

📝 `Segment → Offre → Envoi → Rendez-vous mesurés`

✅ **Test** : l'estimation d'audience est cohérente · l'envoi test arrive · les contacts sans consentement sont bien exclus · le lien de suivi enregistre le clic

### Séquence 6.3 — Malikia Pulse (35 s)

🎬 `/social` — la vue d'ensemble, puis **Composer** : rédige un post « Nouveau balayage chez Salon Éclat », joins une photo, vois le **score qualité** et l'aperçu visuel. Puis **Calendrier** : les posts programmés. Puis **À valider**.

🎙️
> « Votre salon vit sur Instagram. Ici, vous composez, vous voyez l'aperçu réel, vous programmez, et un contrôle qualité vous dit avant publication si le texte est trop long ou s'il manque une image. Vous pouvez même faire valider les posts avant qu'ils partent. Un mois de contenu préparé un dimanche soir. »

📝 `Composer · Programmer · Valider — depuis la même plateforme`

### Séquence 6.4 — Les paliers VIP (20 s)

🎬 `/marketing/vip-tiers` — Bronze / Argent / Or. Puis une fiche cliente marquée VIP.

🎙️
> « Et vos meilleures clientes ? Elles sont identifiées, classées, et vous pouvez leur réserver vos meilleures offres. »

🎬 **Transition** — Les cartes clientes se rangent en colonnes de graphique. Coupe sur `/performance`.

---

## ACTE 7 — Piloter ⏱️ 14:00 → 15:30

**Objectif** : parler au propriétaire-gestionnaire. Rythme plus posé.

### Séquence 7.1 — La performance (35 s)

🎬 `/performance` — onglet **Équipe** puis **Clients**, période *Ce mois-ci*. Montre : chiffre d'affaires, valeur moyenne, CA par membre, meilleurs clients, **client du mois**.

🎙️
> « Qui rapporte quoi. Le chiffre d'affaires par coiffeur, la valeur moyenne d'un passage, vos meilleures clientes. Plus besoin d'attendre la fin du mois et le tableur du comptable. »

### Séquence 7.2 — Les indicateurs réservations (25 s)

🎬 `/app/reservations`, bloc **Performance (30 derniers jours)** : taux d'occupation, taux d'absence, taux de replanification, taux de complétion, panier moyen service, taux de pourboire, utilisation des ressources.

🎙️
> « Et les chiffres que seul un salon regarde vraiment : votre taux d'occupation, votre taux d'absence, le panier moyen par service, l'utilisation de chaque fauteuil. C'est là que vous voyez si vous avez besoin d'un quatrième poste — ou juste de mieux remplir les trois que vous avez. »

📝 `Taux d'occupation · No-show · Panier moyen · Utilisation des fauteuils`

### Séquence 7.3 — Les dépenses (20 s)

🎬 `/expenses` → *Nouvelle dépense* → **Scan IA** d'un reçu fournisseur : les champs se remplissent seuls. Puis la **Petite caisse**.

🎙️
> « Une facture fournisseur ? Vous la photographiez, l'IA la lit et remplit les champs. La petite caisse du salon est suivie, avec ses clôtures. »

### Séquence 7.4 — L'assistant IA (20 s)

🎬 Assistant IA : pose « Quel est mon taux d'occupation cette semaine ? ». Puis ouvre l'assistant **public** `/public/ai-assistant/{salon}` et demande « Vous êtes ouverts samedi ? ».

🎙️
> « Et vous pouvez simplement poser la question. Côté salon comme côté client : votre assistant répond à vos clients pendant que vous coupez. »

✅ **Test** : l'assistant répond en s'appuyant sur la base de connaissances · l'assistant public ne divulgue aucune donnée interne

---

## ACTE 8 — La clôture ⏱️ 15:30 → 16:00

🎬 **Plan de récapitulation** — Montage rapide de 8 plans déjà vus, 1 seconde chacun, sur une musique montante : lien de réservation → kiosque → écran TV → file → pourboire → forfait → campagne → performance.

🎙️
> « Une réception ouverte jour et nuit. Une file qui se pilote toute seule. Des pourboires qui vont à la bonne personne. Des forfaits qui font revenir. Et à la fin de la journée, des chiffres qui veulent dire quelque chose. »
>
> « Tout ce que vous venez de voir a été configuré en moins d'une heure. Pour votre salon, on peut préparer un espace de démonstration avec vos propres prestations, vos propres tarifs, votre propre équipe — et vous le remettre en main pour que vous l'essayiez vous-même. »

📝
```
Salon Éclat n'existe pas.
Le vôtre, oui.

→ [ton URL] · [ton téléphone]
Démo personnalisée gratuite
```

🎬 Logo, coordonnées, tenir 4 secondes.

---

# PARTIE 5 — Checklist de test exhaustive

À passer intégralement avant le tournage. Une case non cochée = un plan à ne pas filmer.

## Réservations & file

| # | Test | ✅ |
|---|---|---|
| R1 | Preset **Salon** appliqué | ☐ |
| R2 | Buffer, intervalle, préavis, annulation, libération retard enregistrés | ☐ |
| R3 | Chaises/postes créés et assignés | ☐ |
| R4 | Deux réservations simultanées sur le même poste → refusées | ☐ |
| R5 | Disponibilités hebdomadaires respectées côté client | ☐ |
| R6 | Exception de fermeture masque les créneaux | ☐ |
| R7 | Surcharge par membre (buffer de Léa) appliquée | ☐ |
| R8 | Lien public actif, créneaux corrects, mobile OK | ☐ |
| R9 | Aucun acompte annoncé sans checkout Stripe réellement actif | ☐ |
| R10 | Réservation → statut **En attente** côté salon | ☐ |
| R11 | Confirmer / Refuser / Terminer / **Marquer absent** | ☐ |
| R12 | Frais d'absence appliqués sur un no-show | ☐ |
| R13 | Replanification client dans la limite d'annulation | ☐ |
| R14 | Annulation client → créneau libéré | ☐ |
| R15 | Liste d'attente : rejoindre, **Libérer le créneau**, Marquer réservé | ☐ |
| R16 | Rappels J-1 et H-2 envoyés | ☐ |
| R17 | Demande d'avis après la fin, avis enregistré | ☐ |
| R18 | Kiosque : ticket walk-in avec position + ETA | ☐ |
| R19 | Kiosque : recherche client + check-in réservation | ☐ |
| R20 | Kiosque : suivi de ticket | ☐ |
| R21 | File : Check-in → Pré-appel → Appeler → Démarrer → Terminer | ☐ |
| R22 | **Appeler le prochain** respecte FIFO + priorité rendez-vous | ☐ |
| R23 | **Ignorer** recule sans bloquer la file | ☐ |
| R24 | Délai de grâce expiré → **Absent** | ☐ |
| R25 | Écran live : Mode TV / Mode tableau, timer, prochain, métriques | ☐ |
| R26 | Afficher / Masquer les noms | ☐ |
| R27 | Bloc Performance 30 jours renseigné | ☐ |

## Catalogue & offre

| # | Test | ✅ |
|---|---|---|
| C1 | Rubriques salon auto-créées | ☐ |
| C2 | 10 prestations avec durée + prix | ☐ |
| C3 | 5 produits avec stock, ajustement de stock | ☐ |
| C4 | 3 packs dont un récurrent mensuel | ☐ |
| C5 | Pack public visible sur la vitrine | ☐ |
| C6 | Attribution d'un pack à une cliente | ☐ |
| C7 | Consommation d'une séance au service | ☐ |
| C8 | Demande de renouvellement / annulation côté client | ☐ |

## Équipe & organisation

| # | Test | ✅ |
|---|---|---|
| E1 | 3 invitations envoyées et acceptées | ☐ |
| E2 | Rôle Réception limité (pas de finance) | ☐ |
| E3 | Pointage entrée / sortie / pause | ☐ |
| E4 | Chaise assignée visible au pointage | ☐ |
| E5 | Vue Présence équipe côté propriétaire | ☐ |
| E6 | Shift récurrent créé | ☐ |
| E7 | Absence et congé posés et visibles | ☐ |
| E8 | Membre ne voit que ses propres RDV | ☐ |

## Argent

| # | Test | ✅ |
|---|---|---|
| A1 | Facture générée en fin de service | ☐ |
| A2 | Envoi de la facture par email | ☐ |
| A3 | Paiement → statut mis à jour | ☐ |
| A4 | Vente POS produits, stock décrémenté | ☐ |
| A5 | Promotion `RENTREE20` appliquée | ☐ |
| A6 | Pourboire proposé au paiement | ☐ |
| A7 | Pourboire attribué au bon membre | ☐ |
| A8 | Dashboard pourboires propriétaire + export CSV | ☐ |
| A9 | Vue pourboires membre | ☐ |
| A10 | Points fidélité crédités | ☐ |
| A11 | Utilisation de points au POS | ☐ |
| A12 | Paliers VIP créés et attribués | ☐ |
| A13 | Dépenses + scan IA d'un reçu | ☐ |
| A14 | Petite caisse : mouvement + clôture | ☐ |
| A15 | Journal comptable alimenté, période en revue | ☐ |
| A16 | Export comptable généré | ☐ |

## Croissance

| # | Test | ✅ |
|---|---|---|
| G1 | Liste de diffusion créée | ☐ |
| G2 | Segment « 90 jours » avec compteur correct | ☐ |
| G3 | Template email créé et prévisualisé | ☐ |
| G4 | Campagne WINBACK : estimation, aperçu, envoi test | ☐ |
| G5 | Campagne planifiée puis envoyée | ☐ |
| G6 | Suivi de clic + désabonnement fonctionnels | ☐ |
| G7 | Contacts sans consentement exclus | ☐ |
| G8 | Post Pulse composé, score qualité affiché | ☐ |
| G9 | Post programmé au calendrier | ☐ |
| G10 | Circuit d'approbation d'un post | ☐ |
| G11 | Assistant IA interne répond | ☐ |
| G12 | Assistant IA public répond sans fuite de données | ☐ |

## Vue client

| # | Test | ✅ |
|---|---|---|
| P1 | Réservation depuis le lien public (mobile) | ☐ |
| P2 | Mes réservations : voir, replanifier, annuler | ☐ |
| P3 | Avis après service | ☐ |
| P4 | Mes forfaits : solde, consommations, factures | ☐ |
| P5 | Paiement de facture en ligne + pourboire | ☐ |
| P6 | Solde de points fidélité visible | ☐ |
| P7 | Page vitrine du salon accessible | ☐ |

---

# PARTIE 6 — Montage

## 6.1 Rythme

| Acte | Rythme | Musique |
|---|---|---|
| 0 — Hook | Lent, cartes de texte, silence | Ambiance salon seule |
| 1 — Installer | Rapide, accéléré ×2 | Rythmique légère |
| 2 — Agenda | Moyen, plans serrés | Continue |
| 3 — Le client réserve | **Temps réel, aucune accélération** | Baisse — la voix porte |
| 4 — Le jour J | Dynamique, split-screens | Monte |
| 5 — Encaisser | Moyen | Continue |
| 6 — Faire revenir | Moyen | Continue |
| 7 — Piloter | Posé | Redescend |
| 8 — Clôture | Montage rapide puis arrêt net | Monte puis coupe |

> **Règle d'or** : n'accélère jamais l'acte 3 ni l'acte 4. Un prospect doit voir que réserver prend **vraiment** 40 secondes et que la file réagit **vraiment** en temps réel. Une accélération détruit la preuve.

## 6.2 Transitions

| Entre | Transition |
|---|---|
| 0 → 1 | Fondu au blanc |
| 1 → 2 | Zoom sur l'icône *Opérations* de la barre latérale |
| 2 → 3 | Split-screen agenda / téléphone, le côté agenda s'estompe |
| 3 → 4 | Time-lapse vitrine nuit → jour |
| Dans l'acte 4 | Split-screen permanent : pilotage à gauche, écran TV à droite |
| 4 → 5 | Le timer s'arrête, la carte passe au vert |
| 5 → 6 | La fiche client se réduit et rejoint une grille |
| 6 → 7 | Les cartes se transforment en colonnes de graphique |
| 7 → 8 | Fondu au noir |

## 6.3 Textes à l'écran

- Maximum **7 mots** par carte
- Position constante : bas-gauche, marge de 80 px
- Durée : **2,5 s** minimum
- Jamais de texte qui répète la voix — il la **complète** (un chiffre, un nom de fonction)
- Réserve les cartes plein écran à l'acte 0 et à la clôture

## 6.4 Les 6 plans à ne surtout pas rater

1. Le clic sur **secteur = Salon**, suivi des rubriques déjà créées
2. Le calendrier mobile qui grise les créneaux indisponibles
3. La facture finale avec **taxes + pourboire + reçu**
4. Le **kiosque plein écran** avec le temps d'attente
5. Le split-screen **file / écran TV** qui réagit en direct
6. Le bloc **pourboire** au moment de payer

Si ton temps de tournage est limité, ce sont ces six plans-là qui vendent.

---

# PARTIE 7 — Les versions dérivées

## 7.1 Teaser 60-90 s (réseaux sociaux, format vertical)

| Temps | Contenu | Texte |
|---|---|---|
| 0-8 s | Écran noir, cartes du hook | *Le téléphone sonne pendant une couleur.* |
| 8-25 s | Julie réserve en mobile | *Elle réserve à 3h du matin. Vous dormiez.* |
| 25-45 s | Kiosque + écran TV | *Le walk-in prend son ticket tout seul.* |
| 45-60 s | Pourboire + performance | *Chaque pourboire à la bonne personne.* |
| 60-75 s | Logo + CTA | *Démo gratuite pour votre salon* |

Aucune voix off — musique + textes uniquement. Sous-titres impératifs (lecture sans son).

## 7.2 Démo commerciale 4-5 min (envoi après appel)

Garde : Acte 0 (15 s) + Acte 3 complet + Acte 4 complet + Séquences 5.3, 5.5, 6.2 + Acte 8.
Coupe : Actes 1, 2, 7 — le prospect qui a déjà parlé avec toi n'a plus besoin de la partie configuration.

---

# PARTIE 8 — Argumentaire : les 7 objections d'un salon

| Objection | Réponse | Preuve dans la vidéo |
|---|---|---|
| « J'ai déjà un logiciel de rendez-vous » | Un agenda ne gère ni les walk-ins, ni la file, ni la caisse, ni les pourboires, ni vos campagnes. Ici tout est relié. | Actes 4, 5, 6 |
| « C'est trop compliqué pour mon équipe » | Sophie ne voit que 4 boutons : Check-in, Appeler, Démarrer, Terminer. | Séquence 4.4 |
| « Ça va me prendre des semaines à installer » | Le secteur Salon pré-crée vos rubriques. Le salon de la vidéo a été configuré en moins d'une heure. | Acte 1 |
| « Mes clientes ne réservent pas en ligne » | Elles ne le font pas parce que c'est mal fait. 40 secondes, sur mobile, sans créer de compte. | Séquence 3.2 |
| « Les no-shows, on n'y peut rien » | Rappels J-1 et H-2 + frais d'absence + liste d'attente. La politique d'acompte est prête, mais son encaissement ne sera montré qu'après validation Stripe E2E. | Séquences 2.3, 3.3, 3.5 |
| « Les pourboires, c'est entre l'équipe et moi » | Justement : la répartition est automatique et transparente. Chacun voit les siens. | Séquence 5.3 |
| « Combien ça coûte ? » | Un balayage no-show, c'est 210 $. Deux évités par mois paient l'abonnement. | Séquence 2.3 |

---

# Annexe — Table des URL

| Écran | URL |
|---|---|
| Onboarding | `/onboarding` |
| Tableau de bord | `/dashboard` |
| Hub (Revenus, Croissance, Opérations, Finance, Catalogue, Espace) | `/workspace-hubs/{revenue\|growth\|operations\|finance\|catalog\|workspace}` |
| Clients | `/customer` |
| Prestations | `/service` · Rubriques `/services/categories` |
| Produits | `/product` |
| Packs / Forfaits | `/offer-packages` |
| Réservations | `/app/reservations` |
| Écran live (+ bouton kiosque) | `/app/reservations/screen` |
| Paramètres réservations | `/settings/reservations` |
| Réservation publique | `/book/{entreprise}/{slug}` |
| Vitrine publique | `/services/{slug}` |
| Planning | `/planning` |
| Présence | `/presence` |
| Équipe | `/team` |
| Factures | `/invoices` |
| Ventes / POS | `/sales` · Nouvelle vente `/sales/create` |
| Promotions | `/promotions` |
| Pourboires — propriétaire | `/payments/tips` |
| Pourboires — membre | `/my-earnings/tips` |
| Fidélité | `/loyalty` · Réglages `/settings/loyalty` |
| Paliers VIP | `/marketing/vip-tiers` |
| Segments | `/marketing/segments` · Listes `/marketing/mailing-lists` · Templates `/marketing/templates` |
| Campagnes | `/campaigns` · Automatisations `/campaign-automations` |
| Malikia Pulse | `/social` |
| Dépenses | `/expenses` |
| Comptabilité | `/accounting` |
| Performance | `/performance` |
| Paramètres entreprise | `/settings/company` |
| Rôles & permissions | `/settings/roles-permissions` |
| Facturation plateforme | `/settings/billing` |
| Assistant IA — réglages | `/admin/ai-assistant/settings` |
| Assistant IA — connaissances | `/admin/ai-assistant/knowledge` |
| Assistant IA — conversations | `/admin/ai-assistant/conversations` |
| Assistant IA public | `/public/ai-assistant/{entreprise}` |
| Super Admin — Espaces de démo | `/super-admin/demo-workspaces` |

**Côté client (portail)**

| Écran | URL |
|---|---|
| Mes réservations | `/client/reservations` |
| Réserver | `/client/reservations/book` |
| Mes forfaits | `/portal/packages` |
| Mes points fidélité | `/portal/loyalty` |
| Mes commandes | `/portal/orders` |
