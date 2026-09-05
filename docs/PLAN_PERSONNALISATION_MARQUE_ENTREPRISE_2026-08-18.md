# Personnalisation de la marque entreprise - plan d'implémentation

Dernière mise à jour : 2026-08-18

Statut : lots prioritaire et complémentaire implémentés et validés

Estimation du lot prioritaire : 2 à 3 jours ouvrables pour un développeur

Estimation avec couverture exhaustive des surfaces existantes : 3 à 5 jours ouvrables

## 1. Objectif

Faire de l'entreprise cliente la marque principale dans son propre espace, tout en conservant une présence discrète et cohérente de Malikia Pro.

Le résultat attendu est le suivant :

- le logo de l'entreprise, accompagné de son nom lorsque l'espace le permet, domine dans son espace connecté, ses pages publiques, ses courriels et ses documents;
- Malikia Pro reste visible sous la forme secondaire « Propulsé par Malikia Pro » lorsque cette attribution est pertinente;
- Malikia Pro demeure la marque principale sur le site marketing, dans le superadmin et sur les pages qui ne disposent d'aucun contexte d'entreprise;
- sur le web, l'absence ou l'échec de chargement d'un logo ne produit jamais une image cassée;
- dans les courriels et PDF, le nom de l'entreprise reste lisible si une image distante est bloquée ou indisponible.

Ce document décrit d'abord le lot recommandé de 2 à 3 jours. La marque blanche complète est traitée séparément comme une évolution ultérieure.

## 2. Décision de produit

### 2.1 Hiérarchie de marque

Dans un contexte d'entreprise connu :

1. afficher d'abord le logo et le nom de l'entreprise;
2. afficher Malikia Pro uniquement selon la règle déterministe de la matrice de la section 5;
3. utiliser le logo Malikia Pro comme solution de remplacement si aucun vrai logo d'entreprise n'est disponible.

Dans un contexte plateforme ou sans entreprise connue :

1. conserver le logo Malikia Pro comme marque principale;
2. ne pas tenter de deviner l'entreprise à partir d'une donnée non fiable.

### 2.2 Contextes qui restent entièrement Malikia Pro

- site marketing public;
- superadmin;
- administration de la plateforme;
- pages légales de la plateforme;
- connexion et inscription génériques sans URL d'entreprise;
- noms de produits tels que « Malikia Pulse » et « Malikia AI Assistant ».

### 2.3 Authentification et contexte d'entreprise

Un écran d'authentification n'est personnalisé que si l'entreprise peut être résolue à partir d'un contexte serveur fiable :

- la vérification et la confirmation authentifiées peuvent utiliser l'entreprise du compte;
- le challenge 2FA utilise le compte authentifié fourni par le serveur, puis le résolveur tenant commun;
- un courriel de réinitialisation peut utiliser l'entreprise résolue pour son destinataire;
- le formulaire de réinitialisation reste sous la marque Malikia Pro sauf s'il reçoit plus tard un contexte tenant signé;
- la connexion, l'inscription et le mot de passe oublié génériques restent sous la marque Malikia Pro.

La marque ne doit jamais être déduite d'un simple paramètre `email`, d'un slug non vérifié ou d'une donnée fournie uniquement par le navigateur.

### 2.4 Impersonation

Lorsqu'un superadmin consulte un tenant en impersonation :

- la marque de l'entreprise est affichée pour reproduire fidèlement son expérience;
- la bannière d'impersonation Malikia Pro reste présente sur toutes les pages pendant l'impersonation, sans exiger qu'elle soit fixe pendant le défilement;
- la sortie d'impersonation demeure immédiatement accessible;
- ce contexte doit être couvert par les tests d'isolation tenant.

## 3. État actuel du code

Le socle nécessaire existe déjà :

- le logo est enregistré dans `users.company_logo`;
- le propriétaire peut le téléverser pendant l'onboarding et dans les paramètres de l'entreprise;
- le logo du propriétaire du compte est partagé au frontend dans `auth.account.company.logo_url`;
- plusieurs pages publiques et pages du portail l'utilisent déjà;
- les modèles principaux de facture et de commande affichent déjà le logo de l'entreprise;
- le layout des courriels sait déjà recevoir et afficher ce logo.

