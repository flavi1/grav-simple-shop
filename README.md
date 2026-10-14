# Simple Shop

Plugin e-commerce pour Grav 2.0 : panier, identification du client par code
à usage unique (OTP) envoyé par e-mail, livraison par zones, paiement
(passerelles séparées, ex. Stripe), commandes, factures Factur-X et
administration via l'API et Admin2.

Périmètre : vente à des particuliers (B2C), en euros, un seul taux de TVA.
Le plugin sait résoudre toutes les destinations (`Delivery\ZoneResolver` :
`FR-METRO`, `FR-CORSE`, `FR-OM`, `EU-ZONE-1`, `INTERNATIONAL`) ; les zones
réellement desservies par un site se règlent dans `plugins.simple-shop.delivery.allowed_zones`
(défaut : `FR-METRO`, soit la France métropolitaine hors Corse). Une adresse hors
zone est refusée avec un message clair (`delivery.unsupported_message`).
Ouvrir une zone demande aussi des règles pour cette zone dans `carriers.yaml`.

Principe directeur : le site doit fonctionner **sans JavaScript** côté client.
Seul le paiement Stripe en mode « Elements » en utilise (option, voir plus bas).

## Parcours client

| Étape | Route par défaut | Rôle |
|---|---|---|
| Panier | `/cart` | ajout, quantité, suppression, saisie des coordonnées |
| E-mail | `/checkout/email` | code OTP (uniquement si l'e-mail n'est pas déjà vérifié) |
| Livraison | `/checkout/delivery` | zone, mode de livraison, transporteur |
| Paiement | `/checkout/payment` | facturation, puis passerelle de paiement |
| Retour de paiement | `/checkout/payment-return` | vérification auprès de la passerelle, création de la commande |
| Confirmation | `/checkout/confirmation` | lit la référence de commande en **session**, pas dans l'URL |
| Identification | `/identify` | connexion par code OTP |
| Compte | `/mon-compte` | données personnelles, newsletter |
| Déconnexion | `/mon-compte/deconnexion` | POST + nonce |

Toutes les routes se changent dans `plugins.simple-shop.routes`.

### Anti-abus des codes OTP (serveur uniquement)

Délai de 60 s entre deux envois par session, plus deux plafonds horaires qui
ne dépendent pas de la session : 5 envois par adresse e-mail et 20 par IP
(`otp.*` dans `simple-shop.yaml`, `Services/OtpThrottle`). Les adresses et IP
sont stockées hachées.

### Stock

Le stock physique (`product_quantity` du produit, `-1` = illimité) est
décrémenté automatiquement à la création de la commande, au moment où le
panier est supprimé (`InventoryManager::decrementPhysicalStock()`, verrou
dédié, jamais sous zéro).

## Paiement

Une passerelle est un plugin `simple-shop-payment-*` qui implémente
`Gateways\PaymentGatewayInterface` (et, pour les paiements avec redirection,
`Gateways\RedirectPaymentGatewayInterface`). Le résultat renvoyé contient
`payment_reference` : cette référence est unique (table `order_payment`),
donc **une même transaction ne crée jamais deux commandes**, même si le client
recharge la page de retour.

`simple-shop-payment-stripe` propose deux modes, choisis dans sa configuration
(`use_javascript`) :

- `true` : Stripe Elements (PaymentIntent), avec repli `<noscript>` vers la
  page hébergée ;
- `false` : page hébergée Stripe (Checkout Session) avec redirection et retour,
  sans aucun JavaScript.

Dans les deux cas, le serveur vérifie le statut **et** le montant auprès de
Stripe avant de créer la commande.

### Webhook (client parti avant le retour)

Au démarrage du paiement, la passerelle enregistre un **instantané** de la commande en cours
(`pending_payment` : panier, client, livraison, facturation, montant attendu) et n'en confie que l'identifiant à Stripe
(métadonnée `shop_snapshot`). Si le client ferme son navigateur après avoir payé, Stripe appelle
`/stripe/webhook` (événement `payment_intent.succeeded`, signature vérifiée avec `webhook_secret`) et
`Services\OrderFinalizer` crée la commande sans session : même idempotence (référence = PaymentIntent), un seul jeu d'e-mails,
déstockage identique. Le montant encaissé et le total recalculé du panier doivent correspondre, sinon la commande n'est pas
créée et l'incident est journalisé pour rapprochement manuel. Configuration pas à pas : `private/prompts/TODO.md`.

