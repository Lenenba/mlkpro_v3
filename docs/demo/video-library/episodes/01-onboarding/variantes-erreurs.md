# G01 — Variantes, erreurs et décisions

Dernière mise à jour : 2026-08-11

Ce fichier prépare les branches qui ne doivent pas être improvisées pendant le parcours principal de Salon Éclat.

## Arbre de décision

```text
La personne est-elle connectée ?
├── Non → Étape Compte
│   ├── Compte existant → Se connecter
│   └── Nouveau propriétaire → Créer le compte → retour à l'onboarding
└── Oui
    ├── Propriétaire → Six étapes de configuration
    └── Membre → Écran d'attente du propriétaire

Combien de personnes utiliseront l'espace ?
├── Une seule → Plans Solo possibles, aucune invitation
└── Deux ou plus → Plans Team
    └── Invitations facultatives maintenant, possibles plus tard

Stripe est-il prêt et requis ?
├── Oui → Checkout externe
│   ├── Succès vérifié → finalisation → challenge 2FA
│   └── Annulation ou erreur → retour onboarding incomplet
└── Non → finalisation directe → challenge 2FA
```

## Variante 1 — Propriétaire déjà connecté

Un propriétaire dont l'onboarding n'est pas terminé voit directement six étapes. Ses données de compte déjà présentes préremplissent l'entreprise, la devise, le type, le secteur, la taille et la méthode 2FA.

Cette branche est utile pour le support, mais elle ne montre pas la création du compte. Si elle sert à une capsule, annoncer explicitement : « Je suis déjà connecté comme propriétaire. »

## Variante 2 — Membre en attente

G01-S18 utilise un compte membre rattaché à un espace dont le propriétaire n'a pas terminé.

Ce qui doit être visible :

- titre **Espace en cours de configuration** ;
- explication demandant au propriétaire de finaliser ;
- actions Se déconnecter et Tableau de bord ;
- aucun bouton permettant de soumettre l'onboarding.

Ne pas confondre cette branche avec un bug de permissions. L'onboarding de l'entreprise appartient au propriétaire.

## Variante 3 — Espace Solo

| Élément | Valeur Solo sûre |
| --- | --- |
| Taille prévue | 1 |
| Invitation | Aucune |
| Audience affichée | Solo |
| Quantité facturable | 1 |

Si une invitation existe, si la taille dépasse 1 ou si un membre actif est déjà rattaché, le serveur refuse le plan propriétaire uniquement. Pour passer réellement en Solo, il faut résoudre ces trois conditions ; changer seulement la carte du plan ne suffit pas.

## Variante 4 — Invitations appliquées pendant l'onboarding

Cette branche n'est pas soumise dans le master.

| Rôle visible | Effet général | Limite à expliquer |
| --- | --- | --- |
| Admin | Permissions par défaut plus larges | Toujours filtrées par les modules actifs. |
| Membre | Permissions opérationnelles plus restreintes | Ne devient pas propriétaire. |

À la finalisation, le comportement courant crée directement le compte et affiche un mot de passe temporaire dans le message de succès. La production doit donc :

1. utiliser uniquement un domaine de test ;
2. ne jamais capturer le message contenant les identifiants ;
3. déplacer ces identifiants vers un canal sécurisé si cette branche est réellement testée ;
4. préférer `G07 — Ajouter un membre` pour une explication publiable.

## Variante 5 — Entreprise de produits

Le type Produits remplace la liste de secteurs Services par les secteurs Détail, Grossiste, Épicerie, Dépanneur, Boutique spécialisée, Pharmacie/parapharmacie, Électronique et Maison/quincaillerie. La soumission active le module Ventes s'il n'a pas encore de valeur dans les fonctions de l'entreprise.

Ne pas annoncer la création des cinq catégories Salon dans cette branche : elle appartient uniquement au scénario Services + Salon.

## Variante 6 — Méthode Application d'authentification

G01-S21 montre seulement le choix visuel. Sur un nouveau compte :

- l'onboarding enregistre `app` comme préférence ;
- aucun QR ni secret TOTP n'est créé ;
- au prochain challenge, l'absence de secret fait utiliser Email comme méthode effective ;
- la méthode App devient réellement vérifiable seulement après une configuration dédiée.

Formulation sûre : « Je peux choisir cette préférence, puis terminer la configuration de l'application dans les paramètres de sécurité. »

Formulation interdite : « L'application d'authentification est maintenant activée. »

## Erreurs utiles à montrer ou à préparer

