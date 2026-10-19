# Conception — Facturation manuelle, acomptes, paiements (19/10/2026)

> Conception validée le 19/10/2026 (16 h 21) ; développement en cours (§12). Décisions de 16 h 21 : §11.
> Besoin : émettre depuis l'administration des factures Factur-X « à régler » (lignes libres), des demandes de paiement et
> des factures d'acompte ; suivre ce qui est payé (rien, en partie, tout) ; préparer remboursements et avoirs. Premier
> usage : flavieng.com (auto-entrepreneur, prestations B2B, site vitrine sans boutique publique). senonevero.flavieng.com
> n'en a pas besoin : tout est désactivé par défaut.

## 1. Décisions déjà prises (échange du 19/10/2026)

| Sujet | Décision |
|---|---|
| Plugin séparé ? | Non : tout dans simple-shop, derrière l'option `invoicing.manual` (désactivée par défaut). |
| Boutique publique | Option `shop.public` (activée par défaut) : désactivée, plus de catalogue, panier ni tunnel ; seule reste la page de paiement d'une facture. |
| Menu Admin2 | « Boutique » devient **« Ventes »**. |
| Cas couverts | Facture à régler, demande de paiement (facture émise au règlement), facture d'acompte. |
| Moyens de paiement | Carte (Stripe, lien de paiement) et virement (IBAN sur la facture). |
| Régime de TVA, échéance, pénalités | Dans la configuration du vendeur (chaque site a le sien), avec des valeurs par défaut d'auto-entrepreneur (§9). |
| « Payée » | N'est plus un statut de commande : c'est l'**état de règlement** de la facture, calculé à partir des paiements (§5). |

## 2. Comment font les logiciels existants

