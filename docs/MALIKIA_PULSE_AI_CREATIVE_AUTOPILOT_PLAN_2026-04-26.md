# Malikia Pulse Autopilot - plan IA creative

Derniere mise a jour: 2026-04-26

## 1. Objectif

Ce document decrit le plan recommande pour ajouter une couche IA dans `Malikia Pulse Autopilot`.

L objectif est de permettre a Autopilot de:

- generer un post social plus intelligent a partir d une source metier
- proposer le meilleur candidat selon une grille de qualite
- generer ou preparer une image IA lorsque la regle le demande
- envoyer le post genere dans le workflow de validation Pulse existant

Cette evolution ne doit pas remplacer Pulse.
Elle doit enrichir le pipeline actuel:

`SocialAutomationRule -> SocialContentGeneratorService -> SocialPost -> SocialApprovalService -> SocialPublishingService`

Par defaut, le post genere doit rester soumis a validation humaine avant publication.

## 2. Decision d architecture

La bonne approche est d ajouter un moteur IA en amont de la creation du `SocialPost`.

Le `SocialPost` reste l entite centrale.
Le post IA doit donc etre stocke comme un post Pulse normal, avec:

- `content_payload.text` pour le texte genere
- `media_payload` pour l image existante ou generee
- `link_url` pour la destination
- `social_automation_rule_id` pour relier le post a la regle
- `metadata.automation` pour tracer la generation
- `metadata.ai_generation` pour tracer le modele, les prompts, le score et les variantes

Il ne faut pas creer de table parallele du type `social_ai_posts`.
La valeur produit vient du fait que les posts IA passent ensuite par les memes vues, approbations, historiques et publications que les autres posts Pulse.

## 3. Plan en 3 etapes

## Etape 1 - Ajouter les consignes IA dans les regles Autopilot

But:

Permettre a l utilisateur de cadrer la generation avant que l IA soit appelee.

Champs recommandes dans l interface Autopilot:

- generation texte IA: active / inactive
- generation image IA: active / inactive
- prompt general de cadrage
- prompt specifique image
- ton: professionnel, chaleureux, premium, direct, promotionnel
- objectif: vendre, informer, reserver, annoncer, relancer
- mode image:
  - `never`
  - `if_missing`
  - `always`
- format image:
  - `square`
  - `portrait`
  - `landscape`
  - `auto`
- nombre de variantes texte a generer
- validation humaine obligatoire par defaut

Stockage recommande pour le MVP:

```json
{
  "generation_settings": {
    "text_ai_enabled": true,
    "image_ai_enabled": false,
    "creative_prompt": "Mets en avant le service avec un ton premium et local.",
    "image_prompt": "Image lumineuse, moderne, sans texte incruste.",
    "tone": "warm",
    "goal": "book",
    "image_mode": "if_missing",
    "image_format": "square",
    "variant_count": 3
  }
}
```

Ce bloc peut etre stocke dans `social_automation_rules.metadata`.
Si les usages deviennent plus complexes, on pourra ensuite migrer vers des colonnes dediees.

Livrables:

- validation backend des options IA
- champs UI dans `SocialAutomationManager`
- payload regle enrichi
- tests create/update de regle avec `metadata.generation_settings`

Critere de fin:

Une regle Autopilot peut enregistrer des consignes IA sans changer le comportement de generation existant.

## Etape 2 - Creer le moteur IA texte, scoring et prompt image

But:

Ajouter un service dedie qui transforme une source metier en candidat de publication.

Service recommande:

`App\Services\Social\SocialAiCreativeService`

Responsabilites:

- construire un brief a partir de la source selectionnee
- appeler OpenAI pour generer plusieurs variantes
- demander une reponse structuree
- scorer les variantes
- choisir le meilleur post
- produire un prompt image final
- fournir un fallback propre si l IA echoue

Entree type:

```json
{
  "company": {
    "name": "Nom de l entreprise",
    "sector": "salon",
    "locale": "fr"
  },
  "source": {
    "type": "service",
    "label": "Soin visage premium",
    "summary": "Description courte du service",
    "link_url": "https://..."
  },
  "settings": {
    "tone": "warm",
    "goal": "book",
    "creative_prompt": "Insister sur la confiance et le resultat.",
    "image_prompt": "Ambiance lumineuse, professionnelle, realiste.",
    "variant_count": 3
  },
  "targets": [
    "facebook",
    "instagram"
  ]
}
```

Sortie type:

```json
{
  "selected": {
    "text": "Texte final du post",
    "hashtags": ["#SoinVisage", "#SalonLocal"],
    "cta": "Reservez votre moment.",
    "image_prompt": "Prompt final pour l image IA",
    "score": 91,
    "score_reason": "Clair, actionnable, adapte au canal."
  },
  "variants": [
    {
      "text": "Variante 1",
      "score": 84
    },
    {
      "text": "Variante 2",
      "score": 91
    }
  ],
  "model": "gpt-5.x",
  "generation_mode": "ai_creative"
}
```

Recommandation technique:

- utiliser une reponse structuree JSON pour eviter les sorties difficiles a parser
- limiter strictement les longueurs selon les reseaux sociaux
- garder les prompts et scores dans les metadata
- conserver le fallback vers `SocialSuggestionService`

