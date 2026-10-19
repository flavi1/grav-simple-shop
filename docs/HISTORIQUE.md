# Historique — ce qui a été délibérément fait (simple-shop)

> Repris le 15/10/2026 de `private/prompts/DONE.md` du site d'origine (senonevero.flavieng.com), en ne gardant que ce qui concerne
> ce plugin. « Preuve » = fichier, test ou procédure qui le montre. Les numéros (A1, T4, O6, Q9…) sont ceux des anciens fichiers de
> suivi du site ; ils ne renvoient à rien dans ce dépôt mais sont gardés pour pouvoir retrouver une discussion.


## Phase B — Migration de simple-shop vers `mvc`

| Tâche | État | Preuve |
|-------|------|--------|
| B1 Déclaration des routes | Fait | `onMvcRegisterRoutes()` dans `simple-shop.php`, dépendance `mvc` dans `blueprints.yaml` |
| B2 `CartController extends AbstractController` | Fait | rendu via `renderStep()` -> `$this->view()` |
| B3 Nettoyage de `simple-shop.php` | Fait | plus de `isShopRoute`, `onPageInitialized`, `matchedMethod`, `identifyRouteHandler` |

## Phase C — Logique client

| Tâche | État | Preuve |
|-------|------|--------|
| C1 `CustomerController` / `identify` | Fait | `identify()`, `sendOtpEmail()` réutilisable, nonce `shop-identify` |
| C2 `verifyEmail` + `back_url` | Fait | `verifyEmail()`, `safeBackUrl()` (anti open-redirect) ; `steps/step2EmailValidation.php` supprimé |
| C3 Page « Mes informations » | Fait | route `/mon-compte`, `account()`, nonce `shop-account`, `account.html.twig` |
| C4 Rebranchement du tunnel | Fait | `step1Cart` -> `resolvePostSaveDestination()` ; garde-fou e-mail dans `step2Delivery` |

## Phase D — Sécurité OTP / tokens

| Tâche | État | Preuve |
|-------|------|--------|
| D1 Rolling tokens / auto-login | Fait (déconnexion ajoutée : T10) | `attemptAutoLogin()` (rotation, bannis, cookie nettoyé sur anomalie), `logout()`, `isHttps()` uniforme |
| D2 TTL OTP | Fait | `expires_at` (10 min), purge de `email_verification_target`, `hash_equals`, 5 tentatives |
| D2 Anti-spam 60 s | Fait (T1, 2026-10-13) | voir la section « Mise à jour du 2026-10-13 » |
| D3 Messages flash | Fait (option A) | `CartController::addMessage()` -> `AbstractController::addMessage()` -> `NoJs::addMessage()` ; plus aucune occurrence de `shop_messages` dans le code |

## Phase E — Qualité et dette

| Tâche | État | Preuve |
|-------|------|--------|
| E1 Config des routes | Fait en grande partie | `Config/Routes.php`, `twig_vars['shop_routes']`, templates du tunnel migrés (reste : voir TODO) |
| E2 Injection de dépendances | Fait en grande partie | `CustomerManager(Session $session, Grav $grav)` typé et documenté |
| E3 README du plugin `mvc` | Fait | `mvc/README.md` cohérent avec l'API réelle |
| E4 Commande, étape finale | Fait en grande partie | `toOrder()` en transaction SQLite (`order`, `order_item`), référence `CMD-AAAAMMJJ-XXXXXX`, étape de confirmation + template, `getCartPhysicalMetrics()` corrigée (reste : déstockage, voir TODO) |
| E5 Robustesse stock | Fait | `getVirtualStock()` -> `0`, `getProductPrice()` -> `null` si non vendable. La « condition de stock » de `add_item` n'est **pas un bug** : `virtualStock` exclut déjà la réservation du panier courant, donc `virtualStock >= 1` est correct (formule documentée dans `CartStorage::revalidateStock()`) |
| E6 Correctifs de templates | Fait en grande partie | ids uniques (`keepdata-description`), « * » retiré sur `keep-data`, `address-line3` corrigé, `shop_routes` utilisé (reste : voir TODO) |
| E7 Callbacks Stripe | Fait (option a) | plus de mapping `handleStripeSuccess/Cancel` ; paiement finalisé en ligne à l'étape paiement |
| E8 Stripe : nettoyage | Fait | `echo`/`console.log` supprimés ; idempotence du `PaymentIntent` (réutilisation + mise à jour du montant) |