Les principaux écarts sont les suivants :

- la barre latérale authentifiée utilise encore le logo Malikia Pro codé en dur;
- plusieurs pages publiques affichent le logo Malikia Pro au-dessus de la page et le logo de l'entreprise une deuxième fois dans la carte;
- les courriels donnent encore beaucoup de place à Malikia Pro dans leur en-tête;
- certains courriels d'authentification ou de fournisseur ne reçoivent pas le contexte de l'entreprise;
- le reçu de vente n'affiche pas encore le logo de l'entreprise;
- l'accesseur `company_logo_url` retourne actuellement `customers/customer.png` même lorsqu'aucun logo personnalisé n'a été ajouté;
- ce fichier de fallback legacy ne doit pas être considéré comme une preuve qu'un vrai logo d'entreprise existe;
- la résolution du propriétaire est actuellement répartie entre plusieurs chemins pour les employés et les clients portail.

## 4. Périmètre du premier lot

Le lot prioritaire de 2 à 3 jours comprend :

- un contrat de données fiable pour distinguer un vrai logo d'entreprise d'un fallback;
- la barre latérale de l'espace connecté;
- les pages publiques qui présentent actuellement un double branding via `GuestLayout`;
- les écrans d'authentification pour lesquels l'entreprise est déjà connue;
- le layout partagé des principaux courriels transactionnels liés à une entreprise;
- la vérification des factures et commandes PDF ainsi que l'ajout du logo au reçu de vente;
- le comportement de fallback;
- les tests automatisés et la vérification visuelle sur ordinateur et mobile.

La couverture exhaustive ajoute ensuite 1 à 2 jours pour inventorier et corriger toutes les autres pages publiques, les vues du portail, les courriels autonomes et leurs tests. Le total réaliste pour l'ensemble des surfaces existantes est donc de 3 à 5 jours.

Le lot prioritaire ne comprend pas :

- les couleurs globales personnalisées dans toute l'application;
- un favicon ou un manifeste PWA dynamique par entreprise;
- un domaine personnalisé;
- un expéditeur de courriel personnalisé;
- une page de connexion personnalisée sans slug ou domaine d'entreprise;
- la facturation d'une option « marque blanche »;
- la suppression des noms de produits Malikia.

### 4.1 État de livraison au 18 août 2026

Le lot prioritaire est implémenté sur `develop` :

- résolveur tenant unique pour le propriétaire, les employés et les clients portail;
- contrat nullable `logo_url`, `custom_logo_url` et `has_custom_logo` dans les payloads concernés;
- logo entreprise dans la barre latérale, les pages publiques prioritaires, la réservation publique, le chat public et l'authentification contextualisée;
- fallback navigateur sans image cassée, nom d'entreprise visible et ratio conservé;
- courriels transactionnels rééquilibrés avec une seule attribution Malikia Pro;
- factures, commandes et reçus harmonisés;
- protection du formulaire public contre un fallback vers un tenant arbitraire;
- couverture automatisée des rôles, des fallbacks, des pages publiques, des courriels et des documents.

Preuves de validation du lot :

- gate `composer qa:format` vert après indexation complète des fichiers PHP;
- PHPStan complet : 867 fichiers analysés, aucune erreur;
- suite PHP ciblée : 88 tests et 742 assertions réussis;
- suite Node : 63 tests réussis;
- build Vite : 2 623 modules compilés;
- budgets frontend : verts;
- test E2E branding : vert, avec contrôle visuel ordinateur, mobile et barre latérale;
- PDF réel généré et inspecté : logo net, ratio conservé, aucun chevauchement.

Le lot complémentaire de la section 9 a ensuite uniformisé les surfaces historiques hors du périmètre prioritaire.

### 4.2 État du lot complémentaire au 18 août 2026

Le lot complémentaire est implémenté sur `develop` :

