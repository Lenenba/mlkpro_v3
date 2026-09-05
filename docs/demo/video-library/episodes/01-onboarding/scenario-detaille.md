# G01 — Scénario détaillé de tournage

Dernière mise à jour : 2026-08-11<br>
Source : [fiche maître G01](../01-onboarding.md)

## Intention de la prise

Le spectateur doit comprendre les décisions qui structurent son espace, pas seulement voir sept écrans défiler. Le master montre un parcours réel depuis un navigateur déconnecté jusqu'au tableau de bord, avec une frontière honnête entre configuration de l'entreprise, abonnement et authentification à deux facteurs.

Le tournage principal est réalisé avec un compte neuf. Les annexes nécessitent des états isolés et ne doivent jamais modifier l'espace principal après sa finalisation.

## État initial reproductible

- navigateur déconnecté, sans cookie d'une session personnelle ;
- aucun utilisateur `amina.diallo.onboarding@example.test` ;
- français, thème clair, zoom 100 %, viewport 1920 × 1080 ;
- boîte de test capable de recevoir le code 2FA ;
- Stripe en mode test si le checkout est requis ;
- prix, devise CAD et plan `starter` vérifiés juste avant la prise ;
- aucun mot de passe, code 2FA, secret TOTP, identifiant de session Stripe ou URL signée dans les captures.

## Pas-à-pas du master

