# Notes / TODO — Simple Shop

Notes de suivi entre sessions de travail. Non destiné à devenir la
documentation finale du plugin, mais à capturer des points en suspens pour
ne pas les perdre d'une session à l'autre.

## ⚠️ Historique d'un incident de commit (à lire avant de continuer)

Un audit du dépôt (voir échange du jour) a révélé qu'une session de travail
précédente sur la facturation Factur-X avait produit du code qui n'a en
réalité PRESQUE JAMAIS été committé (seuls `css/admin-orders.css` et
`classes/Services/OrderMailer.php` l'avaient été), laissant le plugin dans un
état CASSÉ (`OrderMailer.php` référençait une classe `Invoicing\InvoiceManager`
inexistante). Tous les fichiers manquants ont été restaurés en une seule fois
à la suite de cet audit. **Si un comportement inattendu apparaît autour de la
facturation, vérifier en priorité que ce commit de restauration a bien été
appliqué dans son intégralité** (`composer.json`, `shop.sql`,
`classes/Inventory/InventoryManager.php`, tout `classes/Invoicing/`,
`simple-shop.php`, `simple-shop.yaml`, `blueprints.yaml`,
`templates/admin/simple-shop-orders.html.twig`, `CDC-E-REPORTING.md`).

Un second incident similaire a été constaté sur
`templates/simple-shop/email-validation.html.twig` : le correctif Bug 3
(propagation de `back_url`, libellé "Continuer") avait été annoncé comme
commité (hash `aacfce0`) mais le fichier réel ne contenait toujours pas ces
changements lors d'une vérification ultérieure. **Toujours vérifier le
contenu réel d'un fichier après un commit annoncé, en particulier pour les
templates Twig**, qui semblent plus sujets à ce type d'incident dans ce
projet que les classes PHP.

## Fait : suppression de la friction UX sur la vérification e-mail du tunnel

**Contexte** : la page « Votre adresse e-mail a bien été validée / Passer à
l'étape du paiement » (`/checkout/email`) s'affichait systématiquement à
chaque passage dans le tunnel d'achat, même lorsque l'e-mail était déjà
vérifié — imposant un clic totalement superflu ("Continuer"). Cette page
n'a en réalité de raison d'être QUE lorsque l'e-mail n'est PAS encore vérifié
(saisie du code OTP).

**Correctifs appliqués** (objectif : aussi peu de friction que possible) :
1. `CartController::step1Cart()` : la politique `$alwaysVerify` transmise à
   `CustomerManager::resolvePostSaveDestination()` est passée de `true` à
   `false` — désormais alignée avec `CustomerController::account()`. Si
   l'e-mail est déjà vérifié et n'a pas changé, on va directement à l'étape
   de livraison, sans passer par `/checkout/email` du tout.
2. `CustomerController::verifyEmail()` : ajout d'un garde-fou qui redirige
   IMMÉDIATEMENT (côté serveur, sans rendu de page) vers `back_url` si
   l'e-mail est déjà vérifié — couvre aussi la navigation directe/retour
   arrière vers cette URL après une vérification déjà effectuée. La branche
   "déjà validé" du template `email-validation.html.twig` devient un simple
   filet de sécurité défensif, non atteint en pratique.

Le paramètre `$alwaysVerify` de `resolvePostSaveDestination()` est conservé
(valeur par défaut `false`) pour ne pas fermer la porte à un besoin futur
(ex. forcer une re-vérification périodique), mais n'est plus utilisé avec
`true` nulle part dans ce plugin à ce jour.

## À traiter : documentation de l'événement de changement de statut de commande

**Contexte** : `OrderManager::updateStatus()` (voir
`classes/Orders/OrderManager.php`) déclenche un événement Grav à chaque
changement réel de statut d'une commande (y compris à la création de la
commande, dans `CartStorage::toOrder()`, avec `old_status = null`).

**À inclure dans la future documentation du plugin** :

1. **Nom exact de l'événement** : `onSimpleShopOrderStatusChanged`
   (déclenché par `OrderManager::fireStatusChangedEvent()`).