- boutique, vitrine et borne de réservation publique migrées vers le composant de marque commun;
- logo de la boutique relié à sa propre route tenant, sans retour involontaire vers la plateforme;
- portail client et écrans commerciaux historiques harmonisés;
- accès directs au logo tenant supprimés du frontend en dehors du résolveur commun;
- payloads backend normalisés et isolation renforcée entre le client, le document et le propriétaire du tenant;
- courriels classés explicitement entre marque entreprise et marque plateforme;
- courriel d'accès à une démo conservant la marque du profil prospect avec une attribution Malikia Pro unique;
- priorité du `BrandProfile` marketing conservée pour les campagnes;
- compatibilité des anciennes notifications sérialisées protégée;
- scénarios Playwright ajoutés pour le logo inaccessible, l'isolation entre deux tenants et le lien de marque de la boutique.

Preuves de validation du lot complémentaire :

- suite PHP consolidée : 105 tests et 1 210 assertions réussis;
- suite Node complète : 66 tests réussis;
- suite Playwright complémentaire : 3 scénarios réussis;
- tests de contexte couvrant deux tenants, l'impersonation réelle, le portail, l'authentification et le PDF de commande;
- revue croisée backend, frontend et courriels sans défaut applicatif de priorité P0 à P2 restant;
- `git diff --check` vert pendant les validations intermédiaires.

Les nouveaux scénarios Playwright sont verts avec Chromium et PHP 8.4. Ils couvrent la marque principale dans le workspace, le fallback réel lorsqu'un logo est inaccessible, l'isolation visuelle entre deux tenants et le lien de marque propre à la boutique.

## 5. Règles d'affichage

| Surface | Marque principale | Présence Malikia Pro | Fallback |
| --- | --- | --- | --- |
| Espace connecté d'une entreprise | Entreprise | Aucune attribution dans la barre compacte | Logo Malikia Pro |
| Espace client rattaché à une entreprise | Entreprise propriétaire | « Propulsé par Malikia Pro » | Logo Malikia Pro |
| Page publique d'une entreprise | Entreprise | « Propulsé par Malikia Pro » dans le bas de page | Logo Malikia Pro |
| Courriel envoyé au nom d'une entreprise | Entreprise | Une ligne discrète dans le pied de page | Logo Malikia Pro |
| PDF commercial | Entreprise | Aucune attribution dans le premier lot | Nom de l'entreprise si l'image est indisponible |
| Tenant consulté en impersonation | Entreprise | Bannière Malikia Pro présente pendant toute l'impersonation | Logo Malikia Pro |
| Superadmin ou administration plateforme | Malikia Pro | Marque principale | Sans objet |
| Connexion générique | Malikia Pro | Marque principale | Sans objet |
| Site marketing et pages légales | Malikia Pro | Marque principale | Sans objet |

## 6. Plan d'implémentation

### Étape 0 - Confirmer le comportement de référence

Estimation : environ 1 heure

Actions :

- préparer trois comptes de test : entreprise avec logo, entreprise sans logo et superadmin;
- vérifier également un propriétaire, un employé et un client portail rattachés à la même entreprise;
- capturer l'état actuel de la barre latérale, d'une page publique, d'un courriel et d'un PDF;
- confirmer que le logo à utiliser est toujours celui du propriétaire du workspace, jamais celui du membre d'équipe ou du client connecté.

Critère de sortie : les cas de référence sont identifiés et reproductibles avant toute modification.

### Étape 1 - Centraliser le branding et fiabiliser le contrat de données

Estimation : 3 à 4 heures

Fichiers principaux :

- `app/Models/User.php`;
- nouveau résolveur ou DTO PHP de branding tenant;
- `app/Http/Middleware/HandleInertiaRequests.php`;
- `app/Http/Controllers/Api/AuthController.php`;
- contrôleurs publics qui construisent manuellement une propriété `company`.

Actions :

- conserver `company_logo_url` pour éviter une rupture des consommateurs existants;
- centraliser côté PHP le nom, l'URL nullable du vrai logo et l'indicateur de logo personnalisé;
- ajouter une méthode commune qui résout le propriétaire pour un propriétaire, un employé ou un client portail;
- exposer au minimum `company.name`, `company.logo_url` et `company.has_custom_logo` dans Inertia et dans l'API concernée;
- ne pas considérer `customers/customer.png`, une chaîne vide ou une valeur invalide comme un logo personnalisé;
- résoudre systématiquement le propriétaire du compte pour les employés et les clients portail;
- réutiliser le même résolveur dans les contrôleurs, notifications, courriels et PDF au lieu de réimplémenter la détection;
- documenter la priorité des sources de logo.