| Étape | Capture | Action exacte | Valeur ou état | Réponse attendue | Narration principale | Erreur ou reprise |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | G01-S01 | Ouvrir `/onboarding` déconnecté et cadrer la navigation latérale. | Sept étapes ; Compte actif. | Les étapes Entreprise à Sécurité sont désactivées. | « Un visiteur commence par le compte propriétaire. Tant qu'il n'est pas connecté, les six étapes de l'entreprise restent verrouillées. » | Si une session est active, se déconnecter proprement et recommencer dans un contexte vierge. |
| 2 | G01-S02 | Remplir l'inscription par courriel et soumettre. | Amina Diallo ; adresse example.test ; mot de passe local. | Compte owner créé, session ouverte, redirection `/onboarding`. | « J'utilise une identité fictive et un domaine de test. Le mot de passe reste dans mon gestionnaire et n'apparaît jamais dans le support. » | Si le courriel existe, arrêter : ne pas improviser un suffixe différent entre les prises. Préparer une nouvelle identité canonique. |
| 3 | G01-S03 | Montrer l'écran après redirection. | Six étapes ; Entreprise active. | Compte reconnu comme propriétaire. | « Après la création du compte, l'étape Compte disparaît. Nous configurons maintenant l'espace rattaché à ce propriétaire. » | Vérifier que le compteur annonce bien six étapes avant de continuer. |
| 4 | G01-S04 | Saisir le nom et la description ; laisser le logo vide. | Salon Éclat ; description canonique. | Champs sans erreur. | « Le nom est obligatoire. Le logo et la description sont optionnels ; je n'importe pas d'image dont les droits ne sont pas déjà validés. » | Si un autoremplissage insère une vraie entreprise, vider le champ et refaire la capture. |
| 5 | G01-S05 | Ouvrir la saisie manuelle de l'adresse et choisir la devise. | Montréal ; Québec ; Canada ; CAD. | Carte Entreprise complète. | « La recherche peut aider, mais ce formulaire conserve réellement la ville, la région et le pays. La devise principale sera utilisée dans le catalogue, les factures et les frais en ligne. » | Si Geoapify échoue, garder l'erreur hors master et utiliser la saisie manuelle. Ne pas promettre la rue ou le code postal. |
| 6 | G01-S06 | Cliquer Continuer puis choisir Services. | Entreprise de services. | Carte “Modules actifs” mise à jour. | « Services prépare un espace orienté prestations. Produits suit une branche différente et active notamment le module Ventes. » | Le bouton Continuer n'effectue pas encore la validation serveur ; ne pas dire que l'étape est enregistrée. |
| 7 | G01-S07 | Choisir le secteur Salon de coiffure / beauté. | `salon`. | Secteur sélectionné. | « Ce choix prépare Coupe, Coloration, Coiffage, Soin capillaire et Barbier. Il évite de repartir d'un catalogue totalement vide. » | Ne pas sélectionner Autre pour le master ; cette branche peut consulter Wikipédia et varier. |
| 8 | G01-S08 | Saisir la taille prévue. | 3. | Interface Team, recommandation recalculée, section invitations visible. | « La taille ne signifie pas que trois comptes existent déjà. Elle indique le nombre prévu et oriente l'audience des forfaits. » | Si les cartes Solo restent affichées, vérifier que le champ a perdu le focus et que la valeur numérique est bien 3. |
| 9 | G01-S09 | Ajouter une invitation, remplir la ligne, expliquer les rôles, puis supprimer la ligne avant de quitter l'étape. | Léa Moreau ; `lea.moreau.onboarding@example.test` ; Membre. | Ligne visible puis tableau d'invitations de nouveau vide. | « Admin et Membre n'obtiennent pas les mêmes permissions. Pour ce master, je retire la ligne : les membres seront ajoutés dans G07, sans révéler d'identifiants temporaires ici. » | Vérifier visuellement `invites = 0` avant la soumission finale. |
| 10 | G01-S10 | Cadrer la recommandation de plan. | Profil Team de 3 personnes. | Plan Team recommandé et capacité affichés. | « La recommandation utilise la taille prévue. Un forfait Solo ne peut pas accepter trois personnes ni une invitation. » | Ne pas citer un prix dans la voix ; le catalogue et les promotions peuvent évoluer. |
| 11 | G01-S11 | Passer de Mensuel à Annuel, lire les informations, puis revenir à Mensuel. | CAD ; Mensuel final. | Prix et libellé de période actualisés. | « La période change l'affichage et la facturation. La date d'essai est calculée au moment de la prise ; je la laisse visible sans la figer dans la narration. » | Si une carte devient indisponible, ne pas cliquer dessus. Vérifier la configuration du prix avant de reprendre. |
| 12 | G01-S12 | Sélectionner Team Core, ouvrir les conditions, fermer la modale et cocher l'acceptation. | `starter` ; conditions acceptées. | Résumé du plan sélectionné visible. | « J'ouvre les conditions avant de les accepter. L'acceptation est obligatoire pour une première finalisation, même si le bouton permet techniquement de tenter la soumission sans elle. » | Si Team Core n'est pas disponible, arrêter et mettre à jour le scénario avec le plan réellement préparé ; ne pas substituer silencieusement un autre plan. |
| 13 | G01-S13 | Choisir Code par courriel. | `email`. | Carte email sélectionnée. | « Le code par courriel est le parcours fiable pour un nouveau compte. Le choix Application ne configure pas à lui seul un QR ou un secret TOTP ; cette configuration se termine plus tard dans Sécurité. » | Ne pas choisir App dans le master, sinon le challenge peut retomber silencieusement sur email. |
| 14 | G01-S14 | Revenir brièvement sur Configuration pour confirmer zéro invitation, puis Sécurité et Démarrer l'essai. | Formulaire complet. | Soumission multipart acceptée. | « Je fais une dernière vérification : trois personnes prévues, aucune invitation soumise, plan Team, conditions acceptées et 2FA email. » | Si l'erreur concerne une étape précédente, utiliser le menu latéral pour la corriger ; ne pas perdre le fil en cliquant au hasard. |
| 15 | G01-S15 | Terminer le checkout Stripe en mode test lorsqu'il s'ouvre. | Session de test ; aucune donnée de carte en clair dans le montage. | Retour `/onboarding/billing?status=success…`; abonnement synchronisé. | « Dans cet environnement, Stripe confirme réellement l'essai. Un écran de test n'est pas une preuve de paiement en production. » | Si aucun checkout n'est requis, sauter ce plan et annoncer “l'environnement de démonstration finalise directement”. Ne jamais insérer un faux succès Stripe. |
| 16 | G01-S16 | Laisser la redirection atteindre le challenge 2FA. Cadrer l'écran vide, puis saisir le code hors capture. | Méthode email ; code à six chiffres. | Code accepté et redirection vers l'URL prévue. | « La finalisation active maintenant l'exigence 2FA du propriétaire. Le premier accès au tableau de bord est donc intercepté par ce challenge. » | Le code expire après dix minutes. En cas d'échec d'envoi, ne pas montrer une boucle d'erreur ; réparer la boîte de test et recommencer. |
| 17 | G01-S17 | Afficher le tableau de bord et l'identité du workspace. | Salon Éclat. | Navigation principale accessible. | « Salon Éclat est configuré et le compte propriétaire a franchi sa protection. Dans G02, nous allons prendre nos repères dans cette interface. » | Ne pas utiliser un autre workspace prérempli pour ce plan sans cartouche explicite. |

