# Bibliothèque vidéo — socle des démonstrations Malikia Pro

Dernière mise à jour : 2026-08-11

Cette bibliothèque organise les vidéos courtes qui expliquent une seule action réutilisable : terminer l'onboarding, créer un client, ajouter une prestation, créer une réservation ou lancer une promotion. Les démonstrations métier peuvent ensuite raconter leur propre histoire sans répéter ces explications.

## Principe de réutilisation

Chaque épisode possède un identifiant stable. Dans une démo métier, il suffit d'une phrase courte et d'une carte cliquable :

> La création détaillée d'un client est couverte dans `G03 — Créer un client`. Ici, on se concentre sur son parcours dans le salon.

Une démo métier ne refait l'action complète que si le contexte change réellement le geste. Par exemple, le choix du secteur **Salon de coiffure / beauté** mérite d'être visible dans l'onboarding, mais la saisie générale du compte propriétaire n'a pas besoin d'être répétée dans chaque vidéo salon.

## Ordre recommandé

1. `F00` — Présentation du fondateur, séparée du parcours produit.
2. `G01` — Onboarding complet.
3. `G02` — Repères dans l'interface.
4. `G03` — Créer un client.
5. `G04` — Créer une prestation.
6. `G05` — Créer une réservation.
7. `G06` — Créer une promotion.
8. `G07` — Ajouter un membre à l'équipe.
9. `G08` — Facturer et encaisser.
10. Démonstrations métier : salon, services terrain, commerce, puis autres secteurs.

Le détail des dépendances et des statuts se trouve dans [le catalogue de la série](series-catalog.csv).

## Contenu du dossier

```text
video-library/
├── README.md
├── series-catalog.csv
├── production-standard.md
├── shared-data.md
├── capture-plan.csv
├── production-checklist.md
├── publishing-plan.md
├── episodes/
│   ├── 00-intro-fondateur.md
│   ├── 01-onboarding.md
│   ├── 02-reperes-interface.md
│   ├── 03-creer-client.md
│   ├── 04-creer-prestation.md
│   ├── 05-creer-reservation.md
│   ├── 06-creer-promotion.md
│   ├── 07-ajouter-membre-equipe.md
│   ├── 08-facturer-encaisser.md
│   └── [01-onboarding à 08-facturer-encaisser]/
│       ├── scenario-detaille.md
│       ├── guide-*.md
│       ├── variantes-erreurs.md
│       ├── shot-list.csv
│       └── qa.md
├── templates/
│   └── episode-template.md
├── assets/
├── captures/
│   └── G01/ à G08/
│       ├── README.md
│       ├── capture-session.md
│       ├── desktop/
│       └── annotated/
├── subtitles/
└── thumbnails/
```

Les dossiers médias sont volontairement séparés des scripts :

- `assets/` : portrait du fondateur, logo, musique autorisée et éléments de marque ;
- `captures/` : captures sélectionnées et validées selon [le plan de captures](capture-plan.csv) ;
- `subtitles/` : sous-titres, de préférence en `.srt` ;
- `thumbnails/` : miniatures finales légères ;
- les rushs, pistes audio et masters restent hors Git.

## Sources de vérité à réutiliser

- [Guide actif des espaces de démo](../../DEMO_GUIDE.md)
- [Démo métier Salon Éclat](../../DEMO_VIDEO_SALON_COIFFURE.md)
- [Audit de couverture Salon Éclat](../../audits/demo-salon/2026-08-07-salon-eclat-demo-coverage.md)
- [Script long du module Client](../module-client-demo-20min.md)
- [Script long Factures et paiements](../module-invoices-paiements-demo-20min.md)
- [Voix éditoriale Malikia Pro](../../MALIKIA_PRO_WEBSITE_COPY_PHASE_0_EDITORIAL_FOUNDATION.md)

Cette bibliothèque ne remplace pas ces documents. Elle les transforme en épisodes courts et faciles à citer.

## Assets existants à référencer sans les dupliquer

- logo : [`public/brand/bimi-logo.svg`](../../../public/brand/bimi-logo.svg) ;
- carte sociale : [`public/brand/social-card.svg`](../../../public/brand/social-card.svg) ;
- univers beauté : [`beauty-treatment.jpg`](../../../public/images/landing/stock/beauty-treatment.jpg) et [`salon-front-desk.jpg`](../../../public/images/landing/stock/salon-front-desk.jpg) ;
- écran de file : [`barber-chair.mp4`](../../../public/videos/barber-chair.mp4) et [`chair-taken.mp4`](../../../public/videos/chair-taken.mp4).

Les deux MP4 existants sont des animations intégrées à l'écran Réservations. Ils ne sont pas des tutoriels.

## Niveau de profondeur atteint

Les épisodes [G01 — Onboarding](episodes/01-onboarding.md) à [G08 — Facturer et encaisser](episodes/08-facturer-encaisser.md) possèdent maintenant un master détaillé, des données exactes, un guide de l'interface, les variantes, les erreurs, une shot-list et une QA. G03 a servi de dossier pilote, puis son standard a été appliqué à toute la série.

Cela ne signifie pas que les vidéos sont publiées : les PNG, rushs, sous-titres, miniatures et exports restent à produire. Le [catalogue](series-catalog.csv) et le [plan global](capture-plan.csv) maintiennent cette distinction.

## Première utilisation

1. Lire [les règles de production](production-standard.md).
2. Préparer [les données communes](shared-data.md).
3. Suivre l'épisode [G01 — Onboarding](episodes/01-onboarding.md).
4. Produire les fichiers listés dans [le plan de captures](capture-plan.csv).
5. Passer [la checklist de tournage et de QA](production-checklist.md).
6. Publier et relier les épisodes selon [le plan de publication](publishing-plan.md).
