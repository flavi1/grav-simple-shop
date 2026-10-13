# Simple Shop

Plugin e-commerce pour Grav 2.0 : panier, identification du client par code
à usage unique (OTP) envoyé par e-mail, livraison par zones, paiement
(passerelles séparées, ex. Stripe), commandes, factures Factur-X et
administration via l'API et Admin2.

Périmètre assumé : vente à des particuliers (B2C), **France métropolitaine
hors Corse**, en euros, un seul taux de TVA. Un code postal hors zone est
refusé avec un message clair (`AddressFields::isDeliverablePostalCode()`).

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

Limite connue : si le client ferme son navigateur après avoir payé et avant
le retour, la commande n'est pas créée (un webhook serait nécessaire ; voir
`private/prompts/TODO.md`).

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
`api.simple-shop.write` :

`GET /orders`, `GET /orders/export`, `GET /orders/{reference}`,
`PATCH /orders/{reference}/status`, `PATCH /orders/{reference}/invoice-status`,
`GET /orders/{reference}/invoice`, `GET|PUT /carriers`, `GET|POST /bans`,
`DELETE /bans/{email}`.

## Configuration et données

- `simple-shop.yaml` : valeurs par défaut (routes, OTP, TVA, e-mails de statut…).
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