Priorité recommandée pour le premier lot :

1. `users.company_logo` lorsqu'il contient un vrai logo;
2. aucun logo entreprise;
3. fallback visuel Malikia Pro dans le composant d'affichage.

Le logo du profil marketing reste réservé aux campagnes dans ce premier lot. Il ne doit pas devenir silencieusement une deuxième source globale contradictoire.

Critère de sortie : le backend fournit une source de vérité tenant unique et tous ses consommateurs peuvent distinguer sans ambiguïté « logo entreprise personnalisé » et « fallback plateforme ».

### Étape 2 - Créer un affichage de logo réutilisable

Estimation : 1 à 2 heures

Fichiers principaux :

- `resources/js/Components/ApplicationLogo.vue`;
- nouveau composant de marque entreprise, par exemple `resources/js/Components/CompanyBrandLogo.vue`;
- `resources/js/Layouts/GuestLayout.vue`.

Actions :

- garder `ApplicationLogo` réservé à la marque Malikia Pro;
- centraliser l'affichage du logo d'entreprise, son texte alternatif et son fallback;
- conserver le ratio du logo avec `object-contain`;
- placer le logo dans un conteneur neutre qui reste lisible en mode clair et sombre;
- gérer l'erreur de chargement dans le navigateur sans laisser d'icône cassée;
- accepter des dimensions adaptées à la barre latérale, aux pages publiques et aux en-têtes;
- utiliser le nom de l'entreprise dans le texte alternatif;
- permettre de désactiver le lien ou de fournir une destination tenant explicite.

Critère de sortie : les surfaces tenant utilisent une seule règle de rendu et ne réimplémentent pas chacune leur propre fallback.

### Étape 3 - Personnaliser l'espace connecté

Estimation : 1 à 2 heures

Fichier principal :

- `resources/js/Layouts/UI/Sidebar.vue`.

Actions :

- afficher le logo de l'entreprise pour le propriétaire, ses employés et ses clients;
- conserver le logo Malikia Pro pour le superadmin et les administrateurs plateforme;
- afficher le logo tenant tout en conservant la bannière d'impersonation existante sur chaque page lorsqu'un administrateur consulte ce tenant;
- utiliser Malikia Pro comme fallback lorsqu'aucun logo personnalisé n'existe;
- vérifier les modes clair et sombre;
- vérifier le rendu du logo dans la largeur compacte de la barre latérale;
- fournir un libellé accessible correspondant à la marque réellement affichée.

Critère de sortie : un utilisateur d'entreprise voit sa propre marque dès son entrée dans l'application, tandis qu'un administrateur plateforme voit Malikia Pro.

### Étape 4 - Corriger les pages publiques, clientes et l'authentification contextualisée

Estimation : 2 à 3 heures

Pages prioritaires :

- `resources/js/Pages/Public/InvoicePay.vue`;
- `resources/js/Pages/Public/QuoteAction.vue`;
- `resources/js/Pages/Public/WorkAction.vue`;
- `resources/js/Pages/Public/WorkProofs.vue`;
- `resources/js/Pages/Public/RequestForm.vue`;
- autres pages utilisant `GuestLayout` après audit final.

Surfaces à inclure dans l'audit de couverture exhaustive :

- `resources/js/Pages/Public/Store.vue`;
- `resources/js/Pages/Public/Showcase.vue`;
- `resources/js/Pages/Portal/InvoiceShow.vue`;
- `resources/js/Pages/Portal/Products/Shop.vue`;
- `resources/js/Pages/Portal/Products/OrderShow.vue`;
- tous les autres consommateurs de `company_logo_url`, `logo_url` ou `company.logo_url`.

Écrans d'authentification du lot prioritaire :

- `resources/js/Pages/Auth/VerifyEmail.vue`;
- `resources/js/Pages/Auth/ConfirmPassword.vue`;
- `resources/js/Pages/Auth/TwoFactorChallenge.vue`.

Actions :