## Annexes à tourner séparément

| Capture | État isolé | Action | Message pédagogique |
| --- | --- | --- | --- |
| G01-S18 | Membre rattaché à un propriétaire incomplet | Ouvrir `/onboarding`. | Le membre voit **Espace en cours de configuration** et ne peut pas terminer à la place du propriétaire. |
| G01-S19 | Propriétaire incomplet avec taille 2 ou invitation | Tenter `solo_pro`. | Le serveur refuse un forfait Solo incompatible ; l'onboarding reste incomplet. |
| G01-S20 | Compte principal avant finalisation ou second compte jetable | Annuler Stripe Checkout. | Retour à l'onboarding avec paiement annulé ; aucune réussite ne doit être annoncée. |
| G01-S21 | Nouveau propriétaire sans secret TOTP | Sélectionner Application sans soumettre. | Le choix est une préférence, pas la preuve qu'une application est déjà configurée. |

## Script continu — master

> Dans cette vidéo, nous allons créer le compte propriétaire de Salon Éclat, configurer son espace, choisir un forfait cohérent et vérifier le premier accès sécurisé au tableau de bord.
>
> J'ouvre l'onboarding sans être connecté. Il contient sept étapes, mais seule la première est disponible : le compte. Les six autres concernent l'entreprise et se débloquent après l'authentification du propriétaire.
>
> Je choisis le parcours par courriel. Le nom Amina Diallo et l'adresse example.test sont entièrement fictifs. Le mot de passe est préparé localement, respecte la politique affichée et ne sera ni montré ni conservé dans les fichiers de la démonstration.
>
> Après la création, Malikia Pro me reconnecte directement à l'onboarding. L'étape Compte a disparu : il reste six étapes, de l'identité de l'entreprise jusqu'à la sécurité.
>
> Je nomme l'espace Salon Éclat. La description explique en une phrase l'activité du salon. Le logo est facultatif ; je préfère laisser ce champ vide plutôt que d'importer un asset qui n'a pas été validé pour la publication.
>
> Pour l'adresse, je montre la saisie manuelle avec Montréal, Québec et Canada. C'est une subtilité importante : la recherche peut afficher une adresse formatée plus complète, mais l'onboarding actuel enregistre la ville, la province ou région et le pays. Je ne prétends donc pas avoir conservé ici une rue ou un code postal.
>
> Je choisis CAD comme devise principale. Elle sert au catalogue, aux documents de facturation et aux paiements en ligne de cet espace. Changer cette valeur n'est pas un simple choix de symbole, il faut donc la décider avant de créer les premières données financières.
>
> Salon Éclat vend des prestations : je choisis Entreprise de services. À l'étape suivante, je sélectionne Salon de coiffure ou beauté. L'application prépare alors cinq catégories utiles : Coupe, Coloration, Coiffage, Soin capillaire et Barbier. Une entreprise de produits suivrait une autre branche et activerait notamment Ventes.
>
> J'indique maintenant une taille prévue de trois personnes. Cette valeur ne crée pas encore trois utilisateurs. Elle fait passer l'espace dans un contexte Team, adapte la recommandation et rend la section d'invitations disponible.
>
> J'ajoute brièvement Léa pour expliquer les deux rôles. Un Admin reçoit davantage de possibilités de gestion ; un Membre reçoit des permissions plus limitées, elles-mêmes filtrées par les modules actifs. Je retire ensuite cette invitation. Dans cette série, nous ajouterons les membres proprement dans G07, et aucune information temporaire ne doit apparaître à la fin de cette vidéo.
>
> Le forfait recommandé correspond maintenant à un espace Team de trois personnes. Un forfait Solo est réservé au propriétaire seul : il serait refusé avec une taille supérieure à un ou avec une invitation.
>
> Je compare les périodes mensuelle et annuelle. Le montant, les éventuelles promotions et la date de fin d'essai sont lus directement à l'écran le jour de la prise. Je reviens au mensuel et je sélectionne Team Core, dont la capacité convient au scénario préparé. J'ouvre les conditions, puis je les accepte.
>
> La dernière étape concerne la double authentification. Je choisis le code par courriel. L'option Application d'authentification existe, mais elle ne génère pas ici le secret ou le QR nécessaire. Sur un compte neuf sans secret TOTP, le système utilise le courriel comme solution effective. Une capsule Sécurité pourra montrer la configuration complète d'une application.
>
> Avant de terminer, je vérifie le résumé : Salon Éclat, services, secteur salon, trois personnes prévues, aucune invitation en attente, forfait Team mensuel, conditions acceptées et 2FA email.
>
> Si Stripe est prêt dans l'environnement, Démarrer l'essai ouvre maintenant un vrai checkout en mode test. Je termine ce checkout et je laisse le retour synchroniser l'abonnement. Si l'environnement ne demande pas de checkout, l'onboarding se termine directement ; cela ne doit jamais être présenté comme la preuve d'une carte ou d'un paiement.
>
> Une fois l'onboarding terminé, le propriétaire devient soumis à la 2FA. C'est pourquoi le premier accès au tableau de bord s'arrête sur ce challenge. Le code à usage unique n'apparaît pas dans la vidéo. Je le saisis hors capture, puis l'application ouvre enfin le tableau de bord de Salon Éclat.
>
> L'espace est prêt et sécurisé. Dans G02, nous allons voir le tableau de bord, le menu et les actions rapides sans répéter toute cette configuration.