| Symptôme | Cause réelle | Correction utilisateur | Message pédagogique |
| --- | --- | --- | --- |
| Le compte n'est pas créé | Nom ou email vide, email déjà utilisé, mot de passe invalide ou confirmation différente | Corriger le champ au même écran | Le compte propriétaire doit être unique avant la configuration de l'entreprise. |
| Erreur sur Nom de l'entreprise | Champ vide | Revenir à Entreprise et saisir le nom | Continuer n'avait pas enregistré l'étape. |
| Logo refusé | Fichier non reconnu comme image ou supérieur à 2 048 Ko | Utiliser un fichier compatible plus léger ou aucun logo | Le logo est optionnel. |
| Recherche d'adresse indisponible | Clé Geoapify absente ou requête échouée | Ouvrir la saisie manuelle | Ville, région et pays suffisent à terminer l'onboarding. |
| Erreur de secteur | Aucune option ou texte Autre vide | Choisir Salon ou compléter Autre | Le secteur est obligatoire. |
| Taille d'équipe refusée | Valeur < 1, > 5 000 ou non entière | Utiliser un entier plausible | La taille oriente le plan ; elle n'est pas un texte libre. |
| Invitation refusée | Nom/email manquant, email dupliqué dans le lot ou déjà utilisé, rôle invalide, plus de 20 lignes | Corriger ou retirer la ligne | Une invitation prépare un vrai compte. |
| Plan Solo refusé | Taille > 1, invitation présente ou membre actif | Choisir Team ou ramener réellement l'espace à une seule personne | Solo signifie propriétaire uniquement. |
| Carte de plan indisponible | Prix absent pour la devise/période | Choisir une carte configurée ou corriger le catalogue avant le tournage | Ne pas cliquer sur une carte grisée. |
| Conditions refusées | Case non cochée au premier onboarding | Ouvrir les conditions, les accepter et resoumettre | Le bouton final n'intègre pas cette condition dans son état désactivé ; le serveur la contrôle. |
| Facturation non configurée | Checkout requis, mais fournisseur non prêt au moment du démarrage | Corriger l'environnement | Ne pas transformer une erreur de configuration en parcours client. |
| Checkout annulé | Retour `status=cancel` | Revenir au plan et relancer lorsque la décision est prise | L'onboarding reste incomplet. |
| Session Stripe manquante | Retour succès sans `session_id` | Refaire un checkout valide | Une URL bricolée ne prouve pas un succès. |
| Synchronisation échouée | La session Stripe ne peut pas être synchronisée | Vérifier Stripe et la session, puis reprendre | Ne pas afficher le tableau de bord comme si l'abonnement était confirmé. |
| Challenge email non livré | Service de notification indisponible | Réparer la boîte de test ; le système peut déconnecter le compte | Aucun code ne doit être inventé. |
| Code invalide ou expiré | Mauvais code ou délai de 10 minutes dépassé | Demander un nouveau code après le délai autorisé | La 2FA est une vraie étape, pas un écran décoratif. |

## Erreur choisie pour l'annexe

G01-S19 montre l'incompatibilité Solo/Team, car elle relie directement une décision métier à la validation du plan.

1. Utiliser un compte incomplet isolé.
2. Saisir une taille de 2 ou ajouter une invitation de test.
3. Envoyer volontairement `solo_pro` par un état d'interface qui le permet ou un fixture préparé.
4. Capturer l'erreur sur le plan.
5. Vérifier que `onboarding_completed_at` est toujours vide.
6. Ne pas réutiliser ce compte pour le master principal.

L'interface filtre normalement les cartes par audience et peut retirer automatiquement le plan Solo sélectionné lorsque la taille passe à Team. L'annexe doit donc être préparée en préproduction et ne doit pas simuler un message qui n'apparaît pas dans l'écran courant.

## Erreurs de navigation à anticiper

Le formulaire ne déplace pas automatiquement l'utilisateur vers la première étape en erreur. Par exemple, une soumission depuis Sécurité peut échouer sur le nom, le secteur ou les conditions placés plus tôt.

Procédure sûre :

1. lire le nom de la clé d'erreur dans la préproduction ;
2. revenir avec la colonne de progression à l'étape correspondante ;
3. corriger sans ressaisir aveuglément toutes les cartes ;
4. refaire la capture uniquement lorsque l'erreur et sa correction sont compréhensibles.

## Plans de secours de production

| Incident | Reprise sûre |
| --- | --- |
| L'adresse Amina existe déjà | Préparer une nouvelle adresse example.test canonique et la remplacer dans tous les fichiers avant de tourner ; ne pas improviser pendant la prise. |
| Les boutons sociaux diffèrent | Utiliser le formulaire courriel et expliquer que seuls les fournisseurs prêts sont affichés. |
| Geoapify ne répond pas | Passer immédiatement à Montréal, Québec, Canada en saisie manuelle. |
| Le plan Team Core n'a pas de prix | Corriger le catalogue test ou mettre à jour tout le scénario avec un plan Team réellement configuré. |
| Stripe test n'est pas disponible | Produire une version sans preuve Stripe et annoncer clairement la finalisation directe de l'environnement. |
| Le retour Stripe montre un identifiant dans l'URL | Recadrer après navigation ou masquer toute la barre d'adresse ; ne pas publier le jeton. |
| Le courriel 2FA n'arrive pas | Interrompre la prise, réparer la livraison et demander un nouveau code ; ne pas utiliser la base de données pour fabriquer un succès. |
| Une invitation est restée dans le formulaire | Ne pas finaliser. Revenir à Configuration, supprimer la ligne et vérifier de nouveau. |
| Le compte est déjà finalisé | Ne pas tenter de “réinitialiser” un espace partagé ; repartir d'un compte jetable neuf. |

## Anomalies produit à ne pas transformer en instructions

- Le libellé de devise et son aide sont en anglais dans la page française.
- La carte Adresse validée montre rue et code postal, mais le serveur ne persiste ici que ville, province/région et pays.
- Les boutons Continuer et la navigation latérale ne valident pas chaque étape.
- Le bouton final est désactivé sans plan ou sans méthode 2FA, mais pas sans acceptation des conditions ; l'erreur vient alors du serveur.
- La préférence App n'installe pas le secret TOTP.
- Les comptes d'équipe créés à la finalisation produisent des mots de passe temporaires dans le message de succès.
- Une validation tardive du plan peut survenir après l'enregistrement de certaines données d'entreprise ; ne pas affirmer que toute la soumission a été annulée champ par champ.

Une anomalie peut être expliquée ou exclue du cadrage, mais jamais racontée comme un comportement garanti différent de celui observé.