2. **Payload de l'événement** (accessible via `$event['...']` dans un
   listener) :
   - `order_reference` (string) : référence de la commande ;
   - `old_status` (string|null) : statut précédent (`null` uniquement lors
     de la création de la commande) ;
   - `new_status` (string) : nouveau statut (voir `OrderManager::STATUSES`
     pour les valeurs possibles : `paid`, `processing`, `shipped`,
     `delivered`, `cancelled`, `refunded`).

3. **Un ou plusieurs exemples d'utilisation dans un plugin tiers**, à
   rédiger. Idées de cas d'usage concrets à illustrer :
   - Envoyer un e-mail au client lors du passage à `shipped` (numéro de
     suivi, etc.) ;
   - Notifier un webhook externe (comptabilité, CRM...) à chaque
     changement ;
   - Journaliser l'historique des statuts dans une table dédiée (le schéma
     actuel ne conserve que le *dernier* statut, pas l'historique — voir
     point suivant).

   Squelette d'exemple à développer :

   ```php
   public static function getSubscribedEvents(): array
   {
       return [
           'onSimpleShopOrderStatusChanged' => ['onOrderStatusChanged', 0],
       ];
   }

   public function onOrderStatusChanged(Event $event): void
   {
       $ref = $event['order_reference'];
       $old = $event['old_status'];
       $new = $event['new_status'];

       // ... votre logique ...
   }
   ```

## Question ouverte : niveau de complétude suffisant ?

**Pas sûr de vouloir aller plus loin dans la gestion des commandes pour le
moment.** Question à trancher ensemble à la prochaine session :

> Est-ce que l'état actuel (liste des commandes en treegrid dans l'admin,
> changement de statut manuel via formulaire, événement de notification pour
> les plugins tiers) est **suffisant** pour une **toute petite maison
> d'édition** qui fait de la **vente en ligne à des particuliers** ?

Éléments à peser pour cette décision (liste de départ, à affiner) :