- transmettre le logo et le nom de l'entreprise au `GuestLayout` lorsqu'ils sont connus;
- supprimer le double affichage « grand logo Malikia Pro + petit logo entreprise »;
- ne garder qu'un seul logo principal au-dessus ou dans l'en-tête de la carte;
- afficher une attribution « Propulsé par Malikia Pro » discrète dans le pied de page;
- désactiver le lien du logo si aucune URL publique tenant fiable n'est disponible;
- vérifier que les pages de réservation publique et de chat public, déjà partiellement personnalisées, restent cohérentes;
- personnaliser la vérification et la confirmation à partir du compte authentifié;
- personnaliser le challenge 2FA à partir du compte authentifié résolu côté serveur, en conservant Malikia Pro pour un superadmin ou un administrateur plateforme;
- laisser les pages d'authentification génériques sous la marque Malikia Pro.

Critère de sortie : chaque page publique liée à une entreprise présente une seule marque principale, celle de l'entreprise.

### Étape 5 - Rééquilibrer les courriels transactionnels

Estimation : 2 à 3 heures

Fichiers principaux :

- `resources/views/emails/layouts/base.blade.php`;
- `resources/views/emails/onboarding/welcome.blade.php`;
- notifications de réinitialisation, 2FA, invitation, facture, devis et demandes fournisseur;
- `lang/fr/mail.php`, `lang/en/mail.php` et `lang/es/mail.php`.

Audit complémentaire requis pour la couverture exhaustive :

- toutes les vues qui étendent `emails.layouts.base`;
- les vues autonomes telles que les approbations sociales et les envois groupés clients;
- les notifications qui utilisent directement le `MailMessage` Laravel;
- les courriels purement plateforme, qui doivent conserver Malikia Pro.

Actions :

- garder le logo et le nom de l'entreprise comme éléments dominants de l'en-tête;
- retirer le bloc Malikia Pro trop présent à droite de l'en-tête;
- conserver une seule mention « Propulsé par Malikia Pro » dans le pied de page;
- transmettre le bon contexte d'entreprise aux notifications lorsqu'il est disponible;
- conserver la marque Malikia Pro pour les messages purement plateforme;
- vérifier les trois langues prises en charge;
- fournir un texte alternatif et le nom de l'entreprise lorsque les images distantes sont bloquées;
- ne pas promettre un fallback d'image dynamique dans les clients de messagerie qui bloquent les images.

Critère de sortie : un destinataire identifie d'abord l'entreprise qui lui écrit et ne confond pas Malikia Pro avec l'expéditeur commercial.

### Étape 6 - Uniformiser les PDF et reçus

Estimation : 1 à 2 heures

Fichiers principaux :

- `resources/views/pdf/invoice.blade.php`;
- `resources/views/pdf/invoice-clean.blade.php`;
- `resources/views/pdf/invoice-minimal.blade.php`;
- `resources/views/pdf/order.blade.php`;
- `resources/views/pdf/sale-receipt.blade.php`.

Actions :

- confirmer le rendu existant du logo d'entreprise sur les trois factures et la commande;
- ajouter le logo au reçu de vente;
- empêcher la déformation des logos horizontaux, verticaux ou carrés;
- préférer une image locale ou une ressource validée par le serveur lorsque le moteur PDF l'exige;
- afficher proprement le nom de l'entreprise si l'image est absente ou inaccessible;
- vérifier la résolution et le poids des images pour la génération PDF.

Critère de sortie : les documents commerciaux utilisent tous la même identité d'entreprise sans altérer leur mise en page.

### Étape 7 - Ajouter les tests automatisés

Estimation : 3 à 4 heures

Tests du lot prioritaire à adapter ou compléter :

- `tests/Feature/AuthMeApiTest.php`;
- `tests/Feature/PublicBookingLinksTest.php`;
- `tests/Feature/AiAssistantPublicChatTest.php`;
- `tests/Feature/ReservationKioskPublicEndpointsTest.php`;
- `tests/Feature/InvoicePdfTest.php`;
- `tests/Feature/InvoiceSendEmailTest.php`;
- `tests/Feature/Auth/PasswordResetTest.php`;
- `tests/Feature/Auth/TwoFactorChallengeTest.php`;
- `tests/Feature/Auth/TwoFactorServiceTest.php`;
- `tests/Feature/SpanishLocaleSupportTest.php`;
- un test PHP à créer pour le branding du reçu de vente, par exemple `tests/Feature/SaleReceiptPdfTest.php`;
- un test frontend ou E2E dédié à la barre latérale et aux pages publiques prioritaires.

