# G01 — Guide exhaustif des étapes de l'onboarding

Dernière mise à jour : 2026-08-11<br>
Route auditée : `/onboarding`<br>
Contexte principal : nouveau compte propriétaire, entreprise de services, secteur Salon

Ce guide décrit l'interface et les effets réellement observables dans le code actuel. Il sert au master et à la préparation des captures ; la capsule courte ne doit pas réciter toutes les règles.

## Vue d'ensemble

| État de session | Nombre d'étapes | Première étape | Particularité |
| --- | --- | --- | --- |
| Invité | 7 | Compte | Les étapes suivantes sont visibles, mais désactivées. |
| Propriétaire authentifié | 6 | Entreprise | Les valeurs existantes sont utilisées comme preset. |
| Membre authentifié | Écran séparé | Aucune | Message d'attente du propriétaire. |

Pour un propriétaire authentifié, les boutons de la colonne de progression permettent d'aller directement à une étape. **Continuer** et **Retour** changent seulement l'étape locale ; ils n'enregistrent pas la carte affichée et ne déclenchent pas sa validation serveur.

## 1. Compte — invité uniquement

Deux voies peuvent apparaître : fournisseurs sociaux réellement activés et configurés, ou formulaire courriel.

### Formulaire courriel

| Champ | Règle serveur | Valeur G01 |
| --- | --- | --- |
| Nom complet | Obligatoire, texte, 255 caractères maximum | Amina Diallo |
| Email | Obligatoire, minuscules, format email, 255 caractères maximum, unique dans Utilisateurs | `amina.diallo.onboarding@example.test` |
| Mot de passe | Obligatoire, politique `Password::defaults()` courante | Secret local |
| Confirmer le mot de passe | Doit correspondre exactement | Secret local |

La soumission crée un utilisateur de rôle `owner`, l'authentifie et retourne sur `/onboarding`. Si le parcours venait d'une page de prix, le plan et la période valides sont conservés dans la redirection.

Les boutons sociaux ne sont visibles que pour les fournisseurs globalement activés, configurés, implémentés et autorisés dans le contexte onboarding. Il ne faut pas annoncer Google, Microsoft, Facebook ou LinkedIn si leurs boutons ne sont pas présents le jour du tournage.

## 2. Entreprise

### Identité

| Champ | Règle serveur | Subtilité de démonstration |
| --- | --- | --- |
| Nom de l'entreprise | Obligatoire, texte, 255 caractères maximum | Valeur structurante : Salon Éclat. |
| Logo | Optionnel, image, 2 048 Ko maximum | Laisser vide si aucun asset publié n'est prêt. |
| Description | Optionnelle, texte, 2 000 caractères maximum | Présenter l'activité sans données personnelles. |
| Devise principale | Optionnelle ; CAD, EUR ou USD | CAD par défaut et dans G01. |

Le libellé de la devise est actuellement affiché en anglais (`Main business currency`) dans l'interface française. La narration peut dire **Devise principale**, mais la capture brute doit conserver le texte réel.

### Adresse

La recherche Geoapify démarre après deux caractères avec une temporisation de 350 ms. Elle cherche d'abord dans Canada, États-Unis, France, Belgique, Suisse, Maroc et Tunisie, puis retente sans ce filtre si aucun résultat n'est trouvé.

| Valeur visible | Utilisée par la carte “Adresse validée” | Envoyée et persistée par l'onboarding |
| --- | --- | --- |
| Adresse formatée | Oui | Non |
| Rue | Oui | Non |
| Code postal | Oui | Non |
| Ville | Oui | Oui |
| Province / région | Oui | Oui |
| Pays | Oui | Oui |

La saisie manuelle expose seulement Ville, Province/Région et Pays. Si la clé Geoapify manque ou si la recherche échoue, l'interface ouvre cette branche et affiche une erreur ; la configuration de l'entreprise reste possible.

## 3. Type d'entreprise

| Choix | Effet réel au moment de la soumission | Usage dans G01 |
| --- | --- | --- |
| Entreprise de services | Prépare des catégories à partir du secteur. | Oui |
| Entreprise de produits | Active `sales` dans les fonctions de l'entreprise si la clé n'existe pas. | Variante uniquement |

Le choix est obligatoire. La carte « Modules actifs » reformule le choix, mais elle ne constitue pas à elle seule la liste exhaustive des modules du forfait.

## 4. Secteur

Le secteur est obligatoire et limité à 255 caractères. Les options visibles dépendent du type.

### Secteurs Services proposés

- Salon de coiffure / beauté
- Restaurant
- Services généraux
- Menuiserie
- Plomberie
- Électricité
- Peinture
- Toiture
- Rénovation
- Paysagisme
- Climatisation
- Nettoyage
- Autre