## Travaux réalisés en plus du plan initial

- **Facturation Factur-X** : `Invoicing/*` (`Invoice`, `InvoiceManager`, `FacturXXmlBuilder`, `InvoiceLogger`), table `invoice`, PDF joint à l'e-mail de confirmation, téléchargement admin, journal `logs/simple-shop/invoices.log`.
- **Commandes** : `OrderManager`, page admin `/admin/simple-shop-orders` (changement de statut, e-reporting), événement `onSimpleShopOrderStatusChanged`.
- **E-mails** : `OrderMailer` (confirmation client + notification vendeur), template OTP.
- **Profil client** : `keep-data`, profil persistant JSON, restauration du panier, `handleUserData()` partagé.
- **Newsletter** : désabonnement par lien signé HMAC (`unsubscribeNewsletter`).
- **Bannissement** : registre `banned.list`, contrôles à chaque point d'entrée (voir `OBSCURE.md` pour l'absence d'écran admin).
- **Revalidation du stock** du panier à chaque étape (`CartStorage::revalidateStock()`).
- **Admin transporteurs** : page `/admin/simple-shop-carriers`.
- **Migration SQLite** additive pilotée par le `mtime` de `shop.sql` (corrige les verrous « database is locked »).


## Mise à jour du 2026-10-13 (branche `claude/maj-2026-10-13`, un commit par étape)

| Tâche | Fait | Détail |
|-------|------|--------|
| T2 + O7 Config hors dépôt | Oui | `/private/grav/config` exclu du déploiement et d'Git, sauf modèles `*.dist.yaml` sans secret ; clés Stripe retirées de `simple-shop.yaml` et du blueprint |
| T1 Anti-spam OTP | Oui | `Services/OtpThrottle` : délai 60 s par session (`canRequestNewOtp`/`markOtpSent`), 5 envois/h par e-mail, 20/h par IP (`otp.*`), 100 % serveur ; le plafond de 4 adresses par session est abandonné |
| T3 Déstockage | Oui | `InventoryManager::decrementPhysicalStock()` appelé après le commit de `toOrder()` (verrou dédié, `-1` intact, plancher 0) |
| T6 Double abonnement | Oui | un seul `onTwigSiteVariables` |
| T17 + O1 Adresses, zone | Oui | champs `street`, `postal-code`, `city` (obligatoire), `address-complement` (optionnel) ; `Services/AddressFields` normalise les anciennes clés `address-line*` ; France métropolitaine hors Corse seulement, message clair sinon |
| T9 + T18 Paiement | Oui | `use_javascript` (case à cocher du plugin Stripe) : partial JS + `<noscript>`, ou page hébergée Stripe sans JS ; route de retour `/checkout/payment-return` ; vérification statut + montant ; commande idempotente (table `order_payment`) |
| T19 Commandes | Oui | `order_status_history`, événement avec `changed_by`, recherche/filtres, export CSV, e-mails de statut |
| T20 + T16 Admin2 | Oui (à tester) | API `/api/v1/simple-shop/*`, permissions `api.simple-shop.*`, page `admin-next/pages/simple-shop.js` (commandes, transporteurs, bannis) ; ancienne administration retirée de `simple-shop.php` |
| T10 Déconnexion | Oui | `/mon-compte/deconnexion` (POST + nonce) et bouton dans la page compte |
| T12 README | Oui | `plugins/simple-shop/README.md` |
| T14 AGENTS.md | Oui | réécrit |
| O5 `is_book_seller` | Assumé | abandon volontaire ; la page librairies renseigne les points de vente physiques |

Bugs réels corrigés en passant : ville de l'acheteur dans le XML Factur-X ; la page de
confirmation exposait une commande via `order_id` dans l'URL (la référence vient maintenant
de la session) ; `onTwigSiteVariables` s'exécutait deux fois.

## Mise à jour du 2026-10-13 (deuxième lot, branche `claude/maj-2026-10-13b`)

