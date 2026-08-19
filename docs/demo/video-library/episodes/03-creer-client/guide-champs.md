# G03 — Guide des champs du formulaire Client

Dernière mise à jour : 2026-08-19<br>
Route auditée : `/customer/create`<br>
Contexte principal : preset `salon_eclat_complete`

Ce guide décrit ce qui existe réellement dans l'interface actuelle. Il sert à préparer la narration et à repérer les différences entre workspaces ; il ne faut pas réciter chaque règle dans la capsule courte.

## Valeurs initiales à connaître

| Élément | Valeur initiale | Conséquence de tournage |
| --- | --- | --- |
| Type | Particulier | Les champs Entreprise sont masqués. |
| Accès portail | Activé | À désactiver avant la création si aucune invitation n'est préparée. |
| Avatar/icône | Premier preset | Peut être remplacé par un fichier ou un autre preset. |
| Remise fidélité | Vide, puis normalisée à 0 | Ne pas laisser croire qu'une remise est appliquée. |
| Auto-validations | Désactivées | Chaque option ne s'affiche que si son module est actif. |
| Adresse de facturation identique | Désactivée | Visible avec Factures ; elle stocke un choix, pas une deuxième adresse. |

## 1. Type de client

| Choix | Champs propres au choix | Effet important |
| --- | --- | --- |
| Particulier | Date de naissance | Le bloc société reste masqué. |
| Entreprise | Entreprise, numéro d'immatriculation, secteur | Le nom d'entreprise devient obligatoire et la date de naissance est supprimée. |

L'ordre visuel du sélecteur est **Entreprise**, puis **Particulier**, même si Particulier est la valeur par défaut.

## 2. Identité et contact

| Champ | Visibilité | Règle | Usage à expliquer |
| --- | --- | --- | --- |
| Prénom | Toujours | Obligatoire, 255 caractères max. | Contact principal pour les deux types. |
| Nom | Toujours | Obligatoire, 255 caractères max. | Recherche et identification. |
| Adresse email | Toujours | Obligatoire, email valide, 255 caractères max., unique | Sert aussi à l'accès portail lorsqu'il est activé. |
| Téléphone | Toujours | Optionnel, 25 caractères max. | Coordonnée opérationnelle. |
| Date de naissance | Particulier | Optionnelle, aujourd'hui ou avant | Ne jamais utiliser une vraie donnée personnelle. |
| Entreprise | Entreprise | Obligatoire dans cette branche | Nom de l'organisation. |
| Numéro d'immatriculation | Entreprise | Optionnel, 255 caractères max. | Utiliser une valeur explicitement fictive. |
| Secteur | Entreprise | Optionnel, 255 caractères max. | Contexte commercial. |

Le courriel est converti en minuscules avant validation. Son unicité est actuellement globale dans la table Clients, et pas seulement limitée au workspace. Une reprise de tournage doit donc utiliser un nouveau courriel ou un clone réellement remis à zéro.

## 3. Photo, logo et presets

| Type | Libellé | Choix disponibles |
| --- | --- | --- |
| Particulier | Photo de profil | Téléverser un fichier ou choisir parmi 4 avatars. |
| Entreprise | Logo entreprise | Téléverser un fichier ou choisir parmi 4 icônes. |

Fichiers acceptés côté serveur : JPG, JPEG, PNG, GIF, BMP et WEBP, maximum 2 048 Ko. Un SVG téléversé n'est pas accepté. Les icônes internes peuvent néanmoins être des SVG, car elles sont gérées comme presets autorisés.

Choisir un fichier retire le preset sélectionné ; choisir un preset retire le fichier. Pour G03, utiliser un preset afin d'éviter tout droit à l'image.

## 4. Accès portail

Libellé visible : **Donner accès à la plateforme**.

| Choix | Ce que fait réellement l'application | Quand l'utiliser dans une démo |
| --- | --- | --- |
| Activé | Crée un utilisateur de rôle client, un mot de passe aléatoire, impose un changement de mot de passe et tente d'envoyer une invitation. | Seulement avec une boîte de test et un parcours portail préparés. |
| Désactivé | Crée seulement la fiche Client interne. | Parcours G03 principal. |

Si l'envoi de l'invitation échoue, le client peut tout de même être créé et l'application affiche un avertissement. Cette branche mérite une capsule Portail séparée ; elle ne doit pas être simulée dans G03.

Avec le portail actif, le courriel doit être unique à la fois dans Clients et Utilisateurs.

## 5. Validation automatique

La carte apparaît si au moins un des modules suivants est actif : Devis, Jobs, Tâches ou Factures.

