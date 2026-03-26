# Super Admin Branding - Technical Debt

## Resume
Aujourd hui, une partie importante du graphisme produit est encore distribuee dans le code:
- polices importe es localement dans certaines pages
- couleurs et styles parfois codes en dur dans des composants
- options de theme publiques gerees a part du reste de la plateforme
- absence d un point unique dans le super admin pour piloter le branding global

Cette dette technique doit etre traitee pour centraliser la personnalisation visuelle dans un espace super admin unique.

## Pourquoi c est une dette
Chaque ajustement visuel demande encore une intervention code:
- changer une police sur une page marketing
- aligner les couleurs entre home, pricing et pages publiques
- faire evoluer le rendu sans casser le style de composants partages
- gerer des exceptions locales qui deviennent difficiles a suivre

Exemple recent:
- test de `Montserrat` sur la home via code local dans [Welcome.vue](c:/Users/060507CA8/Herd/mlkpro_v3/resources/js/Pages/Welcome.vue)

## Probleme actuel
Le branding est fragmente entre plusieurs couches:
- theme public dans [PlatformPageContentService.php](c:/Users/060507CA8/Herd/mlkpro_v3/app/Services/PlatformPageContentService.php)
- mapping de fonts dans [Page.vue](c:/Users/060507CA8/Herd/mlkpro_v3/resources/js/Pages/Public/Page.vue)
- imports et choix de fonts locaux dans [Welcome.vue](c:/Users/060507CA8/Herd/mlkpro_v3/resources/js/Pages/Welcome.vue) et [Pricing.vue](c:/Users/060507CA8/Herd/mlkpro_v3/resources/js/Pages/Pricing.vue)
- base typographique applicative dans [tailwind.config.js](c:/Users/060507CA8/Herd/mlkpro_v3/tailwind.config.js)
- edition partielle du theme public dans [Edit.vue](c:/Users/060507CA8/Herd/mlkpro_v3/resources/js/Pages/SuperAdmin/Pages/Edit.vue)

Resultat:
- pas de source of truth unique
- risque de divergences visuelles entre pages
- cout de maintenance eleve
- iterations branding lentes

## Cible
Creer un centre de branding dans le super admin pour piloter les tokens visuels principaux sans modification code.

## Ce qui devrait etre centralise
- police du body
- police des titres
- palette primaire
- palette secondaire / surfaces
- couleurs de texte et muted
- rayon des bordures
- ombres
- style de boutons
- largeur de contenu
- densite / spacing principaux
- logo principal et variantes
- images de marque partagees quand pertinent

## Hors perimetre initial
- PDF
- emails transactionnels
- assets marketing tres specifiques a une seule campagne
- theming complet du backoffice si on veut aller vite

## Proposition de mise en oeuvre
### Phase 1
- definir un schema de `branding_settings` global
- stocker les tokens dans `platform_settings`
- fournir un resolver unique cote backend
- exposer les variables CSS globales cote public

### Phase 2
- creer un ecran super admin `Branding`
- edition de fonts, couleurs, radius, shadows, boutons
- preview live avant publication

### Phase 3
- brancher home, pricing, pages publiques et footer sur ce resolver unique
- supprimer les imports et overrides ponctuels dans les pages

### Phase 4
- etendre si besoin au backoffice

## Definition of done
- un super admin peut modifier les tokens visuels principaux sans toucher au code
- la home, pricing, pages publiques et footer consomment la meme source de verite
- aucun import de font local ne reste dans les pages publiques principales
- les exceptions locales sont documentees et limitees

## Risques si on ne le fait pas
- multiplication des overrides CSS ponctuels
- incoherence croissante entre pages
- regressions plus probables lors de chaque changement branding
- difficultes a faire evoluer rapidement la direction artistique

## Backlog suggere
1. creer le schema `branding_settings`
2. creer le resolver backend + variables CSS
3. creer l ecran super admin branding
4. migrer la home
5. migrer pricing
6. migrer les pages publiques generiques
7. nettoyer les overrides historiques

## Priorite
`medium`

## Declencheur recommande
Lancer ce chantier des qu on veut:
- valider `Montserrat` ou une autre police globalement
- harmoniser le branding public
- permettre aux ops / super admin de faire evoluer le graphisme sans deploy