| Tâche | Fait | Détail |
|-------|------|--------|
| Webhook Stripe | Oui (à brancher) | Route `/stripe/webhook`, événement `payment_intent.succeeded`, signature vérifiée, secret `webhook_secret` ; instantané de commande `pending_payment` enregistré au démarrage du paiement ; `OrderFinalizer` partagé avec le retour client (idempotent, e-mails une seule fois) ; le plugin `mvc` expose `Request::getRawBody()` |
| Recherche admin | Oui | insensible aux accents et à la casse (`OrderManager::fold`, fonction SQLite `shop_fold`) |
| Corse / outre-mer, zones | Oui | `plugins.simple-shop.delivery.allowed_zones` (défaut `FR-METRO`) et `delivery.unsupported_message` ; le plugin résout toutes les zones (`ZoneResolver`) ; ce site reste limité à la France métropolitaine |
| Groupe gestionnaire boutique | Oui (à créer sur le serveur) | `shop-manager` dans `config/groups.dist.yaml` |
| Conception transporteurs | Rédigée | `CONCEPTION-TRANSPORTEURS.md`, questions Q1 à Q8 dans `OBSCURE.md` |


## Mise à jour du 2026-10-13 (troisième lot, branche `claude/taxes-darcos`)

| Tâche | Fait | Détail |
|-------|------|--------|
| Bug sur tout le site (webhook) | Oui | autoloader du plugin Stripe déclaré dans le constructeur, abonnement statique à `onMvcRegisterRoutes`, `try/catch` autour de `add()` (déjà sur `main`, 4f37d8e) |
| Taxes | Oui | `TaxManager` ; clés `taxes.default`, `taxes.rates`, `taxes.prices_entered` (remplacent `vat_rate`) ; prix des fiches saisis HT, convertis en TTC à la source ; 22 prix de livres convertis (4 décimales) |
| Loi Darcos | Oui (à valider juridiquement, Q12) | `LegalShippingFloor` : 3,00 € TTC sous 35 €, 0,01 € TTC au-delà ; clés `delivery.book_law.*` ; `legal_floor: false` par mode ; encart dans l'onglet Transporteurs ; mode de démonstration `livraison_offerte` (désactivé) |
| Conception transporteurs | v2 rédigée | `CONCEPTION-TRANSPORTEURS.md` intègre Q1 à Q8 ; questions Q10 à Q15 |
| Groupe gestionnaire | Procédure détaillée | `TODO.md` |

## Mise à jour du 2026-10-13 (quatrième lot, branche `claude/config-deploy-2`)

