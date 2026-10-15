# Guide du dépôt — plugin Grav `simple-shop`

> Ce fichier est écrit pour l'agent (Claude) et pour le propriétaire. Il doit permettre de travailler sur ce plugin
> **sans accès immédiat aux autres dépôts** : contexte, décisions, historique et travail restant y sont recopiés.
> Mettre à jour les sections « À faire » et « Historique » à chaque lot de travail.

## Communication
- S'adresser au propriétaire en **français** ; code, commentaires et messages du plugin en français.
- Ne jamais reproduire de secret (clés Stripe, identifiants Colissimo, mots de passe SMTP, sel Grav). Ce dépôt est **public** :
  aucune clé, même de test, ne doit y entrer (configuration réelle : `user/config/plugins/simple-shop.yaml` du site, jamais versionnée).
- Ne pas supprimer de fichiers à la place du propriétaire : les lister dans « À supprimer par le propriétaire » ci-dessous.

## Rôle du plugin

Boutique en ligne pour Grav 2.0 (Grav 2.0.24, Admin2 2.1.9, plugin `api`) : panier « produit + options », identification du client
par code à usage unique (OTP) envoyé par e-mail, tunnel d'achat **sans JavaScript**, livraison (zones, modes manuels et transporteurs
en plugins, contraintes globales, retrait sur place), taxes (table de taux nommés), paiement par passerelles en plugins, commandes,
factures Factur-X (EN 16931), administration dans Admin2 (page « Boutique » + API `/api/v1/simple-shop/*`).

Référence fonctionnelle détaillée : `README.md` (parcours, routes, événements, API, configuration). Ce fichier-ci donne le contexte de
travail : décisions, historique, travail restant.

