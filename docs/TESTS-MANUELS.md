# MANUAL_TESTS — À tester à la main (non testable depuis l'environnement de développement)

> Seuls `php -l`, `node --check` et des scripts en ligne de commande ont été exécutés. Rien
> de ce qui suit n'a été essayé dans un vrai Grav, un vrai Admin2 ni chez Stripe.
> Notez tout écart dans `TODO.md`. **Ne figure ici que ce qui reste à tester** : un point validé est retiré
> et consigné dans `DONE.md` (passes des 13/10/2026 dans Admin2 réel via Claude in Chrome).

> Extrait du site d'origine : seules les sections utiles à ce plugin sont gardées (numérotation d'origine).

## 0. Préparation du serveur (une fois)
1. Sur le serveur, vérifiez que ces réglages existent :
   - `config/plugins/simple-shop-payment-stripe.yaml` : `enabled: true`, clés **de test** Stripe ;
   - `config/plugins/email.yaml` : SMTP renseigné ;
   - `config/plugins/simple-shop/carriers.yaml` : au moins une règle pour la zone `FR-METRO`.
2. Videz le cache Grav.

## 1. Page Admin2 « Boutique »
Prérequis : plugins `api` et `admin2` installés et actifs ; vous avez un compte administrateur.

0. **Groupe « gestionnaire boutique »** (pour un compte qui n'est pas super-admin) :
   - ajoutez le bloc `shop-manager` de `config/groups.dist.yaml` dans `config/groups.yaml` sur le serveur
     (ou créez le groupe dans Admin2 → Groupes avec les mêmes accès) ;
   - créez un compte de test, affectez-lui ce groupe uniquement ;
   - connectez-vous à Admin2 avec ce compte : il doit voir « Boutique » (et rien d'autre de sensible) ;
   - si la connexion est refusée, notez dans `OBSCURE.md` (Q9) la permission demandée (`api.login` ou `api.access`) ;
   - vérifiez qu'il ne peut **pas** modifier les pages ni les utilisateurs.
1. **Permissions** : dans Admin2, ouvrez votre compte (ou son groupe) et accordez
   `api.simple-shop` en lecture **et** en écriture (`api.simple-shop.read`, `api.simple-shop.write`).
   Si la permission n'apparaît pas : videz le cache, rechargez ; vérifiez que
   `plugins/simple-shop/permissions.yaml` est bien déployé. Un compte super-admin devrait tout avoir.
2. **Commandes** (liste et filtres validés ; créez d'abord une commande de test, voir §2) :
   - filtres en action (statut, texte, dates) et pagination ;
   - clic sur une ligne : détail (client, livraison, facturation, articles, historique, facture) ;
   - changez le statut en « Expédiée » : message de succès, une ligne apparaît dans l'historique, **le client reçoit un e-mail** ;
   - e-reporting : changez le statut de la facture ;
   - « Télécharger la facture (PDF) » : un PDF s'ouvre ;
   - « Exporter en CSV » : ouvrez dans LibreOffice/Excel, accents corrects, colonnes séparées, montants à virgule ;
   - un message de succès en haut de page (après un changement de statut) est annoncé poliment (`role=status`).
3. **Transporteurs** : écran de formulaires, testé en détail au §11. Seul reste ici le **mode avancé** (bloc replié
   « Mode avancé : fichier YAML » en bas de l'onglet) : modifiez un prix, enregistrez, acceptez la confirmation → message
   sous le bouton et fichier `carriers.yaml.bak` créé sur le serveur. Un YAML invalide affiche les erreurs en haut de page et rien n'est écrit.
4. **Adresses bannies** (bannir / débannir validés) : une adresse bannie ne reçoit plus de code sur `/identify`.
5. **Sans droit** : avec un compte sans permission, la page doit refuser l'accès (erreur 403 claire).

## 2. Parcours d'achat complet (sans JavaScript, thème `no-js`)
Désactivez JavaScript dans le navigateur (ou utilisez un navigateur texte) pour tout ce paragraphe.
1. Ajoutez un produit au panier, saisissez les coordonnées (nom, prénom, e-mail, téléphone, rue, code postal, **ville**).
2. Code postal `20000` (Corse) ou `97400` (outre-mer) : message clair de zone non desservie, pas de livraison proposée.
3. Code postal métropolitain (ex. `75001`) : étape e-mail → code reçu par e-mail → saisie → livraison → paiement.
4. **Anti-spam** : redemandez un code immédiatement : message « patientez » et **un seul** e-mail reçu. Après 60 s, un nouveau code part.
   (Plafonds : 5 par heure et par adresse, 20 par IP — à vérifier seulement si vous voulez les voir bloquer.)
5. **Déconnexion** : sur `/mon-compte`, bouton « Me déconnecter » ; après, `/mon-compte` renvoie vers l'identification.

## 3. Paiement Stripe (mode TEST uniquement)
Cartes de test Stripe : `4242 4242 4242 4242` (succès), `4000 0025 0000 3155` (authentification 3-D Secure),
`4000 0000 0000 9995` (refus). Date future quelconque, CVC quelconque.

**A. Mode avec JavaScript** (`use_javascript: true`)
1. Paiement avec `4242…` : redirection vers la confirmation, **une seule** commande créée, e-mails client et vendeur reçus, facture PDF jointe.
2. Stock : le produit commandé en 2 exemplaires avec stock 5 passe à `product_quantity: 3` dans sa page ; un produit à `-1` reste à `-1`.
3. Carte `4000 0025 0000 3155` : valider l'authentification, retour sur le site, commande créée une seule fois.
4. Carte refusée `4000 0000 0000 9995` : message d'erreur, aucune commande.
5. Désactivez JavaScript sur la page de paiement : le bouton de repli `<noscript>` doit mener au paiement hébergé Stripe.

**B. Mode sans JavaScript** (`use_javascript: false`, vider le cache)
1. Étape paiement : bouton qui redirige vers la page Stripe hébergée ; payer avec `4242…` ; retour sur le site ; confirmation ; **une seule** commande.
2. Sur la page Stripe, cliquez « Retour » (annulation) : retour au site sans commande, panier intact.
3. Rechargez (F5) la page de retour après un paiement réussi : toujours **une seule** commande, pas d'e-mail en double.
4. Ouvrez `/checkout/confirmation` sans avoir commandé : aucune commande affichée ; ajouter `?order_id=…` dans l'URL ne montre rien d'une autre commande.

**C. Webhook** (après la configuration décrite dans `TODO.md`, « Configuration du webhook Stripe »)
1. Mode sans JavaScript : payez avec `4242…` et **fermez l'onglet sur la page Stripe**, juste avant le retour. Attendu (quelques
   secondes plus tard) : une commande dans la page Boutique, e-mails client et vendeur reçus, stock décrémenté.
   Dans Stripe : événement `payment_intent.succeeded`, réponse `200`.
2. Même scénario en mode JavaScript : payer puis fermer l'onglet avant la page de confirmation.
3. Payer normalement (sans fermer) : **une seule** commande et **un seul** jeu d'e-mails, que le webhook ou le retour arrive le premier.
4. Dans Stripe, *Renvoyer* l'événement : réponse `200`, aucune seconde commande.
5. Secret volontairement faux : réponse `400` dans Stripe et aucune commande créée par ce chemin.
6. Dans `logs/grav.log`, aucune ligne « Webhook : paiement … encaissé mais commande non créée » après ces essais.

## 4. Facturation Factur-X (T13)
1. Générez une facture de test (une commande ci-dessus) et lisez `logs/simple-shop/invoices.log` : aucune ligne `WARNING`.
2. Envoyez le PDF au testeur de la FNFE-MPE (https://fnfe-mpe.org/factur-x/testeur-de-factur-x/) : conforme (PDF/A-3 + XML CII).
3. Vérifiez que la **ville de l'acheteur** figure correctement dans la facture.

## 5. Règles de livraison
Avec `carriers.yaml` : un panier léger et un panier lourd proposent les bons modes et tarifs en `FR-METRO`.

## 6. Recherche dans les commandes (accents)
Créez (ou utilisez) une commande au nom « Éloïse Désiré ». Dans la page Boutique, cherchez `eloise`, `ÉLOÏSE`, `desire`,
`désiré` : la commande doit apparaître chaque fois. Cherchez `%` ou `_` : aucune commande ne doit sortir par erreur.

## 7. Zones de livraison réglables
1. Par défaut (`delivery.allowed_zones: [FR-METRO]`) : codes postaux `75001`, `13001` acceptés ; `20000`, `97400`, `98000`,
   `99000`, `1234` refusés avec le message « Nous livrons uniquement en France métropolitaine… ».
2. Optionnel : ajoutez `FR-CORSE` à `delivery.allowed_zones` dans la configuration du plugin sur le serveur : `20000`
   passe l'étape adresse, puis aucun mode de livraison n'est proposé tant que `carriers.yaml` n'a pas de règle `FR-CORSE`
   (comportement attendu). Remettez la valeur d'origine ensuite.
3. Personnalisez `delivery.unsupported_message` : le texte s'affiche à la place du message par défaut.

## 9. Taxes, loi Darcos, page livre (lot du 2026-10-13, branche `claude/taxes-darcos`)

Prérequis : déploiement, cache Grav vidé. Dans la configuration du plugin, vérifiez `taxes.default: TVA_5_5` ;
l'ancienne clé `vat_rate` n'est plus lue.

1. **Prix HT → TTC** : ouvrez la page de chaque livre. Le prix affiché doit être l'ancien prix public (ex. 12,95 €,
   fiche à 12.2749 HT ; 15 € pour 14.218 HT). Notez tout écart de centime. Le catalogue, le panier, le montant Stripe et la facture
   affichent le même TTC. L'export CSV (colonne « Prix ») donne le TTC.
2. **Darcos, panier < 35 €** (un livre à 12,95 €) : à l'étape livraison, aucun mode ne coûte moins de 3,00 € TTC.
3. **Darcos, panier ≥ 35 €** : aucun mode à 0,00 € ; un mode « offert » apparaît à 0,01 € s'il est activé
   (mode `livraison_offerte` de `carriers.dist.yaml`, à recopier dans le `carriers.yaml` du serveur et à activer).
4. **Retrait à l'atelier** (désactivé pour le moment) : la contrainte « Loi Darcos » l'exclut (« Modes non concernés ») ; une fois activé, le retrait reste à son prix (0 €).
5. **Route webhook après correctif** : `https://VOTRE-SITE/stripe/webhook` en GET répond `405` (et non une erreur 500 sur tout le site) ; le reste du site fonctionne.
6. **Page livre** : titre, sous-titre, auteur, bloc d'achat en tête, section « À télécharger », affichage correct à moins de 720 px de large.

## 10. Livraison HT, suivi, configuration déployée (lot du 2026-10-13, branche `claude/config-deploy-2`)

2. **Prix de livraison HT dans le tunnel** (validé dans le simulateur) : panier d'un livre à 12,95 € TTC (≈ 340 g avec emballage, 29 mm). Attendu à l'étape livraison :
   « Lettre suivie » **8,35 €** (7,91 € HT × 1,055) et « Colissimo Domicile » **7,99 €** (7,57 € HT × 1,055, grille Facilité 2025). Le « Retrait à l'atelier » n'apparaît plus.
3. **Plancher Darcos** : avec un panier de 35 € ou plus, « Livraison standard (symbolique dès 35 € d'achat) » apparaît à **0,01 €** ; sous 35 €, elle n'apparaît pas.
4. **Emballage** : dans Plugins → Simple Shop → Livraison, passez le poids d'emballage à 2000 g : « Lettre suivie » disparaît (limite 2 kg). Remettez 40.
5. **Numéro de suivi** : Admin2 → Boutique → une commande → « Suivi du colis » : saisir `6A12345678901`, « Enregistrer le suivi » → le lien « Page de suivi » apparaît.
   Passer la commande en « Expédiée » : l'e-mail client contient le numéro et le lien « Suivre mon colis ». Une saisie invalide (`<b>`) est refusée.

## 11. Transporteurs : administration et Colissimo (lot du 2026-10-13)

1. **Clavier et lecteur d'écran** (onglets au clavier validés) : Tab dans les formulaires des modes ; annonces d'état lues par un lecteur d'écran (Orca, NVDA…).
2. **Erreurs** : provoquez une erreur serveur (ex. YAML invalide dans le mode avancé) : le message est une alerte (`role=alert`).
3. **Colissimo, identifiants réels** (le refus sans identifiants est validé) : Plugins > Simple Shop Colissimo, saisissez les identifiants, activez `pickup_enabled`,
   puis « Tester la connexion » (Transporteurs). Attendu : « Réussi ».
4. **Tunnel sans JavaScript** : choisissez « Colissimo point retrait » ; une liste de points apparaît, « autre ville » fonctionne, le point choisi figure aux étapes 3 et 4 et dans les e-mails.

## 12. Transporteurs simplifiés : forfait, tarif du plugin, grille repliée, CSV, contraintes globales (lot du 2026-10-14)

1. **Contraintes globales** : la section « Contraintes globales » montre, dans cet ordre :
   - « Loi Darcos (frais de livraison des livres) » : type de produits (blueprint) `book`, « Retrait à l'atelier » coché dans « Modes non concernés »,
     deux règles avec leur commentaire ;
   - « Livraison offerte à partir de 50 € » (exemple d'offre) : aucun type coché, une règle « Total TTC de 50 », « Prix imposé » 0,01.
   Plus d'encart « Plancher légal ».
   - Simulateur (340 g, 12,95 €, type `book` coché) : un forfait à 0 € affiche 3,00 € et, dans « Contraintes et remarques », « « Loi Darcos… » : 0,00 € → 3,00 € — commentaire ».
     Décochez `book` : la contrainte ne s'applique plus. À 60 € : 0,01 € pour tous les modes sauf le retrait.
   - « Monter » / « Descendre » changent l'ordre (enregistrez puis remettez l'ordre d'origine).
   - Une règle sans valeur, ou « Total TTC de » supérieur à « jusqu'à », est refusée avec un message.
2. **Forfait** : sur un mode, choisissez « Forfait », saisissez 5, enregistrez : la marge disparaît du formulaire ; simulateur 5,28 € TTC (5 × 1,055) pour tout poids.
3. **Tarif du plugin** (Colissimo domicile) : résumé « Grille du plugin : 33 lignes, de 6,72 € à 36,39 € HT… », grille visible en lecture seule sous « Voir la grille du plugin ».
4. **Personnaliser cette grille** : bascule en « Grille personnalisée » avec la grille du plugin pré-remplie et repliable ; colonnes panier / épaisseur masquées
   sauf si cochées ou utilisées. Enregistrer, puis « Rétablir les valeurs du plugin » revient au tarif du plugin.
5. **CSV** : « Exporter en CSV » s'ouvre correctement dans LibreOffice / Excel (accents, colonnes, virgule décimale). Modifiez un prix,
   « Importer un CSV… » ce fichier : « N lignes importées », enregistrez. Un fichier avec une zone ou un prix invalide est refusé avec le numéro de ligne.
6. **Tunnel** : avec un forfait à 0 € comme seul mode actif, l'étape livraison propose 3,00 € (livres < 35 €) ou 0,01 € (≥ 35 €), par la contrainte « Loi Darcos ».

## 13. Retrait sur place (lot du 2026-10-14)

1. Transporteurs > « Retrait à l'atelier » : formulaire sans grille ni marge (nom, description, prix, adresse, distance maximale).
   Saisissez l'adresse de l'atelier et 20 km, enregistrez : « Lieu localisé : … » avec l'adresse reconnue (sinon « Adresse introuvable »).
2. Simulateur : code postal / ville d'une commune proche → le retrait est proposé avec « Client à X km » ; une commune lointaine → « Client à X km… (au plus 20 km) ».
3. Tunnel : un client d'une commune proche voit « Retrait à l'atelier — Gratuit, à environ X km de chez vous » ; un client lointain ne le voit pas.
4. Créez un mode « Retrait sur place » depuis « Ajouter un mode manuel » (type « Retrait sur place ») puis supprimez-le.
5. Le service de géocodage reçoit bien les paramètres `q` et `postcode` (vérifier qu'une ville homonyme d'un autre département n'est pas choisie).

## 15. Tunnel après nettoyage de la dette technique (lot du 2026-10-14)

1. Commande complète avec le paiement de test : chaque étape enchaîne normalement ; un formulaire de panier rechargé (nonce expiré) affiche
   « Votre session de panier a expiré » sans page blanche.
2. **Rapidité et facture** : après « Simuler un paiement réussi », la confirmation s'affiche immédiatement (avant : ~25 s) et l'e-mail
   de confirmation contient la facture PDF ; `logs/simple-shop/invoices.log` ne contient plus « database is locked ».
3. Liens « Mon panier » et « Se connecter » de l'en-tête, et bouton « Ajouter au panier » : toujours fonctionnels.

## 16. Ajustements du 14/10/2026 (soir)

1. Plugins > Simple Shop Colissimo : un encadré en tête indique qu'un contrat Colissimo Facilité ou Entreprise Privilège est nécessaire pour les points de retrait.
2. Tunnel, étape livraison : les modes sont du moins cher au plus cher et le premier est coché.

## 17. Types de produits et prix (lot du 2026-10-14, étapes 2 et 3)

Rien ne doit changer à l'écran : les prix doivent être les mêmes qu'avant.

1. Accueil et `/livres` : le prix de chaque livre paru est inchangé (ex. 12,95 €) ; un livre à paraître affiche « Prévente : … » avec le prix de prévente TTC.
2. Page d'un livre paru : zone d'achat, prix, stock, « Ajouter au panier » ; panier → même prix, même couverture, même titre.
3. Page d'un livre à paraître avec prix de prévente : « En prévente — Sortie officielle le … », bouton « Précommander », prix de prévente dans le panier.
4. Livre à paraître **sans** prix de prévente, ou « vendu ailleurs » : pas de zone d'achat.
5. Étape livraison : frais identiques à avant pour le même panier (loi Darcos sous 35 € de livres, livraison offerte dès 50 €).
6. Boutique > Transporteurs, contraintes globales : la liste « Types de produits (blueprint) » propose toujours `book`.
7. Plugins > Simple Shop : nouveau champ « Devise » (EUR) ; l'enregistrer ne change rien.
8. Export CSV des livres : colonne « Prix » identique (prix de prévente pour un livre à paraître).
9. Admin2 : ouvrir, modifier et enregistrer une fiche livre (le nouveau bloc `shop:` du blueprint ne doit pas gêner l'éditeur) ; Outils > Journaux : aucune ligne « Type de produit … introuvable ».

## 18. Ordre des modes de livraison (correctif du 14/10/2026, 18 h)

1. Panier d'un livre → étape livraison : modes du moins cher au plus cher, **le premier coché**.
2. Choisir le plus cher, continuer, revenir à l'étape livraison (lien ou bouton « Précédent » du navigateur), puis recharger la page : c'est **toujours le moins cher** qui est coché.
3. Si l'ordre est encore faux : noter les modes affichés et leurs prix (capture d'écran) dans `OBSCURE.md` — la simulation avec la configuration du serveur donne un ordre croissant.

## 19. Centimes, taxe par ligne, facture EN 16931 (lot du 2026-10-14, étape 4)

1. Commande complète (paiement Stripe de test) : montant débité = total affiché (ex. 2 × 12,95 € + 7,99 € = 33,89 €) ; même montant dans Stripe.
2. Paiement hébergé (sans JavaScript) : chez Stripe, lignes « livre » et « Frais de livraison » aux mêmes prix, devise EUR.
3. Facture PDF reçue : lignes TTC, « Total HT », « TVA 5,5 % sur … », éventuellement « Arrondi 0,01 EUR », « Total TTC » = montant payé.
4. Déposer le PDF sur le testeur Factur-X de la FNFE-MPE (https://services.fnfe-mpe.org/) : profil **EN 16931** reconnu, aucune erreur de calcul.
   Erreur attendue tant que la configuration n'est pas complète : numéro de TVA du vendeur absent (`seller.vat_number`, règle BR-S-02).

## 20. Prix saisis TTC, mention « TTC », choix de livraison mémorisé (lot du 2026-10-14, 18 h 30)

1. Après le déploiement, ouvrir une page du site : les fiches livres sont converties une fois pour toutes. Ouvrir un livre dans Admin2 :
   « Prix du produit » vaut le prix TTC affiché sur le site (ex. 12.95 au lieu de 12.2749), même chose pour le prix de prévente.
2. Prix publics inchangés partout (accueil, `/livres`, page livre, panier).
3. Modifier un prix dans Admin2 (ex. 13.50) : le site affiche 13,50 € TTC. Une valeur à 3 décimales est refusée à l'enregistrement.
4. Champ « Prix saisis » d'une fiche : « HT » → 13.50 donne 14,24 € TTC ; le remettre sur « Réglage de la boutique ».
5. Partout sur le site public (cartes, page livre, panier, livraison, paiement, confirmation), un petit « TTC » suit les prix (pas après « Gratuit »).
6. Livraison : choisir un mode qui n'est pas le moins cher, continuer, revenir à l'étape livraison → **votre choix** reste coché.
   Modifier la quantité du panier puis revenir → le moins cher est coché.
7. Outils > Journaux : une ligne « Prix des fiches convertis de ht en ttc : … prix sur … fiches », aucune erreur.

## 22. Facture conforme au testeur FNFE (lot du 2026-10-15)

1. Plugins > Simple Shop > Facturation électronique : renseigner raison sociale, adresse, **SIRET** (14 chiffres) et **n° de TVA** ;
   vérifier « Cadre de facturation » (B2) et les trois mentions.
2. Passer une commande de test (une facture déjà générée ne se régénère pas : il en faut une nouvelle).
3. Déposer le PDF sur le testeur : attendu **0 erreur** (PDF/A-3, EN 16931, BR-FR). Me transmettre le rapport s'il en reste.

## 23. Panier par lignes (étape 6, lot du 2026-10-15)

Aucun produit du site n'a d'options : le panier doit se comporter **exactement comme avant**.
1. Ajouter un livre, puis le même : une seule ligne, quantité 2. Modifier la quantité, retirer la ligne : fonctionne.
2. Un panier commencé **avant** le déploiement (autre navigateur) est toujours là après.
3. Commande complète : confirmation, e-mail et administration identiques à avant.

## 24. Taxes (lot du 2026-10-15)

Pour ce site (livres seulement), **aucun prix ne doit changer** : livres et livraison à 5,5 %.
1. Plugins > Simple Shop > Taxes : réglages, « Type de taxe par défaut », « Types de taxe et leurs taux » (6 types, un taux FR chacun),
   « Type de taxe de chaque type de produit » ; les listes déroulantes sont remplies. Enregistrer sans rien changer : pas d'erreur, et les
   6 types sont toujours là (Admin2 sait-il afficher une liste dans une liste ? sinon, le noter). Enregistrer sans rien changer : pas d'erreur. Une modification faite ici est conservée : le déploiement du site n'écrase pas la configuration.
2. Boutique > Taxes : résumé, « book → Taux réduit », puis 6 blocs de types **ouverts**, chacun avec son taux dans un bloc **fermé**
   (résumé « FR · 5,5 % · TVA 5,5 % »). Ouvrir un taux, changer le libellé : le résumé suit. « Ajouter un taux (autre pays…) » sur
   « Taux réduit » : BE, 6 → « Enregistrer les types de taxe » : message « 6 types de taxe, 7 taux enregistrés », et le bloc reste à jour
   après rechargement. Supprimer ce taux et réenregistrer. Essayer de supprimer « Taux réduit » (utilisé par book) : refus expliqué.
   « Exporter en CSV » : fichier lisible dans un tableur (une ligne par taux) ; le réimporter tel quel : même résultat.
3. Fiche livre (Admin2) : champ « Type de taxe » (vide = type du type de produit). Le mettre sur « Taux normal » : le prix affiché passe au taux de 20 %
   (prix saisi TTC : le TTC reste, le HT change) ; remettre vide.
4. Commande complète : montants, facture (« TVA 5,5 % sur … ») et testeur FNFE identiques à avant.

## 25. Ventes manuelles : factures, acomptes, demandes de paiement (lot du 2026-10-19)

Préalable (configuration du site) : `invoicing.manual: true`, vendeur (`seller.*`), `payments.bank_transfer` (titulaire, IBAN,
BIC, `enabled: true`), `payments.methods.manual` (ex. `[bank_transfer, stripe]`) ; site sans boutique : `shop.public: false`.

1. Admin2 > **Ventes** (ancien menu « Boutique ») : onglet **Factures** présent ; bouton « Nouvelle commande manuelle » sous les
   tableaux.
2. Saisir un client connu par son e-mail → « Rechercher le client » pré-remplit ses coordonnées. Organisation : prix **HT** ;
   particulier : **TTC**. Deux lignes, commentaire → « Créer le brouillon ». Le brouillon s'ouvre ; « Aperçu (PDF) » : filigrane
   « APERÇU — sans valeur ». « Modifier le brouillon » rouvre la saisie.
3. Émettre une **facture d'acompte** (30 %) avec envoi : e-mail reçu avec le PDF, le commentaire et le lien « Voir et régler en
   ligne ». Le lien ouvre la page du document (sans compte) : montant, échéance, choix virement / carte.
4. Virement : l'IBAN et la référence à indiquer s'affichent. Dans Admin2, « Enregistrer un paiement reçu » : choisir le document,
   moyen « Virement », « Envoyer un reçu » → l'acompte passe « Réglée », le client reçoit la **facture acquittée**.
5. Émettre la **facture** finale : l'acompte y est déduit (« Net à payer »). La payer en ligne par **Stripe** (mode test) :
   retour sur la page « Merci, votre paiement est enregistré », reçu par e-mail, onglet Factures « Réglée ».
6. **Demande de paiement** réglée par carte → la facture (FACT-…) est émise automatiquement, déjà réglée, et envoyée acquittée.
7. Stripe : fermer l'onglet juste après le paiement (sans revenir sur le site) → le webhook enregistre le paiement (vérifier le
   document « Réglée » quelques secondes plus tard ; aucun doublon si le client revient ensuite).
8. Onglet Factures : filtres Type / Règlement (« En retard » après l'échéance) / Recherche ; « Transmission » modifiable
   (e-reporting) ; « PDF », « Acquittée », « Renvoyer », « Copier le lien ».
9. Testeur FNFE : déposer une facture à régler et une facture d'acompte (vrais SIRET, mention de franchise).
