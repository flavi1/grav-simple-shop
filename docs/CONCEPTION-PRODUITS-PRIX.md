# Audit et conception — produits, prix, indépendance des plugins `simple-shop-*` (14/10/2026)

> Statut : **validé le 14/10/2026** (décisions en §7). Réalisation selon le plan du §6.
> Objectif : des plugins `simple-shop`, `simple-shop-payment-*` et `simple-shop-carrier-*` génériques, publiables dans des dépôts
> indépendants, que le thème `bookstore` (ou un autre site) **configure et étend** sans que le cœur ne connaisse les livres.

---

## 1. Audit de séparation des responsabilités

### 1.1 Contrats existants

| Contrat | Où | Verdict |
|---|---|---|
| **Paiement** : `PaymentGatewayInterface` (formulaire + `processPayment`), `RedirectPaymentGatewayInterface` (page hébergée + `confirmReturn`) ; enregistrement par l'événement `onSimpleShopRegisterGateways` | `simple-shop/classes/Gateways/` | **Bon.** Stripe et Test n'en dépendent que par ces interfaces… sauf Stripe, qui appelle aussi directement `OrderFinalizer` et `PendingPayment` (webhook). À formaliser (§5.3). |
| **Transporteur** : `CarrierProviderInterface` (versionné `API_VERSION`), `CarrierUnavailable`, événement `onSimpleShopRegisterCarriers` | `simple-shop/classes/Delivery/` | **Bon.** Colissimo n'utilise que ce contrat. Les métriques passées (`weight`, `thickness`, `subtotal`) sont génériques. |
| **Contraintes globales** (lois, offres) : types de produits configurables, rien de propre aux livres | `ShippingConstraints` | **Bon** (fait le 14/10). |
| **Produit** | — | **Absent.** Le cœur lit directement des champs d'en-tête fixes et des règles propres aux livres (§1.2). |
| **Prix** | `TaxManager` + `float` partout | **Insuffisant** (§1.3, §3). |

### 1.2 Ce qui lie aujourd'hui le cœur aux livres (ou à ce site)

| Constat | Fichier | Conséquence |
|---|---|---|
| Règle de **prévente** codée en dur : `publication_date` future → `product_presale_price`, sinon invendable | `Inventory/InventoryManager::getProductData()` | Une formation, un objet personnalisé… hériteraient d'une règle de librairie. |
| Champs fixes `product_price`, `product_quantity`, `sales_method` | idem | Impossible d'avoir un prix calculé (déclinaisons, places restantes d'une session). |
| Poids et épaisseur lus dans l'en-tête, épaisseur **en cm × 10**, repli 100 g | `Delivery/ShippingManager::getCartPhysicalMetrics()` | Hypothèse « livre ». Aucun moyen de dire « ce produit ne s'expédie pas » (formation). |
| Image du panier = `book_cover` | `Inventory/CartStorage::getCartItems()` | Champ du blueprint `book`. |
| Gabarit `buy-zone` : textes « prévente », « Sortie officielle le », « Prix libraire », bouton « Précommander » | `templates/partials/simple-shop/buy-zone.html.twig` | Vocabulaire de librairie dans le plugin. |
| **Incohérence B2B** : la carte du thème affiche `product_bookseller_price` aux clients `is_b2b`, mais le panier facture le prix public ; `is_b2b_price` n'est jamais calculé | `bookstore/macros/books.html.twig`, `buy-zone` | Prix affiché ≠ prix payé (fonction morte en pratique : aucun code ne pose `is_b2b`). Question Q15. |
| Messages flash via le **thème `no-js`** (`use Grav\Theme\NoJs`, appel direct) | `simple-shop.php`, `mvc/AbstractController` | simple-shop ne fonctionne pas sans ce thème. |
| Devise **EUR** et symbole « € » en dur | Stripe (`'currency' => 'eur'`), Factur-X, CSV, gabarits | Acceptable pour ce site, pas pour un plugin réutilisable. |

### 1.3 Prix et montants : état actuel