## Types de produits

Le plugin ne connaît aucun métier (livres, formations…). Un **type de produit** se déclare dans le blueprint de page du thème
ou d'un plugin, par un bloc `shop:` dont chaque clé désigne une méthode statique `Classe::méthode` :

```yaml
# themes/bookstore/blueprints/book.yaml
shop:
  price_calculation: 'Grav\Theme\Bookstore::bookPrice'      # obligatoire : fn($page, array $options, PriceContext $ctx): ?Price
  shipping: 'Grav\Theme\Bookstore::bookShipping'            # fn($page, array $options): ?array{weight_g, thickness_mm} (null = rien à expédier)
  presentation: 'Grav\Theme\Bookstore::bookPresentation'    # fn($page, array $options): array{label, image}
  # stock: …   options: …                                    # facultatifs
  tax: TVA_5_5                                               # code de taxe du type (sinon taxes.default)
```

- Une page est de ce type si son gabarit porte le nom du blueprint (`book.md` → `book`). Une clé absente reprend le
  comportement générique (`Product\GenericProduct` : `product_price`, `product_quantity`, `weight` en g, `thickness` en mm, pas d'options).
- **Repli** : une page dont le gabarit n'est pas un type déclaré mais qui a un `product_price` est un produit générique (type `default`).
- **Contrat du prix** (`Pricing\Price`) : montants HT et TTC en centimes, devise, code et taux de taxe (points de base),
  base de saisie (`ht`|`ttc`). Un résultat `null` = pas vendable maintenant. Le contexte (`Product\PriceContext`) fournit la date,
  la quantité, la devise du site, la taxe du type et la base de saisie par défaut.
- **Découverte et cache** (`Product\ProductTypes`) : blueprints du thème actif et flux `blueprints://pages`, liste gardée en cache
  tant que les fichiers ne changent pas. Une méthode introuvable est journalisée et listée par l'API (`product-types`) ; un type dont
  le `price_calculation` est introuvable n'est pas chargé.
- Le cœur passe toujours par `Product\Catalog` (`resolve`, `price`, `context`) et jamais par les champs d'une fiche.
- **Twig** : `shop_price(route)` → `Price` ou `null` ; filtre `shop_money` (`{{ shop_price(page.route).unitTtc|shop_money }}` → « 12,95 € »).
  `get_product_shop_data(route)` ajoute le stock disponible. La zone d'achat `partials/simple-shop/buy-zone.html.twig` est neutre ;
  un thème la surcharge au même chemin (bookstore : libellés de prévente).

## Taxes et frais de livraison

- **Prix.** Saisis HT ou TTC (`taxes.prices_entered`, surchargeable par fiche avec `price_entered_as`), au plus 2 décimales
  (point ou virgule), stockés en centimes. Une ancienne valeur HT à plus de 2 décimales (ex. 12.2749) garde son TTC d'origine (12,95 €),
  qui devient la référence. Panier, paiement, commandes et factures restent en TTC. Devise : `currency` (défaut `EUR`).
  `shop_price_ttc(prix_ht)` (Twig) reste disponible mais ignore le type de produit : préférer `shop_price`.
- **Changement de base HT ↔ TTC** (`taxes.prices_entered`) : au premier passage, les fiches sans `price_entered_as` sont converties
  une fois (`Product\PriceBasisMigration`, événement `onPagesInitialized`) : chaque champ de prix déclaré par le type (`shop.price_fields`,
  défaut `product_price`) est réécrit dans la nouvelle base — de HT vers TTC, le prix public est inchangé. Base déjà appliquée :
  `user://data/simple-shop/.prices-basis` (absente = `ht`). Les prix de livraison ont leur propre réglage (`delivery.prices_entered`).
- **Configuration** : `taxes.prices_entered` (`ht`|`ttc`), `taxes.default` (code unique pour tout le site, livraison comprise),
  `taxes.rates` (ex. `TVA_5_5`, `TVA_20`). L'ancienne clé `vat_rate` n'est plus lue.
- **Contraintes globales** (`Delivery/ShippingConstraints`, bloc `constraints` de `carriers.yaml`, écran Boutique > Transporteurs) :
  lois et offres sur le prix de livraison, sans rien de propre à un type de produit. Une contrainte = nom, types d'articles concernés
  (`product_types` ; type d'un article = son type de produit, ex. `book`, ou `default` pour un produit générique), modes exclus (`except_modes`) et
  règles (poids du colis de/à, montant TTC des articles concernés de/à, effet `minimum` | `maximum` | `fixed`, valeur € TTC, commentaire).
  Ordre d'application = ordre de la liste ; dans une contrainte, la première règle qui correspond s'applique ; bornes incluses.
  Exemple livré pour ce site : la loi Darcos (livres : 3,00 € sous 35 €, 0,01 € au-delà, retrait sur place exclu).
- **Retrait sur place** (mode manuel `kind: local_pickup`) : prix unique (`flat_price`, 0 par défaut), adresse du lieu de retrait
  (`pickup_address` : street, postal_code, city) et distance maximale (`max_distance_km`). L'adresse est géocodée à l'enregistrement (`geo`) ;
  le mode n'est proposé que si la commune du client (code postal + ville, géocodés une fois par session) est à au plus cette distance, à vol d'oiseau.
  Géocodeur : `delivery.geocoding.url` (service public de la Base Adresse Nationale par défaut, `Delivery/Geocoder`).

### Prix de livraison (`carriers.yaml`)

Chaque mode a un **type de calcul** (`pricing`) :
- `flat` — **forfait** : un seul prix (`flat_price`), quel que soit le colis ; pas de marge (0 = gratuit, avant les contraintes globales) ;
- `provider` — **tarif du plugin** transporteur (modes `fournisseur.mode` seulement), grille livrée et mise à jour avec le plugin ;
- `grid` — **grille personnalisée** (`rules`), importable / exportable en CSV depuis l'administration
  (colonnes `zone;poids_de_g;poids_jusqua_g;panier_de_eur;panier_jusqua_eur;epaisseur_max_mm;prix_eur`, séparateur `;`, virgule décimale).
Sans `pricing` : `grid` pour un mode manuel, `provider` pour un mode de plugin (ou `grid` si sa grille est surchargée).

Chaîne de calcul d'un mode : **prix du palier ou du forfait (HT par défaut, `delivery.prices_entered`) + marge** (`margin: {fixed, percent}` sur le mode ; pas de marge sur un palier à 0)
**→ TVA du site → « offert dès » du mode → contraintes globales → arrondi au centime**. L'emballage (`delivery.packaging`, 40 g et 6 mm par défaut) est ajouté au poids et à l'épaisseur du panier
(les épaisseurs des exemplaires s'additionnent) avant le choix des modes. Voir `ShippingManager::priceTtc()`.

### Suivi de colis

Numéro saisi dans Admin2 (`PATCH /orders/{reference}/tracking`, table `order_tracking`), repris dans l'e-mail « expédiée » avec le lien `delivery.tracking_url`
(défaut : suivi La Poste). À saisir avant de passer la commande en « Expédiée ».

## Commandes, factures, événements

- `Orders\OrderManager` : statuts (`paid`, `processing`, `shipped`,
  `delivered`, `cancelled`, `refunded`), historique (`order_status_history`),
  recherche filtrée, export CSV comptable (`;`, UTF-8 BOM, virgule décimale).
- `Orders\OrderAmounts` : **montants en centimes**, seule source pour le panier, le paiement (montant envoyé à Stripe),
  la commande et la facture. Une ligne = prix unitaires HT et TTC, code et taux de taxe (points de base), quantité ;
  livraison = une ligne au taux du site. Total payé = somme des TTC.
- **Instantané à la commande** (migration additive, `InventoryManager::ADDED_COLUMNS`) : `order.currency`, `total_cents`,
  `shipping_ht_cents`, `shipping_ttc_cents`, `shipping_tax_code`, `shipping_tax_bp` ; `order_item.unit_ht_cents`,
  `unit_ttc_cents`, `tax_code`, `tax_bp`, `product_type`. Les colonnes décimales (`total_amount`, `price`) restent remplies
  pour l'affichage et les exports. Les commandes antérieures (centimes vides) sont relues depuis leurs montants décimaux
  et le taux figé sur leur facture.
- `Invoicing\*` : factures Factur-X, **profil EN 16931** (seul à porter le montant d'arrondi BT-114), numérotation, suivi de
  l'e-reporting. Prix net des lignes = HT ; un bloc TVA par taux (base = somme des HT, TVA = arrondi(base × taux)) ;
  l'écart avec le montant encaissé va en arrondi (BT-114) ; commande payée : déjà payé (BT-113) = encaissé, reste dû 0.
  Catégorie de TVA `S` (taux réduit compris). XML validé contre le XSD officiel (`tests/invoice.php`).
  La facture PDF est jointe à l'e-mail de confirmation. Les anomalies sont journalisées dans `logs/simple-shop/invoices.log`.
- `Services\OrderMailer` : e-mails de confirmation, de notification vendeur et
  de changement de statut (`status_emails`, par défaut expédiée / annulée /
  remboursée).

### Événement `onSimpleShopOrderStatusChanged`

Déclenché par `OrderManager::updateStatus()` à chaque changement réel, et à la
création de la commande (`old_status = null`). Charge utile (`$event['…']`) :

| Clé | Type | Sens |
|---|---|---|
| `order_reference` | string | référence de la commande |
| `old_status` | string\|null | statut précédent (`null` à la création) |
| `new_status` | string | nouveau statut |
| `changed_by` | string\|null | compte à l'origine du changement (`null` si automatique) |

```php
public static function getSubscribedEvents(): array
{
    return ['onSimpleShopOrderStatusChanged' => ['onOrderStatusChanged', 0]];
}

public function onOrderStatusChanged(Event $event): void
{
    if ($event['new_status'] === 'shipped') {
        // prévenir un service externe, etc.
    }
}
```

## Administration (Admin2)

Le plugin s'appuie sur le plugin `api` et `admin2` (l'ancienne administration
n'est plus supportée). Entrée de menu « Boutique » ; page composant web
`admin-next/pages/simple-shop.js` : commandes (filtres, détail, changement de
statut, historique, facture, export CSV), transporteurs (édition YAML
validée), adresses bannies.

API (préfixe `/api/v1/simple-shop`), permissions `api.simple-shop.read` /
`api.simple-shop.write` (modèle de groupe « gestionnaire boutique » : `config/groups.dist.yaml`) :

`GET /orders`, `GET /orders/export`, `GET /orders/{reference}`,
`PATCH /orders/{reference}/status`, `PATCH /orders/{reference}/invoice-status`,
`GET /orders/{reference}/invoice`, `GET|PUT /carriers`, `PUT|DELETE /carriers/{key}`, `POST /carriers/simulate`,
`PUT /carrier-constraints` (`{constraints: [...]}`), `POST /carriers/providers/{id}/test`, `GET|POST /bans`,
`DELETE /bans/{email}`.

## Configuration et données

- `simple-shop.yaml` : valeurs par défaut (routes, OTP, zones de livraison, TVA, e-mails de statut…).
- La configuration réelle du site (`user/config`) n'est **pas** versionnée ni
  déployée : seuls les modèles `*.dist.yaml` le sont, sans secret. Les clés
  Stripe et les identifiants SMTP se saisissent sur le serveur.
- Transporteurs : `user/config/plugins/simple-shop/carriers.yaml`
  (modèle : `carriers.dist.yaml`), zones : `FR-METRO`.
- Base SQLite : `user/data/simple-shop/shop.db` (schéma `shop.sql`, migration
  additive automatique).

## Structure

- `simple-shop.php` : abonnements aux événements, routes (via le plugin `mvc`).
- `classes/Controllers/` : `CartController` (tunnel), `CustomerController`.
- `classes/Services/` : `CustomerManager`, `OtpThrottle`, `OrderMailer`, `AddressFields`.
- `classes/Inventory/` : `InventoryManager`, `CartStorage`.
- `classes/Delivery/` : `ZoneResolver`, `CarrierConfig`.
- `classes/Orders/`, `classes/Invoicing/`, `classes/Gateways/`, `classes/Api/`.
- `templates/`, `css/`, `admin-next/`.

Documents de conception : `CDC-E-REPORTING.md`, `CHOIX-CARRIER.md`,
`SHIPPING-PLATFORM-API.md`.