Avec le secteur `salon`, la finalisation prépare exactement :

- Coupe ;
- Coloration ;
- Coiffage ;
- Soin capillaire ;
- Barbier.

La branche **Autre** accepte un texte libre. Pour un type Services, le serveur peut tenter de découvrir jusqu'à trois intitulés via Wikipédia, puis ajoute le secteur saisi et les catégories génériques Installation, Entretien, Réparation, Conseil et Autres. Cette branche dépend d'un service externe et n'est pas utilisée dans le master.

### Secteurs Produits proposés

Détail, Grossiste, Épicerie, Dépanneur, Boutique spécialisée, Pharmacie/parapharmacie, Électronique, Maison et quincaillerie, ou Autre.

## 5. Configuration Solo ou Team

### Taille prévue

| Saisie | Interprétation d'interface | Effet sur les plans |
| --- | --- | --- |
| 1 ou vide sans invitation | Solo | Affiche en priorité les plans Solo. |
| 2 à 5 000 | Team | Affiche en priorité les plans Team. |
| Invitations présentes | Team visible même si l'état a commencé en solo | Une soumission Solo devient incompatible. |

La validation serveur accepte un entier de 1 à 5 000. Si le champ est vide, l'interface estime la taille à `nombre d'invitations + 1`, minimum 1.

### Invitations

| Élément | Règle |
| --- | --- |
| Nombre | 20 invitations maximum |
| Nom | Obligatoire, 255 caractères maximum |
| Email | Obligatoire, minuscules, valide, 255 caractères maximum, distinct dans le lot et unique dans Utilisateurs |
| Rôle | `admin` ou `member` |

Les invitations ne sont pas appliquées au clic **Continuer**. Elles restent dans le formulaire, puis sont stockées en session lorsque la soumission lance le checkout. Elles sont matérialisées seulement lors d'une finalisation réussie.

Le comportement actuel de finalisation est précis :

1. un compte Utilisateur de rôle technique `employee` est créé ;
2. son email est marqué vérifié ;
3. un mot de passe aléatoire de 14 caractères est généré ;
4. une adhésion active `admin` ou `member` est créée ;
5. les permissions par défaut sont filtrées par les modules actifs ;
6. les identifiants temporaires sont ajoutés au message de succès.

Ce flux ne doit pas être décrit comme un simple courriel d'invitation. Pour éviter de révéler des mots de passe temporaires, le master G01 retire toute ligne avant la soumission.

## 6. Forfait et facturation

### Audience et cartes

Les six clés actuellement autorisées dans l'onboarding sont :

| Audience Solo | Audience Team |
| --- | --- |
| `solo_essential` — Solo Core | `starter` — Team Core |
| `solo_pro` — Solo Growth | `growth` — Team Growth |
| `solo_growth` — Solo Scale | `scale` — Team Scale |

Une carte ne peut être sélectionnée que si le prix Stripe existe pour la devise et la période, ou si le plan est de type « contacter ». Les plans d'onboarding actuels sont tarifés ; une carte sans prix configuré apparaît indisponible.

La recommandation Solo privilégie la carte marquée recommandée, puis un plan propriétaire uniquement. La recommandation Team cherche d'abord une capacité suffisante pour la taille prévue, puis utilise la dernière carte disponible si aucune limite ne convient.

### Règles Solo

Un plan propriétaire uniquement est refusé si au moins une de ces conditions est vraie :

- un membre d'équipe actif existe déjà ;
- le formulaire contient une invitation ;
- la taille déclarée est supérieure à 1.

Le nombre facturable d'un plan Solo est toujours 1. Pour un plan Team, il correspond au maximum entre 1, la taille déclarée et le nombre de membres actifs.

### Période, devise, essai et conditions

| Élément | Valeurs ou règle |
| --- | --- |
| Période | Mensuel ou Annuel ; Mensuel par défaut |
| Devise | CAD, EUR ou USD selon le choix Entreprise et les prix disponibles |
| Essai Stripe | Un mois sans dépassement de fin de mois |
| Conditions | Acceptation obligatoire lors du premier onboarding |

La date d'essai et les promotions sont calculées au moment de l'affichage. Les montants ne doivent pas être figés dans la narration ou l'incrustation.

### Quand le checkout s'ouvre

Le checkout est requis seulement lorsque le fournisseur effectif est Stripe **et** que la configuration est prête.

```text
Stripe prêt
└── Démarrer l'essai → Stripe Checkout test
    ├── Succès + session_id → synchronisation → finalisation
    └── Annulation → retour onboarding, non terminé

