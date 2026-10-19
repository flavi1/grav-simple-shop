<?php

namespace Grav\Plugin\SimpleShop\Invoicing;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Inventory\InventoryManager;
use Grav\Plugin\SimpleShop\Orders\OrderManager;
use RocketTheme\Toolbox\Event\Event;

/**
 * Gestionnaire des factures Factur-X et de leur statut e-reporting (voir
 * `CDC-E-REPORTING.md`, table `invoice` dans `shop.sql`).
 *
 * ITÉRATION 1 (mode manuel transitoire, voir CDC section 4) : le statut
 * e-reporting est modifié manuellement par le marchand via un formulaire
 * dédié dans `/admin/simple-shop-orders`
 * (voir classes/Api/ShopApiController.php). Les options
 * proposées par ce formulaire sont volontairement limitées à celles de
 * l'itération 1 (voir {@see self::getManualStatusOptions()}) : les autres
 * valeurs de {@see self::STATUSES} (transmitted, received, refused,
 * collected) sont réservées à l'itération 2 (mode automatisé API/CRON, non
 * implémentée ici), où elles seraient positionnées par des webhooks/l'API de
 * la Plateforme Agréée plutôt que par un choix manuel.
 *
 * TRADUCTION DES STATUTS (décision explicite) : les valeurs stockées en base
 * (colonne `invoice.ereporting_status`) sont en anglais, par cohérence avec
 * `OrderManager::STATUSES` (paid, processing...). Correspondance avec les
 * termes légaux français du CDC :
 *
 *   - not_submitted => non_depose
 *   - submitted      => depose
 *   - transmitted    => transmis
 *   - received        => recu
 *   - refused         => refuse / rejete
 *   - collected       => encaisse
 *   - disabled        => desactive
 *
 * ÉVÉNEMENT DÉDIÉ : chaque changement de statut e-reporting déclenche
 * `onSimpleShopInvoiceStatusChanged` (voir
 * {@see self::fireInvoiceStatusChangedEvent()}), symétrique à
 * `onSimpleShopOrderStatusChanged` (voir OrderManager). Cet événement est
 * prévu pour permettre à un plugin tiers d'implémenter l'itération 2 du CDC
 * (envoi API vers la Plateforme Agréée, CRON de rattrapage...) sans modifier
 * ce plugin.
 *
 * B2B / B2C : conformément à la décision produit actuelle, TOUTES les
 * commandes sont traitées comme B2C (`customer_type = 'b2c'`) — voir
 * `CustomerManager`/`CartController`, qui ne collectent aujourd'hui aucune
 * information SIRET/TVA côté client. Le champ `customer_type` existe en
 * base pour permettre une évolution future sans migration de schéma.
 *
 * JOURNALISATION : les erreurs liées à la facturation (génération du PDF,
 * non-conformité XSD...) sont centralisées dans le fichier dédié
 * `logs/simple-shop/invoices.log` via {@see InvoiceLogger}, plutôt que dans
 * le log général de Grav.
 *
 * @package Grav\Plugin\SimpleShop\Invoicing
 */
class InvoiceManager
{
    /**
     * Préfixe utilisé pour la numérotation légale des factures (voir
     * {@see self::generateInvoiceNumber()}). Format complet :
     * `FACT-{année}-{compteur sur 5 chiffres}` (ex. FACT-2026-00001).
     *
     * @var string
     */
    private const INVOICE_NUMBER_PREFIX = 'FACT';

    /**
     * Nombre maximal de tentatives en cas de collision (improbable) lors de
     * la génération d'un numéro de facture.
     *
     * @var int
     */
    private const MAX_NUMBER_GENERATION_ATTEMPTS = 5;