| | Dolibarr | Odoo | Invoice Ninja |
|---|---|---|---|
| Paiement | Objet **Paiement** séparé, lié à une ou plusieurs factures | Écriture de paiement **lettrée** avec la facture | Objet **Payment** lié aux factures |
| État « payée » | Calculé : impayée / partiellement payée / payée | Calculé (`payment_state`) : non payée, partielle, payée, extournée | Calculé : envoyée, partielle, payée |
| Acompte | **Facture d'acompte**, puis déduite de la facture finale | Facture d'acompte (*down payment*), déduite de la finale | Champ « acompte/partiel » sur la facture |
| Remboursement | Paiement négatif / avoir | Paiement sortant ou avoir (*credit note*) | *Refund* sur le paiement |
| Avoir | **Document distinct** (facture d'avoir) qui annule tout ou partie d'une facture | Idem (*credit note*) | *Credit* |
| Brouillon | Oui, numéro définitif attribué à la validation | Oui | Oui |

Constantes : **(1)** la facture ne change plus après émission ; **(2)** les paiements sont des objets à part, et l'état de
règlement est **calculé** ; **(3)** remboursement (de l'argent qui sort) et avoir (un document qui réduit ce qui est dû)
sont deux choses différentes ; **(4)** numéro attribué à la validation, pas au brouillon (suite sans trou).

## 3. Les documents

| Document | Numéroté (suite légale `FACT-`) | Code Factur-X | Quand |
|---|---|---|---|
| **Demande de paiement** | Non (suite `DP-`, sans valeur fiscale) | aucun (PDF simple) | Paiement attendu **avant** la prestation, sans acompte facturé : la facture n'est émise qu'au règlement (comme la boutique actuelle). |
| **Facture** | Oui | 380 | Prestation réalisée ou convenue ; à régler avant l'échéance. Ou émise automatiquement au règlement d'une demande de paiement ou d'une commande de la boutique. |
| **Facture d'acompte** | Oui | 386 | Un versement est demandé avant la fin de la prestation. Pour une prestation de services, la TVA (si on y est soumis) est due à l'encaissement de l'acompte. |
| **Facture finale** (de solde) | Oui | 380 | Toutes les lignes de la prestation, **moins les acomptes déjà facturés** (rappelés par leur numéro) ; reste à payer = total − acomptes réglés. |
| **Avoir** | Oui (même suite) | 381 | Annule tout ou partie d'une facture émise (erreur, geste commercial, annulation). Référence la facture d'origine. Plus tard. |
| **Reçu de paiement** | Non | aucun (PDF simple) | Envoyé à chaque paiement reçu : montant, date, moyen, référence, reste à payer. |

Une **facture ne se modifie jamais** après émission : une erreur se corrige par un avoir, pas par une nouvelle version.

### Paiement en plusieurs fois : deux façons, au choix à la création

1. **Échéancier sur une seule facture** (le plus simple) : une facture, plusieurs échéances (montants et dates), autant de
   paiements que nécessaire. La facture porte la date de la dernière échéance et l'échéancier en clair dans ses
   conditions de paiement (Factur-X n'a qu'une date d'échéance structurée). Chaque paiement donne un reçu.
2. **Acomptes facturés** : une facture d'acompte par versement demandé, puis la facture finale qui les déduit. Plus lourd,
   mais c'est la règle quand on encaisse **avant** d'avoir rendu le service et qu'on est assujetti à la TVA.

Pour un auto-entrepreneur en franchise de TVA, la première façon suffit presque toujours.

## 4. « Facture acquittée » : les deux états du PDF

Le Factur-X émis (« à régler ») est **le** document légal : son XML ne change plus jamais. Quand la facture est
entièrement réglée, le client reçoit une **copie acquittée** : même PDF, même numéro, même XML intégré, avec en plus une
mention visible « ACQUITTÉE le …, par carte/virement, réf. … ». C'est la pratique admise (même facture + mention
« acquittée », date, moyen et référence du règlement). Entre-temps, chaque paiement partiel donne un **reçu** (§3).

## 5. Les paiements (« transactions »)

Un objet à part, rattaché à **un document** (facture, facture d'acompte, demande de paiement).

| Champ | Exemple | Rôle |
|---|---|---|
| `id` | 42 | |
| `document` | `FACT-2026-0012` ou `DP-2026-0003` | À quoi se rapporte le paiement |
| `amount_cents` | `30000`, ou `-5000` pour un remboursement | Signé : négatif = argent rendu au client |
| `currency` | `EUR` | |
| `method` | `card`, `transfer`, `check`, `cash`, `other` | |
| `paid_at` | 2026-10-21 | Date de valeur (date du virement, pas de sa saisie) |
| `recorded_at`, `recorded_by` | 2026-10-22 10:14, `flavien` ou `stripe` | Qui l'a enregistré (compte admin, ou webhook) |
| `external_ref` | identifiant de paiement Stripe, référence du virement | Unique : un même paiement Stripe n'est jamais enregistré deux fois (comme `order_payment` aujourd'hui) |
| `note` | « 2ᵉ échéance » | |

**Stockage** : une table `payment` dans la base SQLite existante plutôt que des fichiers JSON. Commandes, factures et
paiements y sont déjà, ce qui permet d'enregistrer un paiement et de mettre à jour l'état dans la même opération (pas de
fichier à moitié écrit). La table `order_payment` actuelle y sera reprise.

**État de règlement d'un document** (calculé, jamais saisi) : `dû` = total TTC − acomptes déduits − avoirs ;
`réglé` = somme des paiements (remboursements déduits).

| État | Condition |
|---|---|
| À régler | réglé = 0 |
| Partiellement réglée | 0 < réglé < dû |
| Réglée | réglé = dû |
| En retard | pas réglée et échéance dépassée (affichage seulement) |
| Trop-perçu | réglé > dû (à rembourser ou à garder en avoir) |
| Remboursée | il y a eu des remboursements et réglé = 0 |

**Remboursement ou avoir ?** Vos « transactions négatives » couvrent les **remboursements** (de l'argent rendu). Un
**avoir** est autre chose : un document numéroté qui réduit ce que le client doit, avec ou sans argent rendu. Les deux
seront nécessaires ; seul le remboursement est un paiement.

## 6. Commande, facture et statuts

- La **commande** garde son statut de déroulement : *en attente*, *en préparation*, *expédiée*, *livrée*, *annulée*, plus
  *terminée* pour une prestation. « Payée » disparaît de cette liste : l'admin affiche à côté l'**état de règlement**.
- La **facture** garde son statut de **transmission** (désactivé / non déposée / déposée…), modifiable dès l'émission : la
  réforme demande de déposer une facture B2B au moment où on l'émet, pas après le paiement. Quand une facture de
  prestation est réglée, l'admin propose « à déclarer encaissée » (statut à transmettre à la plateforme agréée pour les
  prestations de services, à confirmer avec elle).
- Pas de nouveau champ de statut : les deux qui existent suffisent, l'état de règlement est calculé.
- **Reprise des commandes existantes** : chaque commande « payée » reçoit un paiement (carte, montant de la commande,
  référence Stripe de `order_payment`), puis son statut devient « en préparation » ou reste celui qu'elle avait.

Une facture manuelle crée elle aussi une commande (*source : manuelle*), qui regroupe ses documents : demande de paiement,
acomptes, facture finale, avoirs, paiements.

## 7. Saisie dans Admin2 (menu « Ventes »)

Onglets : **Commandes** (existant, avec la colonne « Règlement »), **Factures** (nouveau), Transporteurs, etc. Boutons
« Nouvelle facture » et « Nouvelle demande de paiement », sous le tableau des commandes et dans l'onglet Factures.

1. **Client** (en premier : il décide du HT ou du TTC) : e-mail, qui pré-remplit tout si le client est connu ;
   *particulier* ou *organisation* ; raison sociale, SIREN, n° de TVA, nom, prénom, adresse de facturation ; facultatif :
   adresse de livraison, référence de commande du client.
2. **Lignes** : intitulé, quantité, prix unitaire, type de taxe (votre table de taux). Prix saisis en **HT pour une
   organisation, en TTC pour un particulier** (usage B2B / obligation d'afficher le TTC au consommateur), avec un
   interrupteur pour changer. En franchise de TVA, une seule colonne « Prix » (HT = TTC). Ligne « Acompte déjà facturé »
   ajoutée automatiquement sur une facture finale.
3. **Conditions** : type de document ; échéance (à réception, 30 jours, date choisie) ou échéancier ; moyens de paiement
   (carte, virement) ; commentaire ajouté à l'e-mail.
4. **Aperçu** du PDF, puis **« Émettre et envoyer »** (le numéro est attribué à ce moment) ou « Garder en brouillon ».

Onglet **Factures** : numéro, type, client, date, échéance, montant, **réglé / reste**, état de règlement, transmission ;
filtres (à régler, en retard, réglées). Dans le détail d'une facture : les paiements, « Enregistrer un paiement » (virement,
chèque…), « Rembourser », « Renvoyer au client », télécharger le PDF (original ou acquitté).

## 8. Côté client

- E-mail : votre commentaire, le PDF, le montant à régler, l'échéance, et un **lien de paiement**.
- Le lien ouvre `/facture/<jeton>` (jeton aléatoire, impossible à deviner, sans compte client) : le document, le reste à
  payer, « Payer par carte » (Stripe, pour le reste ou l'échéance suivante) et l'IBAN pour un virement.
- Paiement Stripe → le webhook enregistre le paiement → reçu par e-mail, ou copie acquittée si tout est réglé.
- Cette page existe même quand la boutique publique est désactivée.

## 9. Configuration du vendeur et valeurs par défaut (auto-entrepreneur)

| Réglage | Valeur par défaut | Remarque |
|---|---|---|
| Régime de TVA | **Franchise en base** : mention « TVA non applicable, art. 293 B du CGI » | Autres choix : TVA sur les encaissements (habituel pour les services), TVA sur les débits (mention « Option pour le paiement de la taxe d'après les débits »). |
| Forme juridique | « EI » à côté du nom | Obligatoire pour un entrepreneur individuel. |
| Échéance | **30 jours** après la date de la facture | Délai légal par défaut entre professionnels quand rien n'est convenu ; maximum 60 jours. |
| Pénalités de retard | **3 fois le taux d'intérêt légal** | Minimum légal entre professionnels ; mention obligatoire en B2B. |
| Indemnité forfaitaire de recouvrement | **40 €** | Obligatoire en B2B ; absente des factures aux particuliers. |
| Escompte | « Pas d'escompte pour paiement anticipé » | Mention obligatoire en B2B. |
| Coordonnées bancaires | IBAN, BIC, titulaire | Affichées si le virement est proposé. |
| Nature de l'opération | Prestation de services | Nouvelle mention de la réforme (biens, services, mixte). |

Ces valeurs viennent de règles générales du Code de commerce et du CGI : à faire valider par un expert-comptable.

## 10. Plan de développement proposé

1. Paiements : table `payment`, état de règlement calculé, reprise des commandes payées, « Payée » retirée des statuts.
2. Factur-X « à régler » : échéance, conditions, moyens de paiement, reste dû ; mentions B2B ; régime de TVA du vendeur.
3. Facture manuelle (brouillon → émission), onglet Factures, menu « Ventes », options `invoicing.manual` et `shop.public`.
4. Page `/facture/<jeton>`, paiement Stripe d'une facture, reçus, copie acquittée.
5. Demande de paiement, factures d'acompte et facture finale, échéancier.
6. Plus tard : avoirs et remboursements (Stripe compris), dépôt automatique sur une plateforme agréée.

## 11. Décisions du 19/10/2026 (16 h 21)

1. **Numérotation** : une seule suite `FACT-` (boutique et factures manuelles, acomptes et avoirs compris).
2. **Devis** : hors périmètre ; prévu plus tard (manuels, et émission automatique sur demande, ex. création de site sur
   flavieng.com). Le modèle le permet : un document de type `quote` (suite `DEV-`) dans la même table que les demandes de
   paiement, transformable en demande de paiement ou en facture.
3. **Montant payé par carte** : choisi à la création de la commande manuelle — hors périmètre ; prévu par le champ
   `options.amount_policy` du document (`remaining` aujourd'hui ; plus tard `next_installment`, `free`).
4. **Relances** : hors périmètre ; prévues par la date d'échéance et l'état de règlement calculé (une tâche planifiée
   pourra lister les documents « en retard » et écrire dans une table `reminder`).
5. **Paiements par les plugins de passerelle** : une facture se paie avec les passerelles de simple-shop
   (`simple-shop-payment-stripe`, `simple-shop-payment-test`, futur plugin de virement). Plusieurs moyens disponibles :
   **le client choisit**.
6. **Moyens de paiement par origine de commande** : `payments.methods.shop` (ex. carte + test) et
   `payments.methods.manual` (ex. virement + carte + test) ; une passerelle n'apparaît que si son plugin est activé.
7. **Paiements** : table SQLite (pas de JSON).

## 12. Implémentation (état au fil du développement)

### Passerelles

- Contrat existant (`PaymentGatewayInterface`, `RedirectPaymentGatewayInterface`) : tunnel de la boutique (session, panier).
- Nouveau contrat **`DocumentPaymentGatewayInterface`** : payer un document (facture, demande de paiement) sans panier ni
  compte client, à partir d'un **`PaymentContext`** (document, commande, montant en centimes, devise, e-mail, URL de la page,
  URL de retour). Méthodes : `renderDocumentPayment()`, `processDocumentPayment()` ; variante à redirection
  (`RedirectDocumentPaymentGatewayInterface` : `startHostedDocumentPayment()`, `confirmDocumentReturn()`) ; passerelle
  **hors ligne** (virement) : `isOffline()` → elle n'affiche que des instructions, le paiement est enregistré par le vendeur
  à réception (ou, plus tard, par un rapprochement bancaire).
- Une même passerelle peut implémenter les deux contrats (Stripe, test).
- `Gateways\GatewayRegistry` : liste des passerelles (événement `onSimpleShopRegisterGateways`), filtrée par origine
  (`shop` / `manual`) et par contrat.
- Webhook Stripe : métadonnée `shop_document_token` → paiement enregistré sur le document (idempotent par identifiant de
  paiement). Stripe règle les documents par sa **page hébergée** (Checkout) uniquement : pas de JavaScript sur le site.
- Adresse de retour : `…/document?t=<jeton>&moyen=<passerelle>&retour=1` ; la passerelle vérifie la session (jeton du
  document, statut payé) et renvoie le montant encaissé.

### Données (SQLite)

- `payment` : `id, order_reference, invoice_id, amount_cents (signé), currency, method, paid_at, recorded_at,
  recorded_by, external_ref UNIQUE, note`.
- `order` : + `source` (`shop` | `manual`). Statuts : `draft`, `pending`, `processing`, `shipped`, `delivered`,
  `completed`, `cancelled`, `refunded` ; **`paid` disparaît** (anciennes commandes : `processing` + un paiement repris).
- `invoice` (tous les documents) : + `kind` (`invoice`, `deposit`, `credit_note`, `payment_request`, plus tard `quote`),
  `total_cents`, `due_cents`, `issued_at`, `due_date`, `access_token`, `source_invoice_id`, `related_invoice_id`, `payload`
  (JSON : client, lignes figées, conditions, commentaire, moyens de paiement, déductions d'acomptes, échéancier, options) ;
  plusieurs documents par commande (contrainte d'unicité de `order_reference` retirée par migration).

### Réalisé (19/10/2026)

- Étapes 1 à 4 du plan : paiements, documents (facture, acompte, demande de paiement, facture au règlement), page publique de
  paiement, e-mails (document, reçu avec copie acquittée), écran Admin2 (onglet « Factures », éditeur de vente manuelle,
  documents d'une commande, saisie d'un paiement rattaché à un document), Stripe et passerelle de test pour les documents.
- Vérifié de bout en bout dans une copie locale de Grav 2.2.5 + Admin2 (brouillon, aperçu, acompte 30 %, facture finale avec
  acompte déduit, demande de paiement réglée par la passerelle de test → facture acquittée envoyée, virement saisi) ; XML
  validés contre le XSD EN 16931.
- Montant d'une passerelle supérieur au reste dû : enregistré (« trop-perçu », à rembourser) plutôt que refusé, l'argent
  étant déjà encaissé.

### Reste à faire (hors périmètre actuel, conception compatible)

- Devis (`quote`), avoirs (`credit_note`) depuis l'écran, échéancier, montant libre payé par carte, relances automatiques.
- Validation au testeur FNFE d'une facture en franchise en base (catégorie E) avec les vrais identifiants du vendeur.