Stripe non prêt ou fournisseur non-Stripe
└── Finalisation directe dans l'application
```

Une finalisation directe ne prouve ni la présence d'une carte ni un paiement. Si Stripe est annoncé dans la vidéo, la session de test et son retour doivent être réels.

## 7. Sécurité 2FA

| Choix visible | Ce que l'onboarding enregistre | Ce que cela ne fait pas |
| --- | --- | --- |
| Code par email | Méthode `email`, anciens codes effacés, secret TOTP effacé | N'envoie pas le code avant la finalisation. |
| Application d'authentification | Méthode `app`, anciens codes effacés | Ne génère ni secret, ni QR, ni codes de secours. |

Pour un nouveau compte, **Application** sans secret TOTP revient à la méthode effective Email lors du challenge. G01 utilise donc Email. Une future vidéo Sécurité doit montrer la configuration complète avant de recommander l'application.

Avant la finalisation, un propriétaire incomplet est exempté de 2FA afin de pouvoir terminer l'onboarding. Dès que `onboarding_completed_at` est défini, le prochain accès protégé exige la 2FA :

- code email de 6 chiffres ;
- expiration après 10 minutes ;
- renvoi avec délai minimum de 30 secondes ;
- blocage de vérification après 5 tentatives rapprochées ;
- limite de renvoi après 3 tentatives rapprochées.

Le code n'est jamais capturé dans G01.

## 8. Soumission et sorties

### Première finalisation réussie

Le serveur :

1. force le rôle owner si nécessaire ;
2. enregistre l'entreprise et la méthode 2FA ;
3. prépare les catégories Services ou active Ventes pour Produits ;
4. valide la compatibilité du forfait ;
5. conserve le plan et la période ;
6. lance le checkout si requis ;
7. après succès, applique les invitations de session ;
8. définit `onboarding_completed_at` ;
9. envoie la notification de bienvenue au propriétaire ;
10. notifie l'administration de plateforme ;
11. redirige vers le tableau de bord, lequel est intercepté par le challenge 2FA.

### Propriétaire déjà onboardé

Une nouvelle soumission met à jour les données, efface les invitations conservées en session et redirige vers le tableau de bord. Les conditions, le plan et la méthode 2FA deviennent optionnels côté serveur ; le checkout initial n'est pas relancé.

### Membre non propriétaire

Le GET montre `Onboarding/PendingOwner`. Une tentative de POST web renvoie au tableau de bord avec le message « Seul le propriétaire du compte peut terminer l'onboarding ». L'API renvoie un statut 403.

## Validation complète du formulaire entreprise

| Champ | Règle |
| --- | --- |
| `company_name` | Requis, texte, max. 255 |
| `company_logo` | Optionnel, image, max. 2 048 Ko |
| `company_description` | Optionnel, texte, max. 2 000 |
| `company_country` | Optionnel, texte, max. 255 |
| `company_province` | Optionnel, texte, max. 255 |
| `company_city` | Optionnel, texte, max. 255 |
| `currency_code` | Optionnel, CAD/EUR/USD |
| `company_type` | Requis, Services ou Produits |
| `company_sector` | Requis, texte, max. 255 |
| `company_team_size` | Optionnel, entier entre 1 et 5 000 |
| `plan_key` | Plan autorisé ; requis dans le parcours Stripe initial |
| `billing_period` | Optionnel, Mensuel ou Annuel |
| `accept_terms` | Accepté lors du premier onboarding |
| `two_factor_method` | Email ou App ; requis sur le web lors du parcours Stripe initial |

## Sources de vérité dans le dépôt

- Interface : `resources/js/Pages/Onboarding/Index.vue`
- Écran membre : `resources/js/Pages/Onboarding/PendingOwner.vue`
- Traductions : `resources/js/i18n/modules/fr/onboarding.json`
- Validation, effets et redirections : `app/Http/Controllers/OnboardingController.php`
- Création du compte : `app/Http/Controllers/Auth/RegisteredUserController.php`
- Redirection après connexion : `app/Services/Auth/WebLoginResponseService.php`
- Exigence 2FA : `app/Models/User.php` et `app/Http/Middleware/EnsureTwoFactorVerified.php`
- Challenge 2FA : `app/Http/Controllers/Auth/TwoFactorController.php`
- Codes et délais : `app/Services/TwoFactorService.php`
- Compatibilité Solo/Team : `app/Services/BillingSubscriptionService.php`
- Catalogue des plans : `config/billing.php` et `app/Services/BillingPlanService.php`
- Tests de référence : `tests/Feature/OnboardingWebTest.php`, `OnboardingApiTest.php`, `OnboardingBillingApiTest.php` et `OnboardingInvitePermissionModulesTest.php`