    /**
     * Table des statuts e-reporting disponibles : valeur technique (stockée
     * en base, colonne `invoice.ereporting_status`) => libellé lisible pour
     * l'admin. Voir la documentation de classe pour la correspondance avec
     * les termes légaux français du CDC.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'not_submitted' => 'Non déposée',
        'submitted'     => 'Déposée',
        'transmitted'   => 'Transmise',
        'received'      => 'Reçue',
        'refused'       => 'Refusée / rejetée',
        'collected'     => 'Encaissée',
        'disabled'      => 'Désactivée (avant obligation e-reporting B2C)',
        'not_applicable' => 'Sans objet (pas une facture)',
    ];

    /**
     * Retourne le libellé lisible d'un statut e-reporting, ou le statut brut
     * si inconnu.
     *
     * @param string $status Statut technique.
     *
     * @return string Le libellé à afficher.
     */
    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    /**
     * Retourne la date seuil `date_b2c_begin` (configuration du plugin) à
     * partir de laquelle l'obligation d'e-reporting B2C s'applique (voir CDC
     * section 3 : statut `desactive`/`disabled`).
     *
     * @return \DateTimeImmutable
     */
    public static function getDateB2cBegin(): \DateTimeImmutable
    {
        $raw = (string) (Grav::instance()['config']->get('plugins.simple-shop.date_b2c_begin') ?: '2027-09-01');

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception $e) {
            return new \DateTimeImmutable('2027-09-01');
        }
    }

    /**
     * Détermine si l'obligation d'e-reporting B2C est active à la date
     * donnée (par défaut : maintenant).
     *
     * @param \DateTimeImmutable|null $now Date de référence (pour les tests).
     *
     * @return bool
     */
    public static function isB2cEreportingActive(?\DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new \DateTimeImmutable();

        return $now >= self::getDateB2cBegin();
    }

    /**
     * Retourne le taux de TVA unique appliqué à l'ensemble de la boutique
     * (configuration `plugins.simple-shop.taxes`, voir Services\TaxManager ; ex. 0.055 pour 5,5 %).
     *
     * ⚠️ Limitation assumée (voir échanges de conception) : un seul taux
     * pour toute la boutique. Une gestion multi-taux par produit n'est pas
     * implémentée ici.
     *
     * @return float
     */
    public static function getVatRate(): float
    {
        return \Grav\Plugin\SimpleShop\Services\TaxManager::defaultRate();
    }

    /**
     * Retourne les informations légales du vendeur (émetteur des factures),
     * depuis la configuration `plugins.simple-shop.seller.*`.
     *
     * @return array{
     *     name: string, address_line1: string, address_line2: string,
     *     postal_code: string, city: string, country: string,
     *     siret: string, vat_number: string
     * }
     */
    public static function getSellerData(): array
    {
        $config = (array) (Grav::instance()['config']->get('plugins.simple-shop.seller') ?? []);
        $invoicing = (array) (Grav::instance()['config']->get('plugins.simple-shop.invoicing') ?? []);

        return [
            'name'          => (string) ($config['name'] ?? ''),
            'address_line1' => (string) ($config['address_line1'] ?? ''),
            'address_line2' => (string) ($config['address_line2'] ?? ''),
            'postal_code'   => (string) ($config['postal_code'] ?? ''),
            'city'          => (string) ($config['city'] ?? ''),
            'country'       => (string) ($config['country'] ?? 'FR'),
            'siret'         => (string) ($config['siret'] ?? ''),
            'vat_number'    => (string) ($config['vat_number'] ?? ''),
            'billing_mode'  => (string) ($invoicing['billing_mode'] ?? 'B2'),
            'notes'         => array_filter((array) ($invoicing['notes'] ?? []), static fn ($v) => is_string($v) && trim($v) !== ''),
        ];
    }

    /** Vrai si le document (table `invoice`) appartient à la commande. */
    public static function belongsToOrder(int $invoiceId, string $orderReference): bool
    {
        $stmt = InventoryManager::getDatabase()->prepare('SELECT 1 FROM invoice WHERE id = :id AND order_reference = :ref');
        $stmt->execute([':id' => $invoiceId, ':ref' => $orderReference]);
        $ok = $stmt->fetchColumn() !== false;
        $stmt->closeCursor();

        return $ok;
    }

    /**
     * Retourne les options de statut e-reporting proposées par le select
     * manuel de l'admin (itération 1 du CDC, voir documentation de classe) :
     * `not_submitted`, `submitted`, et `disabled` UNIQUEMENT si l'obligation
     * B2C n'est pas encore active (voir {@see self::isB2cEreportingActive()}).
     *
     * @return array<string, string>
     */
    public static function getManualStatusOptions(): array
    {
        $options = [
            'not_submitted' => self::STATUSES['not_submitted'],
            'submitted'     => self::STATUSES['submitted'],
        ];

        if (!self::isB2cEreportingActive()) {
            $options['disabled'] = self::STATUSES['disabled'];
        }

        return $options;
    }

    /**
     * Récupère la facture existante d'une commande, ou en crée une nouvelle
     * (avec numéro légal réservé, voir {@see self::generateInvoiceNumber()})
     * si aucune n'existe encore.
     *
     * Le numéro de facture est réservé dès le premier appel (typiquement au
     * premier affichage de la commande dans l'admin), INDÉPENDAMMENT de la
     * génération du PDF (voir {@see self::ensurePdfGenerated()}, appelée
     * séparément et paresseusement au clic sur "Télécharger la facture").
     * Cela garantit une numérotation stable et sans rupture de séquence dès
     * que l'admin consulte la commande, sans attendre un premier
     * téléchargement.
     *
     * @param string $orderReference Référence de la commande.
     *
     * @return array La ligne `invoice` (existante ou nouvellement créée).
     */
    public static function getOrCreateInvoice(string $orderReference): array
    {
        $db = InventoryManager::getDatabase();

        $stmt = $db->prepare('SELECT * FROM invoice WHERE order_reference = :ref');
        $stmt->execute([':ref' => $orderReference]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($existing) {
            return $existing;
        }

        $vatRate = self::getVatRate();
        $defaultStatus = self::isB2cEreportingActive() ? 'not_submitted' : 'disabled';

        $attempts = 0;
        $lastException = null;

        while ($attempts < self::MAX_NUMBER_GENERATION_ATTEMPTS) {
            $attempts++;
            $invoiceNumber = self::generateInvoiceNumber($db);

            try {
                $insert = $db->prepare('
                    INSERT INTO invoice (order_reference, invoice_number, customer_type, ereporting_status, vat_rate)
                    VALUES (:ref, :number, :type, :status, :vat)
                ');
                $insert->execute([
                    ':ref'    => $orderReference,
                    ':number' => $invoiceNumber,
                    ':type'   => 'b2c',
                    ':status' => $defaultStatus,
                    ':vat'    => $vatRate,
                ]);

                $lastException = null;
                break;
            } catch (\PDOException $e) {
                // Collision improbable sur invoice_number (contrainte UNIQUE) :
                // on retente avec un nouveau numéro calculé.
                $lastException = $e;
            }
        }

        if ($lastException !== null) {
            InvoiceLogger::error(
                'Échec de réservation du numéro de facture pour la commande ' . $orderReference
                . ' après ' . self::MAX_NUMBER_GENERATION_ATTEMPTS . ' tentatives : ' . $lastException->getMessage()
            );

            throw $lastException;
        }

        self::fireInvoiceStatusChangedEvent(Grav::instance(), $orderReference, null, $defaultStatus);

        $stmt->execute([':ref' => $orderReference]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Génère le prochain numéro de facture légal, séquentiel par année
     * civile, au format `FACT-{année}-{compteur sur 5 chiffres}`.
     *
     * @param \PDO $db Connexion active à la base du plugin.
     *
     * @return string Le numéro de facture généré.
     */
    private static function generateInvoiceNumber(\PDO $db): string
    {
        return self::nextNumber($db, self::INVOICE_NUMBER_PREFIX);
    }

    /**
     * Prochain numéro d'une suite (`FACT` : factures, acomptes et avoirs ; `DP` : demandes de paiement), séquentiel par
     * année civile : `{préfixe}-{année}-{compteur sur 5 chiffres}`.
     */
    public static function nextNumber(\PDO $db, string $prefix): string
    {
        $year = date('Y');
        $prefixLike = $prefix . '-' . $year . '-%';

        $stmt = $db->prepare('
            SELECT invoice_number FROM invoice
            WHERE invoice_number LIKE :like
            ORDER BY invoice_number DESC
            LIMIT 1
        ');
        $stmt->execute([':like' => $prefixLike]);
        $last = $stmt->fetchColumn();
        $stmt->closeCursor();

        $nextCounter = 1;
        if ($last) {
            $parts = explode('-', (string) $last);
            $lastCounter = (int) end($parts);
            $nextCounter = $lastCounter + 1;
        }

        return sprintf('%s-%s-%05d', $prefix, $year, $nextCounter);
    }

    /**
     * Change le statut e-reporting d'une facture et déclenche
     * systématiquement l'événement `onSimpleShopInvoiceStatusChanged`, SAUF
     * si le nouveau statut est strictement identique à l'ancien.
     *
     * @param string $orderReference Référence de la commande dont la facture doit être mise à jour.
     * @param string $newStatus      Nouveau statut (doit exister dans {@see self::STATUSES}).
     *
     * @return bool Vrai si la mise à jour a été effectuée ou n'était pas
     *              nécessaire ; faux si la facture est introuvable ou le
     *              statut invalide.
     */
    public static function updateEreportingStatus(string $orderReference, string $newStatus): bool
    {
        if (!array_key_exists($newStatus, self::STATUSES)) {
            return false;
        }

        $db = InventoryManager::getDatabase();

        $stmt = $db->prepare('SELECT * FROM invoice WHERE order_reference = :ref');
        $stmt->execute([':ref' => $orderReference]);
        $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$invoice) {
            return false;
        }

        $oldStatus = $invoice['ereporting_status'];

        if ($oldStatus === $newStatus) {
            return true;
        }

        $update = $db->prepare('
            UPDATE invoice SET ereporting_status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE order_reference = :ref
        ');
        $update->execute([':status' => $newStatus, ':ref' => $orderReference]);

        self::fireInvoiceStatusChangedEvent(Grav::instance(), $orderReference, $oldStatus, $newStatus);

        return true;
    }

    /**
     * Déclenche l'événement `onSimpleShopInvoiceStatusChanged`, consommable
     * par n'importe quel plugin/écouteur tiers — notamment prévu pour
     * implémenter l'itération 2 du CDC (envoi API vers la Plateforme
     * Agréée, CRON de rattrapage des factures `not_submitted`).
     *
     * Payload de l'événement (accessible via `$event['...']`) :
     *  - `order_reference` (string)      : référence de la commande concernée ;
     *  - `old_status`      (string|null) : statut précédent (null à la création) ;
     *  - `new_status`      (string)      : nouveau statut.
     *
     * @param Grav        $grav           Conteneur Grav.
     * @param string      $orderReference Référence de la commande concernée.
     * @param string|null $oldStatus      Statut précédent (null si création).
     * @param string      $newStatus      Nouveau statut.
     *
     * @return void
     */
    public static function fireInvoiceStatusChangedEvent(Grav $grav, string $orderReference, ?string $oldStatus, string $newStatus): void
    {
        $grav->fireEvent('onSimpleShopInvoiceStatusChanged', new Event([
            'order_reference' => $orderReference,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
        ]));
    }

    /**
     * Génère (si nécessaire, sinon renvoie le fichier déjà en cache) le PDF
     * Factur-X d'une commande, et retourne son chemin absolu sur disque.
     *
     * Génération PARESSEUSE (à la demande) : le PDF n'est produit qu'au
     * premier appel — que ce soit au clic sur "Télécharger la facture" dans
     * `/admin/simple-shop-orders`, ou lors de l'envoi automatique de la
     * facture par e-mail au client à la validation du paiement (voir
     * `Services\OrderMailer` — le client ne télécharge JAMAIS sa facture
     * lui-même) — puis mis en cache dans
     * `user://data/simple-shop/invoices/{invoice_number}.pdf` (chemin
     * mémorisé en base, colonne `invoice.pdf_path`).
     *
     * @param string $orderReference Référence de la commande.
     *
     * @return string|null Le chemin absolu du PDF, ou null en cas d'échec
     *                      (commande introuvable, erreur de génération...).
     */
    public static function ensurePdfGenerated(string $orderReference): ?string
    {
        $invoice = self::getOrCreateInvoice($orderReference);

        $existingPath = $invoice['pdf_path'] ?? null;
        if ($existingPath && file_exists($existingPath)) {
            return $existingPath;
        }

        $order = OrderManager::getOrder($orderReference);
        if (!$order) {
            InvoiceLogger::error("Commande introuvable lors de la génération de la facture : {$orderReference}");

            return null;
        }

        $db = InventoryManager::getDatabase();
        $stmtItems = $db->prepare('SELECT * FROM order_item WHERE order_reference = :ref ORDER BY id ASC');
        $stmtItems->execute([':ref' => $orderReference]);
        $orderItems = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);

        $grav = Grav::instance();
        $storageDir = $grav['locator']->findResource('user://data/simple-shop/invoices', true, true);
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $pdfPath = $storageDir . '/' . $invoice['invoice_number'] . '.pdf';

        try {
            $pdfContent = (new Invoice($order, $orderItems, $invoice, self::getSellerData()))->build();
        } catch (\Throwable $e) {
            InvoiceLogger::error(
                'Erreur lors de la génération de la facture Factur-X (' . $orderReference . ') : ' . $e->getMessage()
            );

            return null;
        }

        file_put_contents($pdfPath, $pdfContent);

        $update = $db->prepare('UPDATE invoice SET pdf_path = :path, updated_at = CURRENT_TIMESTAMP WHERE order_reference = :ref');
        $update->execute([':path' => $pdfPath, ':ref' => $orderReference]);

        InvoiceLogger::info("Facture générée avec succès : {$invoice['invoice_number']} (commande {$orderReference}) -> {$pdfPath}");

        return $pdfPath;
    }
}