## Script continu — coupe rapide

> Nous allons créer Salon Éclat depuis le compte propriétaire jusqu'au premier tableau de bord sécurisé. Un visiteur commence par créer son compte ; une fois Amina connectée, l'onboarding passe de sept à six étapes.
>
> Je renseigne Salon Éclat, Montréal, Québec, Canada et la devise CAD. Le type Services et le secteur Salon de coiffure ou beauté préparent le contexte métier et les premières catégories.
>
> Trois personnes utiliseront l'espace : je choisis donc un forfait Team, une période mensuelle et j'accepte les conditions. Je laisse les invitations pour l'épisode Équipe.
>
> Je choisis la 2FA par courriel. Si Stripe est requis, je confirme réellement le checkout en mode test. Après le retour, le premier accès au tableau de bord passe par le challenge 2FA. Le code reste hors écran, puis Salon Éclat apparaît dans le tableau de bord.

## Notes de montage

- Conserver le passage de sept à six étapes en temps réel : c'est la preuve que le compte et l'espace sont deux phases distinctes.
- Ne jamais zoomer sur les caractères du mot de passe, le courriel reçu, le code 2FA ou la barre d'adresse contenant un `session_id`.
- Sur G01-S05, ajouter au besoin un cartouche : « Enregistré ici : ville, région, pays ».
- Sur G01-S09, laisser visible la suppression de la ligne pour prouver qu'aucune invitation n'est soumise.
- Sur G01-S15, afficher « Stripe mode test » ; ne pas masquer ce contexte.
- Sur G01-S16, capturer le challenge vide et couper avant la frappe du code.
- Si le checkout n'est pas requis, supprimer G01-S15 du montage et relier G01-S14 à G01-S16 sans simuler l'étape absente.