- **Historique des statuts** : actuellement non conservé (une commande n'a
  qu'un statut courant, pas de trace des transitions). Est-ce nécessaire
  pour une petite structure, ou l'événement + un plugin tiers d'écoute
  suffit-il si besoin plus tard ?
- **Emails transactionnels automatiques** (confirmation de commande, envoi
  colis, etc.) : à la charge d'un plugin tiers via l'événement, ou à
  intégrer nativement ?
- **Filtrage / recherche** dans la liste des commandes admin (actuellement
  aucun, affichage brut de toutes les commandes) : nécessaire dès
  maintenant ou seulement si le volume grossit ?
- **Facturation** (génération de factures PDF, numérotation légale
  française, etc.) : voir section dédiée ci-dessous, une première
  implémentation existe désormais (Factur-X, itération 1 manuelle).
- **Export comptable** : besoin réel pour une petite structure ou non ?

**Décision à prendre ensemble** : soit on considère que c'est suffisant en
l'état pour ce cas d'usage précis (petite structure, faible volume), soit on
identifie 1-2 points prioritaires à traiter avant de considérer le module
commandes comme "terminé".

## À valider avant mise en production : XML Factur-X généré à la main

**Contexte** : `Invoicing\FacturXXmlBuilder` génère le XML CII (profil
BASIC) entièrement à la main, faute d'outillage de test XSD disponible dans
l'environnement où ce code a été écrit. La validation via
`Atgp\FacturX\XsdValidator` est bien appelée (dans `Invoicing\Invoice::build()`)
mais ses erreurs éventuelles sont **journalisées sans bloquer** la génération
(voir doc de classe `Invoice`), afin qu'un problème de conformité XML
n'empêche jamais l'émission d'une facture pour le marchand.

**Journalisation dédiée** : ces erreurs (et toute autre anomalie liée à la
facturation) sont écrites dans un fichier **séparé** du log général de Grav :
`logs/simple-shop/invoices.log` (voir `Invoicing\InvoiceLogger`), afin de
faciliter leur suivi/audit sans les noyer dans le reste des logs applicatifs.

**À faire avant une mise en production réelle** :
1. Générer une facture de test (commande réelle ou factice) et consulter
   `logs/simple-shop/invoices.log` pour d'éventuelles lignes `WARNING`
   (non-conformité XSD détectée).
2. Valider manuellement le XML produit contre le XSD officiel du profil
   BASIC (fourni dans `vendor/atgp/factur-x/xsd/`), ou via un outil en ligne
   dédié : le **Testeur Factur-X de la FNFE-MPE**
   (https://fnfe-mpe.org/factur-x/testeur-de-factur-x/) permet d'uploader
   directement le PDF généré et d'obtenir un rapport de conformité
   (structure PDF/A-3 + XML CII). Alternative orientée ZUGFeRD allemand :
   https://www.xrechnung.io.
3. Ouvrir le PDF généré dans un lecteur compatible Factur-X/ZUGFeRD (Adobe
   Acrobat affiche nativement les pièces jointes PDF/A-3, dont le XML) pour
   vérifier visuellement le rendu et l'extraction du XML embarqué.
4. Ajuster `FacturXXmlBuilder`/`Invoice::buildBasePdf()` si des non-
   conformités sont détectées.

**Autres limitations connues, assumées pour cette première itération** :
   - un seul taux de TVA pour toute la boutique (`plugins.simple-shop.vat_rate`) ;
   - toutes les commandes traitées comme B2C (`customer_type = 'b2c'` figé) ;
   - devise EUR uniquement ;
   - mise en page PDF minimaliste (FPDF, pas de logo, texte translittéré
     ISO-8859-1 par nécessité technique des polices standard FPDF).

## Fait : facture PDF jointe à l'e-mail de confirmation client

Conformément au CDC (« le client reçoit sa facture par mail dès validation du
paiement, il ne la télécharge jamais lui-même »), `Services\OrderMailer::sendOrderConfirmation()`
génère (à la demande, via `Invoicing\InvoiceManager::ensurePdfGenerated()`) et
joint la facture Factur-X à l'e-mail de confirmation de commande. La
notification vendeur (`sendSellerNotification()`) n'embarque volontairement
PAS cette pièce jointe, le vendeur ayant déjà accès au téléchargement direct
depuis `/admin/simple-shop-orders`. Tout échec de génération est non bloquant
(l'e-mail est envoyé sans pièce jointe, anomalie journalisée dans
`logs/simple-shop/invoices.log`).

## Fait : interface admin des commandes enrichie (adresse de livraison + facture)

`templates/admin/simple-shop-orders.html.twig` affiche désormais, pour
chaque commande du treegrid, deux lignes enfants supplémentaires (avant la
liste des articles) :
  1. « Adresse de livraison » : bloc `<address>` à partir de `order.customer` ;
  2. « Facture » : lien de téléchargement (ADMIN UNIQUEMENT, route virtuelle
     `simple-shop-invoice-download` gérée dans `SimpleShopPlugin::onPagesInitialized()`)
     + formulaire dédié de changement de statut e-reporting.

Le numéro de facture est réservé dès le premier affichage de la commande
dans cette page (voir `InvoiceManager::getOrCreateInvoice()`), indépendamment
de la génération paresseuse du PDF lui-même (déclenchée soit par un clic sur
le lien de téléchargement, soit par l'envoi de l'e-mail de confirmation).

## Note technique : migration additive de la base SQLite

Depuis l'ajout de la table `invoice`, `InventoryManager::getDatabase()`
exécute désormais `shop.sql` uniquement lorsque son contenu (mtime) a changé
depuis la dernière application réussie (marqueur
`user://data/simple-shop/.shop-schema-applied`), et non plus à chaque
ouverture de connexion — voir le correctif "Bug 1" (verrouillage SQLite) plus
récent, qui a remplacé la ré-exécution systématique initialement mise en
place pour la migration additive. Voir la documentation de
`InventoryManager::getDatabase()` pour le détail de ce choix et de ses
garde-fous (une base déjà existante n'est jamais supprimée en cas d'erreur
d'exécution du schéma, contrairement à une base fraîchement créée).
