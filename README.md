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

## Taxes et frais de livraison

- **Prix HT.** Les prix des fiches produit (`product_price`) sont saisis hors taxe, jusqu'à 4 décimales. `InventoryManager::getProductData`
  les convertit en TTC (`TaxManager::toTtc`, arrondi au centime) : panier, paiement, commandes et factures restent en TTC.
  Dans Twig, `shop_price_ttc(prix_ht)` donne le TTC.
- **Configuration** : `taxes.prices_entered` (`ht`|`ttc`), `taxes.default` (code unique pour tout le site, livraison comprise),
  `taxes.rates` (ex. `TVA_5_5`, `TVA_20`). L'ancienne clé `vat_rate` n'est plus lue.
- **Loi Darcos** (`delivery.book_law`, classe `Delivery/LegalShippingFloor`) : sous `threshold` € TTC de livres, frais minimum `below`
  (3,00 €) ; à partir de `threshold`, minimum `at_or_above` (0,01 €). Appliqué dans `ShippingManager::getAvailableCarriers` ;
  un mode peut s'en exclure avec `legal_floor: false` (retrait sur place). À faire valider juridiquement.

## Commandes, factures, événements

- `Orders\OrderManager` : statuts (`paid`, `processing`, `shipped`,
  `delivered`, `cancelled`, `refunded`), historique (`order_status_history`),
  recherche filtrée, export CSV comptable (`;`, UTF-8 BOM, virgule décimale).
- `Invoicing\*` : factures Factur-X (profil BASIC), numérotation, suivi de
  l'e-reporting. La facture PDF est jointe à l'e-mail de confirmation. Les
  anomalies sont journalisées dans `logs/simple-shop/invoices.log`.
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
`GET /orders/{reference}/invoice`, `GET|PUT /carriers`, `GET|POST /bans`,
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