| Tâche | Fait | Détail |
|-------|------|--------|
| Prix de livraison HT | Oui | `delivery.prices_entered: ht` (défaut) : prix de `carriers.yaml` hors taxe + TVA du site, puis plancher Darcos ; `ttc` toujours possible |
| Marge | Oui | `margin: {fixed, percent}` par mode (YAML validé) ; pas de marge sur un palier offert (prix 0) |
| Emballage | Oui (valeurs de départ) | `delivery.packaging` 40 g + 6 mm, réglable dans la page du plugin ; les épaisseurs des exemplaires s'additionnent (avant : maximum) |
| Tarifs 2026 | Oui (à confirmer) | lettre suivie (grille lettre verte + 0,50 €) et Colissimo domicile ramené en HT ; Chronopost retiré (tarifs d'exemple) ; retrait atelier désactivé ; démo « livraison symbolique dès 35 € » activée |
| Numéro de suivi | Oui | table `order_tracking`, `PATCH /orders/{ref}/tracking`, champ dans Admin2, lien dans l'e-mail « expédiée » (`delivery.tracking_url`) |
| OBSCURE / AGENTS | Oui | Q1–Q8, Q11, Q14, Q15 closes ; décisions dans `AGENTS.md` |

| Recherche tarifs La Poste (2026-10-13) | Oui | lettre verte disponible pour les pros (mêmes prix nets) ; suivi inclus dans Colissimo, +0,50 € pour la lettre verte ; grille HT Facilité 2025 mise dans `carriers.yaml` (Colissimo domicile) ; détails dans la conception §5.2 |

## Transporteurs : contrat, plugin Colissimo, administration (2026-10-13)

| Tâche | État | Preuve |
|-------|------|--------|
| Contrat fournisseur + événement `onSimpleShopRegisterCarriers` | Fait | `Delivery/CarrierProviderInterface.php`, `CarrierCatalog`, `ShippingManager` |
| Plugin `simple-shop-carrier-colissimo` (grille Facilité HT 2025, points de retrait) | Fait, testé sur fixtures | `tests/run.php` ; service réel non testé (pas d'identifiants) |
| Point de retrait dans le tunnel (sans JavaScript), e-mails et récapitulatifs | Fait | `CartController::step2Delivery`, templates `step2/3/4`, `pickup_point` |
| Administration des transporteurs (Admin2) : modes, marge, offert dès, grille, plancher, simulateur, test de connexion, YAML avancé | Fait | `admin-next/pages/simple-shop.js`, API `PUT/DELETE /carriers/{key}`, `POST /carriers/simulate` |

## Administration des transporteurs : validation en navigateur réel et corrections (2026-10-13)

Première passe dans Admin2 réel (Grav 2.0.24, Admin 2.1.9), faite via l'extension Claude in Chrome avec le compte de test.
Console sans erreur ; tous les appels `/api/v1/simple-shop/carriers` (GET, PUT, DELETE, POST simulate) en 200.

| Point | État | Détail |
|-------|------|--------|
| Menu « Boutique », trois onglets | Validé | `MANUAL_TESTS.md` §1.2, §1.3 |
| Liste des commandes | Validé (partiel) | 2 commandes affichées, filtres et export présents ; détail, statut, PDF et CSV restent à tester (§1.4) |
| Affichage Transporteurs | Validé | encart (3,00 € / 35,00 € / 0,01 €, TVA 5,5 %, emballage 40 g / 6 mm), modes plugin et manuels (§9.6, §11.1) |
| Simulateur | Validé | 340 g, 29 mm, 12,95 € : Colissimo Domicile 7,57 € HT → 7,99 € TTC, Lettre suivie 7,91 € HT → 8,35 € TTC ; retrait et « Livraison standard » non proposés, avec leur raison (§10.2) |
| Marge d'un mode plugin, puis « Rétablir les valeurs du plugin » | Validé | marge 0,6 → 8,17 € HT / 8,62 € TTC, badge « modifié » puis retour aux valeurs d'origine (§11.2) |
| Création / suppression d'un mode manuel | Validé | mode « Test Claude », grille à 5 € HT, puis suppression ; configuration laissée intacte (§11.3) |
| Onglets au clavier | Validé (flèches) | bouclage, `aria-selected` et `tabindex` corrects (§11.4) |
| Test de connexion Colissimo sans identifiants | Validé | message « Identifiants incomplets… » (§11.5) ; reste le test avec de vrais identifiants |
| D1 Thème sombre : encart et badges illisibles | Corrigé | couleurs d'état définies par paires fond + texte (contraste ≥ 4,5:1 vérifié), variante sombre choisie d'après la couleur de texte d'Admin2 |
| D2 Après enregistrement : mode replié, simulateur vidé, message hors de vue | Corrigé | le mode reste ouvert, message sous ses boutons, simulateur conservé et recalculé |
| D3 Onglets : Début / Fin | Corrigé | modèle WAI-ARIA Tabs |
| D4 Focus perdu après enregistrement | Corrigé | focus sur le message du mode (ou en tête de liste après suppression) |
| D5 Mineurs | Corrigé | champ « Nom du nouveau mode » vidé, brouillon placé à la fin de la liste, succès en `role=status` |

Les corrections D1 à D5 ont été vérifiées dans Chromium headless avec une fausse API ; contre-test en réel : `MANUAL_TESTS.md` §11.7.

### Contre-tests des corrections D1 à D5 (2026-10-13, Admin2 réel, via Claude in Chrome)

Après déploiement ; configuration laissée dans son état d'origine ; console sans erreur, appels `/carriers` en 200 ; aucun nouveau défaut.

| Point | État | Détail |
|-------|------|--------|
| D1 Thème sombre et clair | Validé | encart du plancher, badges des modes (Plugin, Manuel, Actif, Désactivé, Indisponible) et badge « Payée » lisibles dans les deux thèmes |
| D2 / D4 Message et focus | Validé | Enregistrer (marge 0,6 → 8,17 € HT, 8,62 € TTC), Rétablir (retour à 7,99 € TTC), Créer (« Test Claude 2 »), Supprimer : mode ouvert, message sous les boutons ou en tête de liste, focus dessus, simulateur recalculé |
| D3 Début / Fin | Validé | Fin → « Adresses bannies », Début → « Commandes », `aria-selected` et focus suivent |
| D5 Nouveau mode | Validé | champ vidé, brouillon à la fin de la liste, focus sur « Nom affiché au client », texte d'aide présent |
| Messages de haut de page | Validé (adresses bannies) | bannir / débannir `test-claude@example.invalid` : message en `role=status` ; commandes et `role=alert` non vérifiés |
| Adresses bannies | Validé (partiel) | bannissement et débannissement, confirmations, liste à jour ; reste le blocage sur `/identify` |
| Marge invalide | Validé | navigateur : `-1` bloqué (min = 0), `abc` impossible ; serveur : refus 422 couvert par `simple-shop/tests/mode-editor.php` (ajouté ce jour) |

## Transporteurs simplifiés (2026-10-14, suite à l'audit `AUDIT-TRANSPORTEURS.md`)

| Point | État | Preuve |
|-------|------|--------|
| Type de calcul par mode : forfait / tarif du plugin / grille personnalisée | Fait | `pricing`, `flat_price` (`CarrierCatalog`, `ModeEditor`, `ShippingManager::evaluate`, `CarrierConfig`) ; anciens fichiers compatibles |
| Grille repliée : résumé, grille du plugin en lecture seule, « Personnaliser cette grille », colonnes avancées masquées | Fait | `admin-next/pages/simple-shop.js` |
| Import / export CSV de la grille (dans le navigateur, validé par le serveur à l'enregistrement) | Fait | idem |
| Préréglage « Livres France » proposé une fois (forfait 0 € au plancher légal = 3,00 € / 0,01 €), option « désactiver les autres modes » | Fait | `POST /carriers/preset`, bloc `setup` de `carriers.yaml` |
| Tests | Fait | `simple-shop/tests/carriers-pricing.php`, `tests/mode-editor.php` ; interface testée dans Chromium headless avec une fausse API ; en réel : `MANUAL_TESTS.md` §12 |

## Contraintes globales (2026-10-14, demande du propriétaire)

| Point | État | Preuve |
|-------|------|--------|
| Contraintes globales génériques : nom, types d'articles concernés, modes exclus, règles (poids de/à, montant TTC des articles concernés de/à, minimum / maximum / prix imposé, valeur, commentaire), ordre de la liste | Fait | `Delivery/ShippingConstraints.php`, bloc `constraints` de `carriers.yaml`, `PUT /carrier-constraints` |
| Type d'un article : `product_type` de la fiche, sinon son gabarit (`book`) | Fait | `ShippingManager::productType()`, montants par type dans `getCartPhysicalMetrics()` |
| simple-shop sans rien de propre aux livres : loi Darcos = contrainte de la configuration du site (`private/prompts/config`, `carriers.dist.yaml`) ; `delivery.book_law` et `legal_floor` retirés de l'écran et des réglages (conversion automatique conservée, voir `LATER.md` L-CONV) | Fait | `simple-shop.yaml`, `blueprints.yaml` |
| Préréglage « Livres France » et encart « Plancher légal » supprimés ; commentaires des règles affichés dans l'écran et le simulateur | Fait | `admin-next/pages/simple-shop.js` |
| Simulateur : types d'articles du panier, contraintes appliquées (avant → après, commentaire) | Fait | idem |
| Tests | Fait | `simple-shop/tests/constraints.php` (dont l'exemple Darcos + « offerte dès 50 € ») ; interface testée dans Chromium headless ; en réel : `MANUAL_TESTS.md` §12 |
| Vocabulaire : « Types de produits (blueprint) », « Total TTC » ; contrainte d'exemple « Livraison offerte à partir de 50 € » ajoutée à la configuration du site (après la loi Darcos, retrait exclu) | Fait | `private/prompts/config/plugins/simple-shop/carriers.yaml` |

## Retrait sur place, fin de la conversion `delivery.book_law` (2026-10-14)

| Point | État | Preuve |
|-------|------|--------|
| Mode spécial « retrait sur place » (`kind: local_pickup`) : prix unique (gratuit par défaut), adresse du lieu, distance maximale ; proposé si la commune du client est plus proche | Fait | `ShippingManager::evaluateLocalPickup()`, `ModeEditor::sanitizeLocalPickup()`, écran Admin2, tunnel (`CartController::customerTown()`, distance affichée) |
| Géocodage libre / ouvert : Base Adresse Nationale (Géoplateforme IGN, sans clé), URL réglable (`delivery.geocoding`), client localisé à la commune seulement, cache de session | Fait | `Delivery/Geocoder.php` |
| Conversion de l'ancien réglage `delivery.book_law` retirée (le serveur a son bloc `constraints`) ; `LegalShippingFloor.php` à supprimer | Fait | `REMOVE.md` |
| Tests | Fait | `tests/local-pickup.php` (géocodeur simulé, distance, éligibilité, saisie) ; interface dans Chromium headless ; en réel : `MANUAL_TESTS.md` §13 |


## Dette technique (LATER.md) soldée (2026-10-14)

| Point | État | Preuve |
|-------|------|--------|
| T4 `CartController` conforme au contrat MVC : 19 `redirect(); exit;` → `return $this->redirect()`, 23 `filter_input`/`$_POST`/`$_GET`/`$_SERVER` → `Request` (`post()`, `get()`, `isPost()`, `postString()` pour refuser les tableaux forgés) | Fait | `tests/checkout-flow.php` : tunnel complet, chaque étape retourne une `Response` |
| T5 `AbstractController` : session lue sur `$this->grav` (et non sur le paramètre nullable) | Fait | `mvc/classes/AbstractController.php` |
| T7 Routes : une seule source (`Routes::DEFAULTS`, `Routes::get()`) ; gabarits sur `shop_routes` ; clés mortes `buy_route`, `cart_route`, `success_url`, `cancel_url` retirées ; `newsletter_unsubscribe` ajoutée | Fait | `Config/Routes.php`, `simple-shop.yaml`, `blueprints.yaml`, `buy-zone`, `header_navigation` |
| T8 `Pages::init()` hors de la boucle (et sans coût réel : idempotent dans Grav 2.0.24) | Fait | `CartStorage::getCartItems()` |
| T11 / O6 `PageFactory` : code mort retiré ; `path` = dossier du plugin (justifié) ; `addPage()` reste dans mvc.php (justifié) | Fait | `mvc/classes/PageFactory.php` |
| **Défaut trouvé par le test** : après le paiement, la facture n'était pas créée (« database is locked ») et la confirmation attendait ~25 s : curseur SQLite laissé ouvert dans `OrderFinalizer` ; même correction sur toutes les lectures d'une ligne | Corrigé | test du tunnel : 25 s → 0,1 s, facture `FACT-…` générée |

## Petits ajustements (2026-10-14, soir)

| Point | État | Preuve |
|-------|------|--------|
| Configuration Colissimo : encadré « contrat Colissimo Facilité ou Entreprise Privilège obligatoire » en tête du formulaire | Fait | `simple-shop-carrier-colissimo/blueprints.yaml` (champ `display`) |
| Tunnel : modes de livraison triés du moins cher au plus cher, le moins cher présélectionné (ou le choix déjà fait s'il est encore proposé) | Fait | `ShippingManager::getAvailableCarriers()`, `step2delivery.html.twig`, `tests/checkout-flow.php` |
| Prix libraire (B2B) retiré : carte de livre et gabarit d'achat | Fait | `macros/books.html.twig`, `buy-zone.html.twig` |
| Conception produits / prix validée et complétée (saisie, taxe par type, contrat du prix) | Fait | `CONCEPTION-PRODUITS-PRIX.md` §7 |


## Produits et prix — `CONCEPTION-PRODUITS-PRIX.md` §6 (2026-10-14)

| Étape | État | Preuve |
|-------|------|--------|
| 1. `Money` (centimes entiers, saisie « 12.95 » ou « 12,95 », 2 décimales au plus), `TaxRate` (points de base, arrondi au demi supérieur), `Price` (HT, TTC, taxe, devise, base de saisie) | Fait | `classes/Pricing/`, `tests/pricing.php` |
| 2. Contrat produit : types déclarés dans les blueprints (bloc `shop:`), découverts et mis en cache, type générique de repli ; stock, prix, expédition et panier passent par le type | Fait | `classes/Product/` (`ProductTypes`, `ProductType`, `GenericProduct`, `Catalog`, `PriceContext`), `InventoryManager`, `ShippingManager`, `CartStorage`, `tests/product-types.php` |
| 3. Règles des livres dans le thème : prix de prévente si non paru, « vendu ailleurs », épaisseur en cm, poids par défaut, couverture ; taxe `TVA_5_5` du type `book` | Fait | `themes/bookstore/bookstore.php`, `blueprints/book.yaml` (bloc `shop:`) |
| Affichage : fonction Twig `shop_price(route)` et filtre `shop_money` ; carte de livre, export CSV et zone d'achat sur le prix calculé par le type ; zone d'achat du plugin neutre, surchargée par bookstore (prévente) | Fait | `simple-shop.php`, `macros/books.html.twig`, `export-csv.csv.twig`, `partials/simple-shop/buy-zone.html.twig` (plugin et thème) |
| Devise du site réglable (`currency`, défaut EUR) | Fait | `simple-shop.yaml`, `blueprints.yaml`, `config/plugins/simple-shop.dist.yaml` |
| Étape livraison : le moins cher toujours présélectionné (l'ancien choix n'est plus repris), formulaire `autocomplete="off"` (le navigateur ne restaure plus un ancien bouton coché), tri repris dans le gabarit | Corrigé | `step2delivery.html.twig` ; simulation avec la configuration du serveur : ordre croissant |
| 4. Montants en centimes jusqu'à la commande : panier, livraison (`price_cents`), montant à encaisser (`billing_total_cents`, lu par Stripe avec la devise), contrôle du webhook, commande | Fait | `Orders/OrderAmounts.php`, `CartStorage::toOrder()`, `CartController::computeBillingTotal()`, `OrderFinalizer`, `simple-shop-payment-stripe` |
| 4. Instantané par ligne de commande : HT/TTC en centimes, code et taux de taxe, type de produit ; livraison et devise sur la commande (colonnes ajoutées sans toucher aux anciennes commandes) | Fait | `InventoryManager::ADDED_COLUMNS`, `tests/checkout-flow.php`, `tests/invoice.php` (migration d'une base existante) |
| 4. Facture Factur-X corrigée : profil EN 16931, prix net **HT** (et non TTC), TVA par taux, arrondi BT-114, déjà payé / reste dû 0, catégorie `S` (le code `AA` n'est pas admis par EN 16931) ; PDF : TVA par taux et ligne « Arrondi » | Fait | `Invoicing/FacturXXmlBuilder.php`, `Invoice.php`, `tests/invoice.php` : XSD officiel + règles BR-CO-10, 14, 15, 16, BR-S-09 |
| Livraison : le dernier choix du client reste coché quand il revient à l'étape livraison, tant que le panier ne change pas (sinon le moins cher) | Fait | `CartController` (`shipping_choice`, empreinte du panier), `step2delivery.html.twig`, `tests/checkout-flow.php` |
| Prix des livres saisis **TTC** (décision du 14/10/2026, 18 h 30) : réglage `taxes.prices_entered: ttc` livré dans la configuration du serveur ; conversion automatique et unique des fiches saisies en HT (prix TTC inchangé, 2 décimales) ; champ « Prix saisis » par fiche ; saisie limitée à 2 décimales | Fait | `Product/PriceBasisMigration.php` (événement `onPagesInitialized`), `book.yaml` (`price_fields`, `price_entered_as`), `tests/price-basis.php` |
| Mention « TTC » en petit après les prix du site public | Fait | gabarits du tunnel, `buy-zone` (plugin et thème), carte de livre, `css/cart.css` (`.price-tax`) |
| Facture FACT-2026-00003 au testeur FNFE (16 erreurs) : polices non embarquées (PDF/A-3) → Liberation Sans embarquée ; règles françaises BR-FR → cadre de facturation B2, mentions AAB/PMD/PMT, SIREN (et non SIRET) du vendeur, adresses électroniques vendeur (0225) et acheteur (e-mail), adresse de livraison ; « Désignation », « Qté » accentués | Corrigé | `Invoicing/Invoice.php`, `FacturXXmlBuilder.php`, `fonts/`, `tests/invoice.php` (XSD, pdffonts) ; reste : informations vendeur dans la configuration (BR-06, BR-CO-26, BR-S-02, BR-FR-10) |
| Testeur FNFE, FACT-2026-00004 : PDF/A-3, XML et EN 16931 valides ; dernière erreur BR-FR-CO-09 (cadre B2 : la date d'échéance BT-9 doit être la date du paiement) → échéance = date de la commande payée ; mention « Facture payée le … » sur le PDF | Corrigé | `FacturXXmlBuilder.php`, `Invoice.php`, `tests/invoice.php` (XSD) |
| Testeur FNFE : FACT-2026-00006 **valide** (0 erreur). Contrôle du point 3 avec des outils libres (poppler, qpdf) : une seule pièce jointe `factur-x.xml` (AFRelationship /Data), XMP PDF/A-3B + Factur-X EN 16931, polices embarquées, XML extrait valide au XSD. Rendu : désignation longue renvoyée à la ligne (elle débordait sur « Qté ») ; « SIREN » affiché quand seul le SIREN est saisi | Fait | `Invoice.php` |
| 6. Panier « produit + options » : table `cart_line` (clé `line_id`), options validées par le type (`shop.options`), prix/stock/expédition selon les options, stock réservé par produit, `order_item.line_id` et `options` ; anciens paniers repris ; options affichées (panier, paiement, confirmation, e-mails, administration) | Fait | `CartStorage`, `CartController`, `InventoryManager`, `OrderAmounts`, `tests/cart-options.php` |
| Taxes (modèle WooCommerce, `CONCEPTION-TAXES.md`) : classes de taxe par type de produit (livres : taux réduit), table des taux par pays / région / codes postaux avec priorités et taxes composées, adresse de livraison / facturation / boutique, taxe de la livraison selon les articles (taux le plus élevé ; prorata ou classe fixe en option), exonération et franchise en base, réglages Admin2, vue d'ensemble + import/export CSV (Boutique > Taxes), événement `onSimpleShopTaxRates`, factures par taux | Fait | `classes/Tax/`, `Pricing/TaxComponent.php`, `TaxRate` (composantes), `OrderAmounts`, `ShippingManager::priceTtc()`, `tests/taxes.php` |
| Taxes simplifiées (15/10/2026) : plus de « classes » séparées, une seule table de taux nommés (le nom joue le rôle de la classe, variantes par pays sous le même nom) ; anciennes configurations et codes repris | Fait | `Tax/TaxSettings.php`, `TaxResolver::forRate()/rateFor()`, blueprints, Boutique > Taxes, `tests/taxes.php` |
| Types de taxe contenant leurs taux (15/10/2026, 16 h) : `taxes.tax_types` imbriqué, éditeur Admin2 > Boutique > Taxes (type = bloc ouvert, taux = blocs fermés), `PUT /taxes/types`, CSV une ligne par taux, `product_types` / `default_type` / champ de fiche `tax_type` ; formats précédents lus | Fait | `Tax/TaxSettings.php` (`nest()`), `Api/ShopApiController::saveTaxTypes()`, `admin-next/pages/simple-shop.js`, `tests/taxes.php` |
| Correctif identification (19/10/2026) : Grav 2 vide la session au renouvellement de son identifiant ; données recopiées, `shop_order` recréé au besoin ; message d'erreur quand le code ne part pas | Fait | `CustomerManager::regenerateSessionId()`, `tests/session.php` |
| Paiements (19/10/2026) : table `payment` (montants signés, idempotence `external_ref`), état de règlement calculé, statut « payée » retiré (reprise des anciennes commandes), saisie dans Admin2, colonne Règlement, export CSV | Fait | `Payments/PaymentLedger.php`, `tests/payments.php` |
| Ventes manuelles (19/10/2026) : brouillon, facture à régler, facture d'acompte, facture finale (acomptes déduits), demande de paiement → facture au règlement, Factur-X « à régler » (BT-9, BT-20, BG-16, BT-113/115, BG-3), copie acquittée, page publique `/document?t=`, choix du moyen de paiement (virement, Stripe, test), e-mails document et reçu, onglet « Factures » et éditeur Admin2, menu « Ventes », `shop.public` | Fait | `Invoicing/DocumentManager.php`, `Controllers/DocumentController.php`, `Gateways/*Document*`, `Services/DocumentMailer.php`, `admin-next/pages/simple-shop.js`, `tests/documents.php` ; vérifié dans Grav 2.2.5 + Admin2 (Playwright) |