Tests réservés au lot complémentaire :

- un test PHP dédié au branding de la commande;
- des tests de couverture complète des pages publiques Quote/Work et des vues du portail;
- un scénario Playwright qui force réellement l'échec du chargement d'une image.

Cas minimum à couvrir :

- propriétaire avec logo;
- employé rattaché au propriétaire avec logo;
- client portail rattaché au propriétaire avec logo;
- entreprise sans logo;
- fallback du composant lorsque le logo déclenche une erreur dans le navigateur;
- superadmin et administrateur plateforme;
- page publique avec entreprise connue;
- connexion générique sans entreprise;
- courriel lié à une entreprise et courriel purement plateforme;
- PDF avec et sans logo;
- absence de fuite du logo d'un tenant vers un autre.

Le lot prioritaire protège le comportement du composant et les surfaces modifiées. Le lot complémentaire ajoute le scénario réseau Playwright réel. `npm run qa:node` ne remplace pas Playwright.

Critère de sortie : les règles de priorité, de fallback et d'isolation tenant sont protégées par des tests.

### Étape 8 - Effectuer la QA visuelle et livrer

Estimation : 2 à 3 heures

Matrice de vérification :

- ordinateur et mobile;
- mode clair et mode sombre;
- logo horizontal, carré et vertical;
- logo clair sur fond clair;
- logo absent;
- image inaccessible;
- français, anglais et espagnol;
- propriétaire, employé, client et superadmin.

Commandes de validation prévues :

```bash
php artisan test --filter=AuthMeApiTest
php artisan test --filter=InvoicePdfTest
php artisan test --filter=InvoiceSendEmailTest
php artisan test --filter=PublicBookingLinksTest
php artisan test --filter=AiAssistantPublicChatTest
php artisan test --filter=ReservationKioskPublicEndpointsTest
php artisan test --filter=PasswordResetTest
php artisan test --filter=TwoFactorChallengeTest
php artisan test --filter=TwoFactorServiceTest
php artisan test --filter=SpanishLocaleSupportTest
php artisan test --filter=SaleReceiptPdfTest
npm run qa:node
npm run qa:build
npm run qa:e2e
composer qa:test
git diff --check
```

Gate PHP obligatoire du dépôt :

1. indexer entièrement tous les fichiers PHP ajoutés, modifiés ou supprimés;
2. exécuter `composer qa:format`;
3. corriger les écarts détectés;
4. relancer `composer qa:format`;
5. exécuter `git diff --check` immédiatement avant la livraison ou le push.

Critère de sortie : les tests ciblés, le build, la vérification visuelle et le gate PHP sont verts.

## 7. Critères d'acceptation globaux

- avec un logo personnalisé valide, le propriétaire, ses employés et ses clients voient tous le logo du propriétaire du workspace;
- le superadmin et les administrateurs plateforme continuent de voir Malikia Pro;
- une page publique liée à une entreprise n'affiche pas simultanément deux logos principaux;
- Malikia Pro suit la règle fixe de la matrice sur les surfaces tenant;
- les écrans d'authentification disposant d'un contexte serveur fiable utilisent la marque de l'entreprise;
- la connexion générique sans contexte conserve Malikia Pro;
- les courriels clients affichent d'abord le logo et le nom de l'entreprise;
- les factures, commandes et reçus affichent le logo de l'entreprise;
- sur le web, une URL vide ou une erreur de chargement ne laisse aucun visuel cassé;
- dans un courriel ou un PDF, le nom de l'entreprise reste présent lorsque l'image ne peut pas être affichée;
- le logo conserve son ratio et son conteneur neutre évite les principaux problèmes de contraste en mode clair ou sombre;
- tous les logos possèdent un texte alternatif pertinent;
- le logo d'une entreprise n'est jamais visible dans le contexte d'une autre entreprise;
- l'impersonation affiche la marque tenant tout en maintenant la bannière Malikia Pro sur chaque page concernée;
- le site marketing, le superadmin, les pages légales et les noms des produits Malikia restent inchangés.

## 8. Calendrier recommandé

### Jour 1