- Montants en **`float`** (euros) de la fiche au panier, à la commande (`REAL` en base) et à la facture ; Stripe convertit en centimes (`round(x * 100)`).
- **Un seul taux de TVA** pour tout le site (`taxes.default`), y compris livraison. Pas de taux par produit.
- Saisie **HT** avec 4 décimales pour retomber sur un TTC rond (`12.2749` → 12,95 €) ; `round($x + 1e-9, 2)` compense les erreurs binaires.
- La commande ne garde **ni taux, ni HT, ni devise** par ligne : la facture recalcule avec le taux **courant** (faux si le taux change).
- Factur-X : `NetPriceProductTradePrice` reçoit le prix unitaire **TTC** (la norme attend un net HT) — à vérifier au testeur FNFE (MANUAL_TESTS §4) et à corriger avec §3.

### 1.4 Autres dépendances à traiter pour des dépôts séparés

- `simple-shop` dépend de `mvc` (déclaré) : `mvc` devient lui aussi un dépôt.
- Tests : `simple-shop/tests/support/bootstrap.php` charge `mvc` et `simple-shop-payment-test` par chemins voisins → à rendre configurable (variables d'environnement).
- Les gabarits du tunnel étendent `partials/base.html.twig` du thème : convention Grav, acceptable (documenter les blocs attendus).

---

## 2. Principes retenus

1. Le cœur ne connaît que des **produits** : une page dont le blueprint déclare qu'elle se vend (§4), et dont le **type** fournit le calcul.
2. Un **prix** est un objet (montant entier en centimes, devise, taxe, base HT/TTC), jamais un `float` nu (§3).
3. Une **ligne de panier** = produit + **options** (déclinaison, personnalisation, session…), même si aucune option n'est développée maintenant (§5).
4. Tout ce qui fonctionne pour bookstore continue de fonctionner, **tunnel testé de bout en bout** à chaque étape (`tests/checkout-flow.php`).

---

## 3. Le prix

### 3.1 Objets

```
Money      { int amount (unités mineures : centimes), string currency (ISO 4217, ex. EUR) }
TaxRate    { string code (ex. TVA_5_5), int basisPoints (550 = 5,5 %) }
Price      { Money unitHt, Money unitTtc, TaxRate tax, string enteredAs ('ht'|'ttc') }
```

- **Entiers seulement** pour les montants (centimes) et les taux (points de base) : plus de `1e-9`, plus d'écart d'un centime entre panier, Stripe et facture.
- La valeur **saisie** est la référence ; l'autre est **dérivée une fois**, arrondie au centime, demi supérieur :
  - saisi HT : `ttc = arrondi(ht × (10000 + bp) / 10000)` ;
  - saisi TTC : `ht = arrondi(ttc × 10000 / (10000 + bp))`.
- **Saisie dans l'administration** (décision) : champ texte avec un point décimal, **deux décimales au plus**, protégé par un motif
  (`^[0-9]+(\.[0-9]{1,2})?$`) ; la valeur lue est convertie et **stockée en centimes entiers**. Plus de 4 décimales.
- **HT ou TTC au choix** : réglage du site `taxes.prices_entered` (existant), **surchargeable par produit** (champ `price_entered_as` dans le blueprint).
  Les deux cas sont couverts par les tests du tunnel (§6, étape 5).
- **Taxe par type de produit** (décision) : chaque type de produit déclare son code de taxe (ex. `book` → `TVA_5_5`) ; défaut : taux du site.
  La livraison garde le taux du site (réglable).
- **Contrat du prix** (décision) : la réponse de `price_calculation` contient **toutes** les informations : montant en centimes, devise, code de taxe,
  base de définition (`ht` | `ttc`). Le cœur n'ajoute ni ne devine rien ; il dérive l'autre valeur et contrôle la cohérence (devise du site, code de taxe connu).

### 3.2 Totaux, factures, arrondis

- Ligne : `unitTtc × quantité` (et `unitHt × quantité`), en centimes. Total à payer = somme des TTC + livraison TTC : **c'est le montant envoyé à Stripe**, inchangé.
- Facture (EN 16931 / Factur-X) : base HT par taux = somme des lignes HT ; TVA par taux = `arrondi(base × taux)`.
  Si les prix sont saisis TTC, la somme peut différer d'un centime du TTC payé : on renseigne l'écart dans le **montant d'arrondi** prévu par la norme (BT-114),
  pour que le total facturé égale exactement le montant encaissé.
- **Instantané à la commande** : chaque ligne enregistre ses centimes HT/TTC, son code et son taux, la devise, les options ; la facture ne relit plus la configuration.
  Migration additive (nouvelles colonnes ; les anciennes commandes en `REAL` restent lisibles).

### 3.3 Devise

`shop.currency` (défaut `EUR`) ; formatage par une fonction Twig (`shop_money(price)`), plus de « € » en dur ; Stripe, CSV et Factur-X lisent la devise de la commande.
Une seule devise par site (pas de multidevise).

---

### 3.4 Migration des prix existants (constat du 14/10/2026)

Mesuré par `tests/pricing.php` : à 5,5 %, **1 043 prix TTC ronds sur 20 000** (0,01 € à 200 €) ne s'obtiennent depuis aucun HT au centime
(ex. 12,95 € TTC exige 12,2749 € HT, d'où les 4 décimales actuelles). Convertir ces HT en centimes changerait le prix public.
Donc : une fiche dont le prix HT a plus de 2 décimales est **lue** comme avant (TTC = HT × (1 + taux), arrondi), et une **conversion**
(bouton dans Boutique, étape 5) la réécrit en **TTC à 2 décimales** avec `price_entered_as: ttc` — prix public inchangé, puis motif à 2 décimales.

## 4. Les types de produits

### 4.1 Déclaration dans le blueprint (votre proposition)

```yaml
# themes/bookstore/blueprints/book.yaml
title: Livre
'@extends': default
shop:
  price_calculation: 'Grav\Theme\Bookstore\BookProduct::price'   # obligatoire : rend la page « produit »
  # facultatifs (défauts génériques fournis par simple-shop) :
  stock: 'Grav\Theme\Bookstore\BookProduct::stock'               # défaut : champ product_quantity (-1 = illimité)
  shipping: 'Grav\Theme\Bookstore\BookProduct::shipping'         # défaut : weight (g) / thickness (mm) ; false = non expédié
  presentation: 'Grav\Theme\Bookstore\BookProduct::presentation' # libellé de ligne, image (défaut : titre de la page)
  options: 'Grav\Theme\Bookstore\BookProduct::options'           # défaut : aucune option acceptée
form: …
```

- Un **type de produit** = un blueprint de page qui a `shop.price_calculation`. Son nom = nom du blueprint (`book`).
- Chaque clé désigne une **méthode statique** `Classe::méthode` (votre idée), appelée avec le contexte ci-dessous ; une valeur absente prend le
  comportement générique (celui d'aujourd'hui, sans les règles de librairie).
- Variante possible (non retenue par défaut) : une seule clé `shop.handler: Grav\Theme\Bookstore\BookProduct` désignant une classe qui implémente
  `ProductTypeInterface`. Plus rigide mais vérifiable à la compilation ; on peut l'accepter en plus si vous préférez.

### 4.2 Signatures (contrat publié par simple-shop, versionné comme les transporteurs)

```php
price(PageInterface $page, array $options, PriceContext $ctx): ?Price        // null = pas vendable maintenant
stock(PageInterface $page, array $options): ?int                             // null = illimité
shipping(PageInterface $page, array $options): ?array                        // ['weight_g'=>int,'thickness_mm'=>int] ; null = non expédié
presentation(PageInterface $page, array $options): array                     // ['label'=>string,'image'=>?string]
options(PageInterface $page, array $raw): array                              // options normalisées ; exception = refus
```

`PriceContext` : client (dont un éventuel statut professionnel), quantité, date, devise. C'est là que vivent prévente et prix libraire, **dans le thème**.

### 4.3 Liste des types de produits et cache

- `ProductTypes::all()` parcourt les blueprints de pages (`blueprints://pages`, thèmes et plugins) et garde ceux qui ont `shop.price_calculation`.
- **Cache** : clé = empreinte des chemins + dates de modification des fichiers de blueprints ; stocké dans le cache de Grav. Une modification
  d'un blueprint invalide le cache sans action. Les noms de méthodes sont vérifiés (`is_callable`) à la construction ; une erreur est journalisée et le type ignoré.
- Utilisations : contraintes globales (cases « Types de produits (blueprint) »), administration, validation de l'ajout au panier.

### 4.4 Ce que bookstore implémente (`Grav\Theme\Bookstore\BookProduct`)

- `price` : prix public ; prévente si `publication_date` future (`product_presale_price`, sinon non vendable) — **affichage et paiement
  utilisent le même calcul**. Taxe : `TVA_5_5`. (Plus de prix libraire : pas de vente aux professionnels sur ce site, retiré le 14/10/2026.)
- `shipping` : poids en g, épaisseur saisie en cm → mm.
- `presentation` : titre + `book_cover`.
- Vocabulaire « prévente », « Précommander »… : déplacé du gabarit `buy-zone` du plugin vers le thème (le plugin garde un gabarit neutre avec des blocs).

---

## 5. Panier, options et cas futurs (anticipés, non développés)

### 5.1 Ligne de panier

- Aujourd'hui : clé unique `(cart_id, product_route)` → impossible d'avoir deux déclinaisons du même produit.
- Demain : `line_id` = empreinte de `product_route` + options normalisées ; colonnes `options` (JSON) dans `cart_item` et `order_item`.
- Formulaire d'ajout : `product_route` + `options[...]` facultatives, validées par `options()` du type (défaut : aucune acceptée → comportement actuel).

### 5.2 Les exemples, sans les développer

| Cas | Comment il s'exprime |
|---|---|
| Inscription payante à une session de formation (Qualiopi) | type `session` : `price` (tarif, éventuellement par public), `stock` = places restantes, `shipping` = null (**pas d'étape livraison**), `options` = participant (nom, e-mail). |
| Déclinaisons (taille, couleur — « combinations » PrestaShop) | `options` = `{combination: id}` ; `price` = prix de base + écart ; `stock` par déclinaison. |
| Personnalisation (texte gravé — « customizations ») | `options` = `{customization: {...}}` validées ; `price` = base + supplément. |

Conséquence prévue dans le tunnel : si **aucune ligne n'est expédiable**, l'étape livraison est sautée (à coder au moment où un tel type existera).

### 5.3 Contrat de finalisation pour les paiements

Stripe appelle aujourd'hui `OrderFinalizer` et `PendingPayment` directement. On publie une interface stable (ex. `OrderServiceInterface` :
instantané avant paiement, finalisation idempotente par référence de paiement) pour que les plugins de paiement ne dépendent que d'elle.

---

## 6. Plan de réalisation (après validation, une PR par étape, tunnel testé à chaque fois)

1. `Money`, `TaxRate`, `Price` + tests unitaires (aucun changement visible).
2. Contrat produit + `ProductTypes` (découverte, cache) + **type générique** = comportement actuel. `InventoryManager`, `ShippingManager`, `CartStorage` passent par lui.
3. `BookProduct` dans bookstore (prévente, épaisseur, couverture, vocabulaire) ; le cœur ne contient plus rien de propre aux livres.
4. Montants en centimes de bout en bout, instantané taxe/devise par ligne de commande (migration additive), Factur-X corrigé (prix net HT, arrondi BT-114).
5. **HT ou TTC** par produit : tests du tunnel dans les deux cas (prix, contraintes de livraison, montant Stripe en centimes, facture).
6. Lignes de panier avec options (`line_id`, JSON), sans option exposée.
7. Messages flash via le service `messages` de Grav (plus de dépendance au thème `no-js`) ; devise configurable ; gabarit `buy-zone` neutre.
8. Préparation des dépôts : `mvc`, `simple-shop`, `simple-shop-payment-stripe`, `simple-shop-payment-test`, `simple-shop-carrier-colissimo` ;
   dépendances déclarées dans chaque `blueprints.yaml`, tests autonomes par dépôt.

**Avancement** : étapes 1 à 4 faites (14/10/2026), étape 6 faite (15/10/2026) — étape 4 : facture passée au profil EN 16931 (BT-114 n'existe pas en BASIC) ; voir `DONE.md`. Écart au plan : la fonction Twig `shop_price` et la zone d'achat
neutre (prévue à l'étape 7) sont venues avec l'étape 3, la carte de livre en ayant besoin.

## 7. Décisions (14/10/2026)

- **V1** — Stockage en centimes entiers + taux en points de base : **oui**. Saisie dans l'administration avec un point décimal, 2 décimales au plus
  (champ texte à motif), convertie en centimes. HT ou TTC réglé pour le site, modifiable par produit : **oui**.
- **V2** — Clés `shop.*` du blueprint pointant vers des méthodes statiques : **validé** (tout le §4).
- **V3** — Taxe par type de produit (ex. `book` → `TVA_5_5`) : **oui**. La réponse de `price_calculation` porte devise, code de taxe et base (HT/TTC).
- Commande : montants et taux enregistrés par ligne : **oui**. Facture : écart d'un centime en montant d'arrondi : **oui**. Panier avec options : **oui**.
- **Q15** — Prix libraire : **supprimé** (plus de vente aux professionnels sur ce site).
