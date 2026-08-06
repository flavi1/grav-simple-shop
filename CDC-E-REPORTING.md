Cahier des charges : Intégration de la Facturation Électronique et de l'E-Reporting (B2B / B2C)

1. Objectif et Contexte
L'objectif est d'intégrer dans la boutique PHP la gestion du cycle de vie des factures conformément à la réforme de la facturation électronique, en gérant à la fois les flux B2B (e-invoicing) et B2C (e-reporting). L'implémentation se fera en deux temps : un mode manuel transitoire, suivi d'un mode automatisé via API (activable par option).
2. Règles de Gestion et Distinction B2B / B2C

* Le système doit déterminer automatiquement le type de client lors du checkout :
* B2B : Présence d'un numéro de SIRET / TVA intracommunautaire valide.
* B2C : Consommateur particulier.


* Format unique des factures : Génération systématique au format **Factur-X** (rétrocompatible : PDF lisible + XML structuré) pour toutes les factures (B2B et B2C), simplifiant ainsi la base de code.

3. Gestion du Cycle de Vie et Statuts des Factures en Base de Données
La table des factures doit intégrer un champ de statut et un champ de suivi du e-reporting. Les statuts normatifs gérés sont :

* `non_depose` : Facture générée mais non transmise.
* `depose` : Facture transmise à la Plateforme Agréée (PA) ou déclarée comme telle.
* `transmis` : Acheminée au destinataire.
* `recu` : Réceptionnée par la PA du client.
* `refuse` / `rejete` : Facture rejetée par le destinataire ou la PA.
* `encaisse` : Paiement validé.
* `desactive` : Statut temporaire affecté aux factures B2C émises avant la date seuil réglementaire (`DATE_B2C_BEGIN`). Ce statut disparaîtra des options une fois la date dépassée.

4. Itération 1 : Mode Manuel Transitoire

* Fonctionnement : Le marchand gère le dépôt de manière externe via le panel de sa PA.
* Champs et Interface :
* Un champ de sélection (select) manuel est disponible dans le back-office pour chaque facture.
* Options initiales du select : `non_depose`, `depose`, `desactive` (si B2C et date courante < `DATE_B2C_BEGIN`).
* Le marchand modifie manuellement le statut de `non_depose` vers `depose` après avoir effectué l'action sur le portail de sa PA.



5. Itération 2 : Mode Automatisé (API & CRON)

* Activation : Une case à cocher dans les réglages du module permet d'activer le mode automatique (nécessite la saisie d'une clé API PA par le client-vendeur).
* Logique d'attribution initiale :
* Pour le B2B : Le mode auto tente immédiatement d'envoyer le Factur-X via l'API de la PA.
* En cas de succès : Statut initial = `depose`.
* En cas d'échec : Statut initial = `non_depose` avec journalisation de l'erreur.


* Pour le B2C :
* Si date courante < `DATE_B2C_BEGIN` : Statut initial = `desactive`.
* Si date courante >= `DATE_B2C_BEGIN` (ou selon les obligations d'e-reporting applicables) : Tentative d'envoi automatisé ou stockage pour l'e-reporting.




* Mécanisme de rattrapage (CRON) : Un script planifié (CRON) balaie périodiquement les factures en échec (`non_depose`) pour retenter la transmission vers l'API de la PA.
* Maîtrise des statuts : Dès l'activation du mode auto, le champ select manuel se transforme en un affichage en lecture seule (span), les webhooks et l'API de la PA devenant les maîtres absolus de la mise à jour des statuts.

6. Implémentation itération 1 (état actuel du code)

* Classe `Grav\Plugin\SimpleShop\Invoicing\InvoiceManager` (table `invoice` de
  `shop.sql`) : gère la génération du PDF Factur-X (profil BASIC, via
  `atgp/factur-x` + `setasign/fpdi-fpdf`), la numérotation légale séquentielle
  (`FACT-{année}-{compteur}`), et le statut e-reporting manuel.
* Les valeurs de statut stockées en base sont traduites en anglais par
  cohérence avec `OrderManager::STATUSES` (voir doc de classe
  `InvoiceManager` pour la table de correspondance avec les termes ci-dessus :
  `non_depose` => `not_submitted`, `depose` => `submitted`,
  `transmis` => `transmitted`, `recu` => `received`,
  `refuse`/`rejete` => `refused`, `encaisse` => `collected`,
  `desactive` => `disabled`).
* Le select manuel de l'admin (`/admin/simple-shop-orders`) n'expose que les
  options pertinentes pour cette itération 1 : `not_submitted`, `submitted`,
  et `disabled` (uniquement tant que l'obligation e-reporting B2C n'est pas
  encore active, voir `date_b2c_begin`). Les autres valeurs (`transmitted`,
  `received`, `refused`, `collected`) existent déjà en base pour l'itération 2
  mais ne sont pas positionnables manuellement.
* B2B : non implémenté à ce stade — toutes les commandes sont actuellement
  traitées comme B2C (voir `CustomerManager`/`CartController`, qui ne
  collectent aucune information SIRET/TVA côté client).
* Journalisation dédiée : toute anomalie liée à la facturation (échec de
  génération du PDF, non-conformité XSD détectée...) est écrite dans
  `logs/simple-shop/invoices.log` (voir `Invoicing\InvoiceLogger`), séparément
  du log général de Grav (`logs/grav.log`), afin de faciliter le suivi/l'audit
  de ces sujets spécifiquement réglementaires.
* La facture PDF Factur-X est jointe automatiquement à l'e-mail de
  confirmation de commande envoyé au client (voir `Services\OrderMailer::sendOrderConfirmation()`),
  généré à la demande lors de cet envoi si elle n'existe pas encore en cache.
  Le client ne télécharge donc jamais sa facture lui-même.
* Interface admin (`/admin/simple-shop-orders`, voir
  `templates/admin/simple-shop-orders.html.twig`) : chaque commande affiche
  désormais deux lignes enfants supplémentaires dans le treegrid — « Adresse
  de livraison » et « Facture » (numéro, lien de téléchargement ADMIN
  UNIQUEMENT via la route virtuelle `simple-shop-invoice-download`, et
  formulaire dédié de changement de statut e-reporting).

7. Événement Grav dédié : `onSimpleShopInvoiceStatusChanged`

Chaque changement de statut e-reporting (y compris la création de la facture,
avec `old_status = null`) déclenche l'événement Grav
`onSimpleShopInvoiceStatusChanged` (voir
`InvoiceManager::fireInvoiceStatusChangedEvent()`), symétrique à
`onSimpleShopOrderStatusChanged` (voir `OrderManager` et `NOTES-TODO.md`).

Payload de l'événement (accessible via `$event['...']`) :
  - `order_reference` (string)      : référence de la commande concernée ;
  - `old_status`      (string|null) : statut e-reporting précédent (`null`
    uniquement à la création de la facture) ;
  - `new_status`      (string)      : nouveau statut e-reporting.

Cet événement est le point d'extension prévu pour implémenter l'ITÉRATION 2
du présent CDC (mode automatisé API & CRON) SANS modifier ce plugin : un
plugin tiers pourra s'y abonner pour, par exemple, déclencher l'envoi API vers
la Plateforme Agréée dès le passage à `not_submitted`, ou pour construire le
mécanisme de rattrapage CRON décrit en section 5.