- établir la référence visuelle;
- créer le résolveur backend et fiabiliser le contrat de données;
- créer le composant de logo réutilisable;
- personnaliser la barre latérale;
- commencer les pages publiques prioritaires.

### Jour 2

- terminer les pages publiques;
- simplifier le layout des courriels;
- corriger les notifications qui perdent le contexte tenant;
- uniformiser les PDF et reçus;
- ajouter les tests ciblés.

### Jour 3

- effectuer la QA responsive, sombre et multilingue;
- corriger les régressions;
- exécuter les gates de qualité;
- préparer la livraison sur `develop`.

Le troisième jour constitue aussi une marge raisonnable pour les différences de rendu des logos et des clients de messagerie.

## 9. Lot complémentaire - couverture exhaustive

Estimation additionnelle : 1 à 2 jours ouvrables

Estimation cumulée : 3 à 5 jours ouvrables

Ce lot a été implémenté afin d'uniformiser les surfaces historiques. Il comprend :

- l'inventaire complet des consommateurs de `company_logo_url`, `logo_url` et `company.logo_url`;
- la correction des pages publiques et du portail qui n'utilisent pas `GuestLayout`;
- l'inventaire des vues courriel autonomes et des notifications `MailMessage`;
- la validation détaillée des contextes 2FA, vérification et réinitialisation;
- le nouveau test de branding de la commande et la couverture complète des pages publiques Quote/Work;
- le scénario E2E d'image inaccessible;
- la vérification de l'impersonation et de l'isolation entre deux tenants.

Critère de sortie : aucune surface tenant existante ne dépend encore d'une détection locale ou contradictoire du logo.

État : implémenté et validé. Les exceptions intentionnelles sont les logos clients, les profils de marque des campagnes, les sections CMS, les workspaces de démonstration et les champs d'édition des paramètres. La vue legacy `customer-bulk-outreach.blade.php`, sans consommateur actif identifié, est conservée sans modification. Les validations automatisées PHP, Node et Playwright sont vertes.

## 10. Déploiement progressif

Ordre recommandé :

1. livrer sur un environnement de test;
2. vérifier une entreprise avec logo et une entreprise sans logo;
3. vérifier un employé et un client portail;
4. envoyer des courriels de test dans les trois langues;
5. générer chaque modèle de PDF;
6. valider le superadmin;
7. déployer en production;
8. surveiller les erreurs de chargement d'images et les retours utilisateurs.

Un feature flag n'est pas indispensable si le fallback est correctement testé. Il peut néanmoins être ajouté si la mise en production doit être activée entreprise par entreprise.

## 11. Évolution ultérieure - marque blanche complète

Estimation : 5 à 8 jours ouvrables hors domaines et expéditeur de courriel personnalisés.

Cette phase pourrait ajouter :

- une option de marque blanche par plan ou par entreprise;
- une palette globale primaire, secondaire et accent;
- des variantes de logo clair et sombre;
- un favicon, une icône Apple et un manifeste PWA par entreprise;
- un titre de navigateur et des métadonnées personnalisés;
- une page de connexion contextualisée par slug d'entreprise;
- un réglage permettant d'afficher ou masquer « Propulsé par Malikia Pro »;
- un écran de prévisualisation dans les paramètres de l'entreprise.

Avec des domaines personnalisés et un expéditeur de courriel vérifié par entreprise, prévoir plutôt 2 à 4 semaines pour la feuille de route complète, selon les fournisseurs et le niveau d'automatisation DNS. Ces fonctions ajoutent des contraintes SSL, délivrabilité, sécurité et support opérationnel.

## 12. Définition de terminé

Le premier lot est terminé lorsque :

- toutes les étapes 0 à 8 sont réalisées;
- les critères d'acceptation applicables aux surfaces du lot prioritaire sont validés;
- les captures avant/après montrent clairement la nouvelle hiérarchie de marque;
- les tests automatisés et le build frontend sont verts;
- `composer qa:format` est vert après indexation complète des changements PHP;
- `git diff --check` est vert;
- aucune modification n'a été faite directement sur `main`;
- la livraison ou la pull request cible `develop`.

La personnalisation est entièrement implémentée et validée sur les surfaces existantes des lots prioritaire et complémentaire.