| Option | Module requis | Visible dans Salon Éclat |
| --- | --- | --- |
| Auto-acceptation des devis | Devis | Non |
| Validation auto des chantiers | Jobs | Non |
| Validation auto des tâches | Tâches | Non |
| Validation auto des factures | Factures | Oui |

Le serveur force à `false` une option envoyée pour un module inactif. La vidéo doit donc montrer l'interface du workspace réellement utilisé, sans reconstituer artificiellement les quatre options.

## 6. Détails additionnels

| Champ | Règle | Interprétation métier |
| --- | --- | --- |
| Description | Optionnelle ; si présente, 5 à 255 caractères | Contexte utile à l'équipe, jamais une donnée sensible. |
| Référé par | Optionnel, 255 caractères max. | Source du contact : Instagram, recommandation, événement, etc. |
| Remise fidélité (%) | Nombre entre 0 et 100 | Remise permanente appliquée au client ; à distinguer d'un code promotionnel ponctuel. |

Une remise vide devient 0. Cette remise peut influencer les ventes et le calcul des taxes après remise : elle ne doit pas être saisie au hasard pour rendre la capture plus intéressante.

## 7. Localisation

Dans Salon Éclat, la section s'appelle **Localisation**. Dans un workspace utilisant Jobs, son titre devient **Propriétés**.

| Champ | Règle de requête | Subtilité |
| --- | --- | --- |
| Rechercher une adresse | Optionnel | Autocomplétion après 2 caractères si Geoapify est configuré. |
| Rue 1 | Optionnel | Ne suffit pas à créer l'adresse. |
| Rue 2 | Optionnel | Complément. |
| Ville | Optionnelle en validation | Sa présence déclenche réellement la création de l'adresse. |
| État | Optionnel | Province ou État. |
| Code postal | Optionnel, 10 caractères max. | Utiliser une valeur fictive cohérente. |
| Pays | Optionnel | L'autocomplétion est limitée au Canada et aux États-Unis. |

Lorsque la ville est présente, l'adresse créée devient automatiquement la propriété principale de type `physical`. Le formulaire initial ne permet ni de choisir un autre type, ni d'ajouter plusieurs sites.

Avec Factures, la case **Adresse de facturation identique à l'adresse principale** est visible. Elle enregistre un indicateur ; elle ne duplique pas l'adresse et ne crée pas une adresse de facturation distincte.

## 8. Préférences de facturation avancées

Cette carte est visible uniquement lorsque la condition suivante est vraie :

```text
Factures ET (Jobs OU Tâches)
```

Elle est donc **absente de Salon Éclat**.

| Champ | Options conditionnelles |
| --- | --- |
| Mode de facturation | Par tâche, Par segment, Fin de chantier/prestation, Différé |
| Regroupement | Une facture, Regrouper |
| Cycle | Hebdomadaire, toutes les 2 semaines, mensuel, chaque N tâches |
| Délai | 0 à 365 jours, seulement en mode Différé |
| Règle de date | 50 caractères max., seulement en mode Différé |

G03-S16 peut illustrer cette carte avec un workspace de référence, à condition d'afficher clairement que la capture ne vient pas de Salon Éclat.

## 9. Actions de bas de formulaire

| Bouton | Comportement vérifié | Formulation sûre dans la narration |
| --- | --- | --- |
| Annuler | Revient à `/customer` en création, ou à la fiche du client en modification, sans enregistrer | Permet de quitter le formulaire sans créer ni modifier la fiche. |
| Enregistrer et créer un autre | Crée le client puis revient à `/customer/create` | Utile pour une saisie en série. |
| Enregistrer client | Crée le client puis revient à `/customer` | Choix du parcours G03. |

Le numéro client est généré automatiquement avec un préfixe `C` ; il ne se saisit pas dans ce formulaire.

## 10. Éléments qui ne sont pas créés ici

- civilité visible ;
- tags ou segments ;
- niveau de fidélité ;
- plusieurs propriétés ;
- adresse de type facturation ou autre ;
- historique de rendez-vous ;
- invitation portail contrôlée après envoi.

Ces éléments ne doivent pas être ajoutés à la narration simplement parce qu'ils existent ailleurs dans le module Client.

## Sources de vérité dans le dépôt

- Formulaire : `resources/js/Pages/Customer/Create.vue`
- Traductions : `resources/js/i18n/modules/fr/customers.json`
- Validation : `app/Http/Requests/CustomerRequest.php`
- Création et redirections : `app/Http/Controllers/CustomerController.php`
- Types de clients : `app/Enums/CustomerClientType.php`
- Preset Salon Éclat : `app/Services/Demo/DemoWorkspaceCatalog.php`
- Cohérence des modules Salon : `tests/Feature/CustomerSalonFeatureConsistencyTest.php`
- Portail et variantes : `tests/Feature/CustomerClientRoleTest.php`