Périmètre du premier site (senonevero.flavieng.com, petite maison d'édition) : B2C, France métropolitaine hors Corse, euros, livres.
**Objectif durable : rester générique** — simple-shop ne doit rien contenir de propre aux livres (le type `book` est déclaré par le
thème du site) et doit pouvoir servir hors de France plus tard (taxes, devise, zones).

## Famille de plugins

Extraits le 15/10/2026 du dépôt du site `senonevero.flavieng.com` (`private/grav/plugins/<nom>`), historique Git conservé
(commits ne touchant que ce dossier ; clés Stripe de test présentes dans d'anciennes versions remplacées par `CLE-STRIPE-RETIREE`).

| Dossier du plugin | Dépôt | Rôle |
|---|---|---|
| `mvc` | [flavi1/grav-mvc](https://github.com/flavi1/grav-mvc) | routes → contrôleurs (`Request`, `Response`, `AbstractController`) ; requis par simple-shop |
| `simple-shop` **(ce dépôt)** | [flavi1/grav-simple-shop](https://github.com/flavi1/grav-simple-shop) | boutique : panier, tunnel sans JavaScript, OTP, livraison, taxes, commandes, factures Factur-X, API Admin2 |
| `simple-shop-carrier-colissimo` | [flavi1/grav-simple-shop-carrier-colissimo](https://github.com/flavi1/grav-simple-shop-carrier-colissimo) | transporteur Colissimo (grille Facilité, points de retrait) |
| `simple-shop-payment-stripe` | [flavi1/grav-simple-shop-payment-stripe](https://github.com/flavi1/grav-simple-shop-payment-stripe) | paiement Stripe (formulaire intégré ou page hébergée), webhook |
| `simple-shop-payment-test` | [flavi1/grav-simple-shop-payment-test](https://github.com/flavi1/grav-simple-shop-payment-test) | passerelle de paiement simulée (sites de test) |
| — | [flavi1/senonevero.flavieng.com](https://github.com/flavi1/senonevero.flavieng.com) (privé) | site d'origine : thème `bookstore` (type de produit `book`), configuration, pages |

## Installation sur le serveur (hébergement mutualisé multi-domaines)

- Grav 2.0 est installé **une fois** dans `/shared/grav-2/` et sert plusieurs domaines (`/domains/<domaine>/private/grav` = dossier
  `user://` de chaque site, routeur global `www/index.php`, routeur de domaine `shared/manager/grav/Domain.php`).
- Ce plugin est déployé dans **`/shared/grav-2/user/plugins/simple-shop/`**, partagé par tous les domaines.
- Grav cherche un plugin d'abord dans le dossier du site (`user://plugins`, soit `/domains/<domaine>/private/grav/plugins/simple-shop`),
  **puis** dans le dossier partagé. Une copie restée dans un site masque donc la version partagée : la supprimer de ce site
  pour qu'il utilise la version partagée.
- **Désactivé par défaut** (`enabled: false` dans `simple-shop.yaml`), puisque le dossier est commun à tous les domaines : chaque site
  l'active dans sa propre configuration (`user/config/plugins/simple-shop.yaml` : `enabled: true`, ou Admin2 > Plugins).
- Fichiers publics : le routeur de domaine ne sert depuis `/user/plugins/…` que les ressources statiques (CSS, JS, images, polices) ;
  `.php`, `.yaml`, `.md`, `.json`, `.lock`… répondent 403.

## Déploiement (GitHub Actions → FTP)

Chaque push sur `main` lance `.github/workflows/deploy.yml` :
1. **Contrôle** : `php -l` sur tous les fichiers PHP (hors bibliothèques tierces).
2. **Envoi FTP** (action `SamKirkland/FTP-Deploy-Action`) vers `/shared/grav-2/user/plugins/simple-shop/` : seuls les fichiers
   **modifiés depuis le dernier envoi** partent (état mémorisé sur le serveur dans `.ftp-deploy-sync-state.json`) ; un fichier
   supprimé du dépôt est supprimé du serveur ; les fichiers que le dépôt n'a jamais envoyés ne sont **jamais** touchés.
   Exclus : `.git*`, `.github/`, `tests/`, `docs/`, `AGENTS.md`.
3. Relance à la main possible : onglet *Actions* → *Déploiement FTP* → *Run workflow*.

**Mise en place (une fois par dépôt)** — GitHub → dépôt `flavi1/grav-simple-shop` → *Settings* → *Secrets and variables* → *Actions* :
- onglet *Secrets* → *New repository secret*, trois fois :
  - `FTP_SERVER` : hôte FTP de l'hébergement (ex. `ftp.clusterXXX.hosting.ovh.net`, voir l'espace client OVH → Hébergement → *FTP - SSH*) ;
  - `FTP_USERNAME` : identifiant FTP ;
  - `FTP_PASSWORD` : mot de passe FTP.
- onglet *Variables* (facultatif, valeurs par défaut entre parenthèses) :
  - `FTP_PLUGINS_DIR` (`/shared/grav-2/user/plugins/`) : dossier des plugins **tel que vu à la connexion FTP**, avec `/` final ;
  - `FTP_PROTOCOL` (`ftps`) : mettre `ftp` si le serveur refuse le FTP chiffré ;
  - `FTP_PORT` (`21`).
- Avec l'outil `gh` : `gh secret set FTP_SERVER -R flavi1/grav-simple-shop` (idem pour les deux autres ; la valeur est demandée, rien n'est affiché).

Premier envoi : tous les fichiers partent (quelques minutes). Si le dossier existait déjà (copie manuelle), il est complété et
mis à jour, sans suppression de fichiers inconnus. Vérifier ensuite par FTP la présence de `simple-shop.php` dans le dossier attendu.

## Environnement de développement

Les tests se lancent dans une copie du site d'origine, qui fournit le thème `bookstore` (type de produit `book`, utilisé par les
tests du tunnel) et les plugins voisins :

```
git clone https://github.com/flavi1/senonevero.flavieng.com site          # dépôt privé du site
cd site/private/grav/plugins
git clone https://github.com/flavi1/grav-mvc mvc
git clone https://github.com/flavi1/grav-simple-shop simple-shop
git clone https://github.com/flavi1/grav-simple-shop-carrier-colissimo simple-shop-carrier-colissimo
git clone https://github.com/flavi1/grav-simple-shop-payment-test simple-shop-payment-test
git clone https://github.com/flavi1/grav-simple-shop-payment-stripe simple-shop-payment-stripe
```

(ces dossiers sont ignorés par le dépôt du site). `symfony/yaml` est nécessaire : `composer require symfony/yaml` dans un dossier
temporaire, ou, sans accès à Packagist, `git clone -b 7.3 https://github.com/symfony/yaml` + un petit autoloader PSR-4
(`Symfony\Component\Yaml\` → dossier cloné) ; la variable `YAML` pointe vers cet autoload.

## Tests en ligne de commande
Depuis le dossier du plugin (disposition ci-dessus), avec `YAML=<chemin de l'autoload de symfony/yaml>` :

```
for t in tests/*.php; do YAML=$YAML php -d zend.assertions=1 -d assert.exception=1 $t || break; done
```

Chaque script affiche `OK`. `tests/checkout-flow.php` (tunnel complet avec doublures de Grav, `tests/support/bootstrap.php`) **doit
rester vert**. Les autres : `pricing`, `product-types`, `price-basis`, `invoice` (XSD Factur-X officiel), `cart-options`, `taxes`,
`constraints`, `carriers-pricing`, `local-pickup`, `mode-editor`. Le plugin Colissimo a ses propres tests (variable `SS` = dossier de
simple-shop).

## Conventions (reprises du site d'origine)
- Fonctionnement **sans JavaScript** côté client public (hors option Stripe « formulaire intégré ») ; JavaScript admis côté Admin2.
- Un contrôleur retourne toujours une `Response` ; pas de `exit`, pas d'accès direct à `$_POST`/`$_GET`/`$_SERVER` (passer par `Request`).
- Écriture de fichiers partagés : verrou + fichier temporaire + `rename`.
- Pas de `innerHTML` avec des données dans le JavaScript.
- Tester en ligne de commande ce qui peut l'être (`php -l`, scripts de `tests/`) ; signaler explicitement ce qui n'est testable
  qu'après déploiement (Admin2, Stripe réel, service Colissimo réel).
- Commits distincts par étape, poussés directement sur `main` (le déploiement suit).

## Repères dans le code
- `simple-shop.php` : abonnements aux événements Grav, routes (`onMvcRegisterRoutes`, plugin `mvc`), fonctions Twig (`shop_price`,
  filtre `shop_money`), API et page Admin2, inscription des listes dynamiques des blueprints (`Blueprint::addAllowedDynamicCallable`,
  obligatoire depuis Grav 2.0.11 pour `data-options@`).
- `classes/Controllers/CartController.php` : tunnel (panier, livraison, paiement, retour de paiement, confirmation).
  `CustomerController.php` : identification OTP, compte, déconnexion, newsletter (modèle de contrat MVC).
- `classes/Services/` : `CustomerManager` (session client, OTP), `OtpThrottle` (plafonds serveur), `OrderMailer`, `OrderFinalizer`
  (création idempotente de la commande, partagée par le retour client et le webhook), `AddressFields` (noms de champs d'adresse
  normalisés : `street`, `postal-code`, `city`, `address-complement`), `TaxManager` (raccourcis historiques).
- `classes/Inventory/` : `InventoryManager` (produits, base SQLite `user://data/simple-shop/shop.db`, migrations additives,
  déstockage), `CartStorage` (table `cart_line`, `line_id` = sha1(route + options) sur 16 caractères ; `toOrder()` idempotent via la
  référence de paiement).
- `classes/Product/` : types de produits déclarés dans les blueprints (bloc `shop:` : `price_calculation`, `stock`, `shipping`,
  `presentation`, `options`, `tax`, `price_fields` — méthodes statiques), `GenericProduct` (repli), `Catalog`, `PriceBasisMigration`
  (conversion unique des fiches quand la base HT/TTC change).
- `classes/Pricing/` : `Money` (centimes entiers), `TaxRate` / `TaxComponent` (points de base, taxes multiples et composées), `Price`.
- `classes/Tax/` : `TaxSettings` (réglages normalisés, formats anciens repris), `TaxResolver` (taux d'un nom à une adresse, taux d'un
  produit, parts de taxe de la livraison), `TaxLocation`.
- `classes/Delivery/` : `ShippingManager` (prix des modes : forfait / tarif du plugin / grille, marge, offert dès, contraintes,
  arrondi, TVA), `CarrierCatalog` + `CarrierProviderInterface` (transporteurs en plugins, événement `onSimpleShopRegisterCarriers`),
  `ShippingConstraints`, `ModeEditor` (validation des saisies Admin2), `Geocoder` (Base Adresse Nationale), `ZoneResolver`, `CarrierConfig`.
- `classes/Orders/` : `OrderManager` (statuts, historique, recherche sans accents, export CSV, événement `onSimpleShopOrderStatusChanged`),
  `OrderAmounts` (source unique des montants : panier, paiement, commande, facture), `PendingPayment`.
- `classes/Invoicing/` : Factur-X (`FacturXXmlBuilder`, `Invoice` — PDF/A-3 avec polices Liberation Sans embarquées dans `fonts/`).
- `classes/Gateways/` : interfaces de paiement (`PaymentGatewayInterface`, `RedirectPaymentGatewayInterface`).
- `classes/Api/ShopApiController.php` + `admin-next/pages/simple-shop.js` : administration (onglets Commandes, Transporteurs, Taxes,
  Adresses bannies).
- `shop.sql` : schéma ; colonnes ajoutées par `InventoryManager::ADDED_COLUMNS` (migration additive, repère = mtime + empreinte).
- Événements publiés : `onSimpleShopRegisterGateways`, `onSimpleShopRegisterCarriers`, `onSimpleShopOrderStatusChanged`,
  `onSimpleShopInvoiceStatusChanged`, `onSimpleShopTaxRates`.

## Pièges connus
- Une lecture SQLite d'une ligne (`fetch`, `fetchColumn`) libère son curseur (`closeCursor()`) : `InventoryManager::getDatabase()` ouvre
  une connexion par appel, et un curseur ouvert bloque l'écriture d'une autre connexion de la même requête (« database is locked »
  après 5 s × 5 essais ; la facture n'était plus créée et la confirmation attendait ~25 s).
- L'API d'Admin2 ne vide pas le cache des pages de Grav ; une liste de fichiers d'une page se relit sur le disque.
- Admin2 n'active « Enregistrer » après un ajout de média que si l'en-tête de la page a déjà un `media_order` non vide (contourné
  dans le thème du site).
- Les listes `data-options@` des blueprints doivent être inscrites (`Blueprint::addAllowedDynamicCallable`), sinon Grav 2 les ignore ;
  garder des `options:` statiques de repli.
- Tant que le site d'origine recopie sa configuration à chaque déploiement (mécanisme temporaire), une modification faite dans Admin2
  est écrasée au déploiement suivant du site : la reporter dans le dépôt du site.

## Décisions du propriétaire (à respecter)
- **Sans JavaScript** côté client public (hors option Stripe « formulaire intégré »).
- **Montants** stockés en **centimes entiers**, taux en **points de base** ; saisie avec un point (ou une virgule) décimal, 2 décimales au plus.
- **Prix des fiches** : base HT ou TTC réglée pour le site (`taxes.prices_entered`), modifiable par fiche (`price_entered_as`) ; changer
  la base convertit une fois les fiches existantes (prix TTC inchangé). Premier site : **TTC** (décision du 14/10/2026, 18 h 30) ;
  un petit « TTC » suit les prix publics.
- **Pas de vente aux professionnels** sur le premier site (prix libraire supprimé).
- **Taxes** (conception validée le 15/10/2026, `docs/CONCEPTION-TAXES.md`) : modèle de WooCommerce simplifié — **une seule table de taux
  nommés** (pas de « classes » séparées, décision du 15/10/2026 après-midi : le nom du taux joue le rôle de la classe ; plusieurs lignes
  du même nom = variantes par pays / région / codes postaux, pour la vente à l'étranger). TVA française par défaut ; adresse de livraison
  du client (comme WooCommerce) ; livraison taxée selon les articles (panier mixte : taux le plus élevé, comme WooCommerce ; prorata ou
  taux fixe en option) ; exonérations, franchise en base, CSV des taux (OSS), événement `onSimpleShopTaxRates` — « mais on s'arrête là ».
- **Livraison** : chaque mode se calcule au choix en **forfait**, **tarif du plugin** ou **grille personnalisée** (grille repliée,
  import/export CSV). Prix saisis HT (la TVA s'ajoute) : tarif → marge (fixe et/ou %) → TVA → offre « gratuite/0,01 € dès X € »
  → contraintes globales → arrondi. Pas d'options (signature, assurance) : tout passe par la marge. Aucune API de tarifs : les grilles
  sont datées, à mettre à jour chaque janvier (par le propriétaire du site, pas par le client).
- **Contraintes globales** (lois et offres commerciales) : liste de contraintes, chacune un ensemble de règles « si poids / montant
  TTC des articles concernés dans telle plage, alors minimum | maximum | prix imposé », limitée à des types d'objets et excluant des
  modes ; chaque règle a un **commentaire**. **Rien de propre aux livres dans simple-shop** : la loi Darcos (3 € TTC sous 35 € TTC de
  livres, 0,01 € au-delà, retrait exclu) n'est qu'une contrainte de la configuration du site. Pas de préréglage.
- **Retrait sur place** (`kind: local_pickup`) : prix unique (gratuit par défaut), adresse du lieu, distance maximale ; proposé si la
  commune du client est plus proche ; géocodage par un service **libre / ouvert** (Base Adresse Nationale, URL réglable compatible addok) ;
  désactivé tant que l'adresse n'est pas saisie.
- **Emballage** (provisoire, non sourcé) : 40 g et + 6 mm d'épaisseur ; les épaisseurs des exemplaires s'additionnent.
- **Tunnel** : modes de livraison triés du moins cher au plus cher ; le moins cher présélectionné ; le dernier choix du client reste
  coché pour un même panier (empreinte du panier).
- **Suivi de colis** : numéro saisi dans l'administration (avant « Expédiée »), repris dans l'e-mail avec le lien (`delivery.tracking_url`).
- **Factures** : Factur-X EN 16931 + règles françaises BR-FR (cadre B2, mentions AAB/PMD/PMT, SIREN schéma 0002, adresses
  électroniques, échéance = date du paiement) ; une ligne à plusieurs taxes cumulées donne un PDF sans XML (non exprimable en EN 16931).
  Les factures des anciennes commandes de test ne sont **pas** à régénérer (elles seront supprimées).
- **Types de produits** déclarés dans les blueprints (`shop.price_calculation` → méthode statique, réponse complète : montant,
  devise, code de taxe, base) ; panier « produit + options ».
- **Indépendance** : plugins `simple-shop-*` indépendants du thème du site (objectif de la conception produits/prix).

## À faire
- [ ] **Tests autonomes** (étape 8 de `docs/CONCEPTION-PRODUITS-PRIX.md`) : les tests chargent encore le thème `bookstore` du site
  (type `book`) ; leur donner un type de produit d'exemple dans `tests/fixtures/` et déplacer les tests propres aux livres
  (`tests/product-types.php`, partie « book ») vers le thème.
- [ ] **Étape 7** de `docs/CONCEPTION-PRODUITS-PRIX.md` : messages flash via le service `messages` de Grav (plus de dépendance au thème
  `no-js` : `AbstractController::addMessage()` → `NoJs::addMessage()`), gabarits neutres.
- [ ] **Étape 5** (en partie faite) : tests du tunnel avec des prix saisis HT **et** TTC (prix, contraintes, montant Stripe, facture).
- [ ] **À tester dans Admin2 après déploiement** (voir `docs/TESTS-MANUELS.md`) : §1 page « Boutique » (détail, statut, PDF, CSV),
  §17 à §24 (types de produits, centimes, TTC, facture, panier par lignes, **taxes** : listes `data-options@`, onglet Taxes,
  import/export CSV).
- [ ] **Permissions** : groupe « gestionnaire boutique » (`api.login`, `api.access`, `api.simple-shop.read/write`) à valider dans
  Admin2 (question Q9 du site : permission manquante éventuelle).
- [ ] **Factur-X (T13)** : validé au testeur FNFE le 15/10/2026 (FACT-2026-00006, 0 erreur) avec des identifiants fictifs ; à refaire
  avec les vrais SIRET et n° de TVA du site.
- [ ] Signaler à Admin2 (getgrav/grav-plugin-admin2) le défaut `media_order` (voir « Pièges connus ») ; Admin2 2.1.9 → 2.1.31 sur le serveur.

## Fichiers retirés le 15/10/2026
Ancienne administration Admin 1 (`templates/admin/`, `css/admin-orders.css`, `blueprints/plugins/simple-shop/carriers.yaml`),
`classes/Delivery/LegalShippingFloor.php` (remplacé par les contraintes globales), `NOTES-TODO.md` (repris ici et dans le README).
Aucun code ne les utilisait.

## Historique
- `git log` : commits d'origine (messages en français, un par étape).
- `docs/HISTORIQUE.md` : ce qui a été **délibérément** fait, lot par lot, avec les preuves (repris de `DONE.md` du site).
- `docs/CONCEPTION-*.md`, `docs/AUDIT-TRANSPORTEURS.md` : conceptions validées par le propriétaire (produits et prix, taxes, transporteurs).
- `docs/TESTS-MANUELS.md` : procédures de test à la main (Admin2, Stripe, factures), numérotées comme sur le site.