Generation image:

Pour le MVP de cette etape, le moteur peut seulement produire `image_prompt`.
La generation d image reelle peut etre activee dans l etape 3.

Livrables:

- `SocialAiCreativeService`
- nouvelle methode OpenAI si besoin pour Responses API
- schema de sortie strict
- scoring minimal
- fallback vers generation deterministe
- tests unitaires du service avec client OpenAI fake

Critere de fin:

Autopilot peut produire un candidat texte IA robuste, avec prompt image et metadata de generation, sans encore publier ni generer automatiquement une image.

## Etape 3 - Brancher image IA et validation Pulse

But:

Creer le post Pulse complet, avec texte et image IA si demande, puis l envoyer en validation.

Flux final:

1. `SocialAutomationRunnerService` detecte une regle due.
2. `SocialContentPlannerService` choisit la source.
3. `SocialContentGeneratorService` appelle `SocialAiCreativeService` si l IA est activee.
4. Si `image_ai_enabled` est actif:
   - `never`: aucune image IA
   - `if_missing`: generer seulement si la source n a pas deja d image
   - `always`: generer une nouvelle image
5. L image generee est stockee sur le disque public.
6. Le `media_payload` recoit l URL publique de l image.
7. `SocialPostService::createAutomationDraft` cree un post standard.
8. `SocialApprovalService::submit` envoie le post en verification.

Metadata recommandee sur le post:

```json
{
  "ai_generation": {
    "text_enabled": true,
    "image_enabled": true,
    "text_model": "gpt-5.x",
    "image_model": "gpt-image-1.x",
    "creative_prompt": "Prompt utilisateur",
    "image_prompt": "Prompt final utilise",
    "selected_score": 91,
    "variant_count": 3,
    "fallback_used": false,
    "generated_at": "2026-04-26T..."
  }
}
```

Points de garde obligatoires:

- validation humaine par defaut
- credits ou quota pour la generation image
- timeout et retry controles
- fallback si OpenAI echoue
- journalisation claire dans `social_automation_runs`
- pas d auto-publication IA sans activation explicite

Livrables:

- integration dans `SocialContentGeneratorService`
- generation image IA avec stockage public
- metadata `ai_generation`
- regeneration depuis l inbox d approbation
- tests feature:
  - texte IA genere un post
  - image IA ajoute un `media_payload`
  - fallback si OpenAI indisponible
  - validation humaine obligatoire
  - auto-publish reste bloque sauf option explicite

Critere de fin:

Une regle Autopilot peut generer automatiquement un candidat texte + image IA et l envoyer dans l inbox de validation Pulse.

## 4. Grille de scoring recommandee

Le "meilleur post" doit etre choisi selon des criteres explicites.

Score recommande sur 100:

- clarte du message: 20
- adequation a la source metier: 20
- qualite du CTA: 15
- ton conforme a la regle: 15
- adaptation au reseau social: 10
- absence de repetition recente: 10
- respect des contraintes de longueur: 5
- qualite du prompt image: 5

Le score doit rester un outil d aide.
Le post final doit quand meme passer par la validation humaine.

## 5. Gestion des couts et limites

Risques principaux:

- generation image plus couteuse que texte
- latence plus elevee
- erreurs OpenAI temporaires
- generation de variantes trop frequente

Mesures recommandees:

- garder `variant_count` entre 1 et 5
- image IA par defaut sur `if_missing`, pas `always`
- reutiliser le systeme de credits existant
- ajouter un contexte image `social` dans le service de quota IA
- stocker le modele et le mode utilise dans les metadata
- ajouter un bouton de regeneration manuel cote validation plutot que regenerer en boucle

## 6. Mode MVP recommande

Ordre de livraison conseille:

1. Texte IA + prompt image sauvegarde
2. Scoring et choix du meilleur candidat
3. Image IA automatique avec credits
4. Regeneration depuis l inbox de validation
5. Optimisation par plateforme sociale

Ce MVP donne vite de la valeur sans exposer le systeme a trop de couts ou de complexite.

## 7. Sources techniques utiles

Documentation OpenAI pertinente:

- Text generation: https://developers.openai.com/api/docs/guides/text
- Structured Outputs: https://developers.openai.com/api/docs/guides/structured-outputs
- Image generation: https://developers.openai.com/api/docs/guides/image-generation

Fichiers internes importants:

- `app/Services/Social/SocialContentGeneratorService.php`
- `app/Services/Social/SocialAutomationRunnerService.php`
- `app/Services/Social/SocialPostService.php`
- `app/Services/Social/SocialApprovalService.php`
- `app/Services/Assistant/OpenAiClient.php`
- `app/Http/Controllers/AiImageController.php`
- `resources/js/Pages/Social/Components/SocialAutomationManager.vue`

## 8. Conclusion

La fonctionnalite est faisable avec un risque technique raisonnable.

Le point cle est de ne pas creer un second systeme de publication.
L IA doit seulement produire un meilleur candidat de publication, puis Pulse continue de gerer:

- le brouillon
- les cibles sociales
- la validation
- la programmation
- la publication
- l historique

La meilleure trajectoire est donc:

`Consignes IA -> Moteur creative -> Image optionnelle -> SocialPost standard -> Validation humaine`
