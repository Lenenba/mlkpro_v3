# Personnalisation de la couleur principale par entreprise

Dernière mise à jour : 2026-08-18

Statut : socle et surfaces prioritaires implémentés et validés sur `develop`

## 1. Objectif

Permettre au propriétaire d'une entreprise de choisir une couleur principale qui complète son logo et personnalise les actions importantes de son espace Malikia Pro.

La couleur choisie doit :

- suivre l'entreprise sur l'espace connecté, les pages publiques et le portail client;
- être réutilisée dans les courriels transactionnels et les documents PDF de l'entreprise;
- conserver un texte lisible grâce à un contraste calculé automatiquement;
- ne jamais remplacer les couleurs fonctionnelles des succès, erreurs, avertissements, paiements ou statuts;
- revenir à la couleur Malikia Pro si aucune valeur valide n'est enregistrée.

## 2. Décisions de conception

Le réglage est conservé dans `users.company_branding_settings` sous une forme extensible :

```json
{
  "primary_color": "#16A34A"
}
```

Seule une couleur hexadécimale complète `#RRGGBB` est acceptée. Le serveur la normalise en majuscules et calcule les variantes de survol, de focus et de premier plan.

Ordre de priorité :

1. couleur spécifique d'une surface, par exemple `company_store_settings.header_color` pour l'en-tête de boutique;
2. couleur principale globale de l'entreprise;
3. couleur Malikia Pro par défaut `#16A34A`.

Les palettes des campagnes marketing, des workspaces de démonstration et des pages CMS restent indépendantes.

## 3. Expérience dans les paramètres

Dans **Paramètres > Entreprise**, le propriétaire dispose de :

- un sélecteur visuel;
- un champ hexadécimal synchronisé;
- un aperçu avec la couleur de texte accessible;
- une action de réinitialisation vers la couleur Malikia Pro;
- un message de validation si le format est invalide.

Les employés, clients portail et administrateurs plateforme ne peuvent pas modifier ce réglage.

## 4. Application de la couleur

La palette est exposée par `TenantBrandingResolver`, puis transformée en variables CSS sémantiques :

- `--app-primary`;
- `--app-primary-hover`;
- `--app-primary-focus`;
- `--app-primary-line`;
- `--app-primary-foreground`;
- `--app-primary-checked`.

Ces variables sont appliquées et nettoyées lors des navigations Inertia afin d'éviter qu'une couleur d'entreprise soit conservée chez une autre entreprise. Le superadmin reste aux couleurs de Malikia Pro, sauf lorsqu'il reproduit volontairement l'expérience d'un tenant en impersonation.

La migration visuelle cible les boutons principaux, liens actifs, champs, cases à cocher, onglets et appels à l'action publics. Les verts ou autres couleurs qui représentent un état métier restent inchangés.

## 5. Courriels et PDF

Les courriels transactionnels tenant utilisent des couleurs inline compatibles avec les clients de messagerie. Les courriels de plateforme, les campagnes marketing et les démonstrations conservent leur propre palette.

Les cinq modèles PDF utilisent la couleur principale uniquement pour leurs accents de marque. Les couleurs de statut, d'alerte et de paiement ne sont pas modifiées.

## 6. Sécurité et accessibilité

- validation stricte côté serveur contre l'injection CSS;
- nettoyage défensif côté navigateur et dans les rendus Blade;
- résolution systématique de la couleur depuis le propriétaire du compte;
- choix automatique entre un texte clair et sombre selon le meilleur ratio de contraste WCAG;
- fallback déterministe pour les anciennes données et les notifications déjà en file;
- nettoyage du thème entre deux contextes tenant.

## 7. Étapes de réalisation

1. ajouter le stockage JSON et la validation propriétaire;
2. étendre le contrat de branding Inertia, API, public et portail;
3. ajouter le sélecteur et l'aperçu dans les paramètres;
4. activer les variables de thème et migrer les composants partagés;
5. adapter les pages publiques et le portail prioritaires;
6. adapter les courriels transactionnels tenant et les cinq PDF;
7. couvrir sauvegarde, contraste, fallback, plateforme, impersonation et isolation inter-tenant;
8. exécuter les gates PHP, Node, build et navigateur avant livraison sur `develop`.

## 8. Critères de sortie

La fonctionnalité est terminée lorsque :

- une entreprise peut enregistrer ou réinitialiser sa couleur;
- un employé et un client portail héritent uniquement de la couleur de leur propriétaire;
- les appels à l'action ciblés utilisent la couleur et un texte lisible;
- les pages publiques, courriels et PDF utilisent le même contrat;
- le superadmin et les surfaces exclues conservent leur palette;
- la navigation entre deux entreprises ne provoque aucune fuite de thème;
- tous les tests et contrôles obligatoires sont verts.

## 9. Évolutions possibles

La structure JSON permettra ensuite d'ajouter, sans nouvelle colonne par option :

- une couleur secondaire et une couleur d'accent;
- des variantes de logo clair et sombre;
- une palette proposée automatiquement à partir du logo;
- des thèmes prédéfinis;
- une option de marque blanche selon le forfait.

## 10. Résultat de livraison

Les huit étapes du périmètre initial sont réalisées. La couleur est enregistrée de façon isolée par entreprise et propagée aux surfaces prioritaires des espaces connectés, pages publiques, portails, courriels transactionnels et cinq modèles PDF, avec contraste automatique et fallback Malikia Pro.

Contrôles réalisés avant livraison :

- suite PHP complète : 1 436 tests et 15 352 assertions;
- analyse statique PHP : 867 fichiers sans erreur;
- suite Node : 74 tests;
- build Vite de production et budgets frontend;
- scénario Playwright multi-entreprises : 3 tests;
- génération, rasterisation et inspection visuelle des cinq modèles PDF;
- gate `composer qa:format` : 63 fichiers PHP contrôlés;
- contrôle documentaire et vérification des espaces de fin de ligne.

## 11. Extension de couverture exhaustive

La présence de nombreux anciens styles `green-*` et `emerald-*` impose maintenant une certification module par module. Certains sont des couleurs de marque à migrer; d'autres sont des statuts fonctionnels qui doivent rester verts.

Le suivi exhaustif, la taxonomie et les critères de passage entre modules sont définis dans [le plan d'audit de la couleur primaire par module](PLAN_AUDIT_COULEUR_PRIMAIRE_PAR_MODULE_2026-08-18.md).
