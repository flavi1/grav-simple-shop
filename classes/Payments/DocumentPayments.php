<?php

namespace Grav\Plugin\SimpleShop\Payments;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager;

/**
 * Enregistre un paiement de passerelle sur un document (page publique du document, retour de paiement, webhook) :
 * montant positif (au-delà du reste dû : enregistré quand même, document « trop-perçu » à rembourser), idempotence par
 * référence de paiement. Les suites (facture émise au
 * règlement d'une demande de paiement, reçu par e-mail) sont faites par l'écouteur de `onSimpleShopPaymentRecorded`
 * (SimpleShopPlugin::onPaymentRecorded()).
 */
final class DocumentPayments
{
    /**
     * @return array{ok: bool, created: bool, error: ?string, payment: ?array}
     */
    public static function recordGatewayPayment(array $doc, string $method, ?string $reference, int $amountCents, string $by = 'client'): array
    {
        if ($reference !== null && $reference !== '' && ($existing = PaymentLedger::findByExternalRef($reference)) !== null) {
            return ['ok' => true, 'created' => false, 'error' => null, 'payment' => $existing];
        }
        $remaining = DocumentManager::settlement($doc)['remaining_cents'];
        if ($amountCents <= 0) {
            return ['ok' => false, 'created' => false, 'error' => 'Montant de paiement invalide.', 'payment' => null];
        }
        if ($amountCents > $remaining) {
            // L'argent est déjà encaissé par la passerelle (double paiement, règlement reçu entre-temps) : on l'enregistre quand
            // même — le document passe « trop-perçu » et le vendeur rembourse l'excédent.
            Grav::instance()['log']->warning("[SimpleShop] Paiement {$reference} ({$amountCents}) supérieur au reste dû ({$remaining}) sur {$doc['invoice_number']} : trop-perçu à rembourser.");
        }
        $r = PaymentLedger::record([
            'order_reference' => (string) $doc['order_reference'],
            'invoice_id'      => (int) $doc['id'],
            'amount_cents'    => $amountCents,
            'currency'        => (string) ($doc['payload']['amounts']['currency'] ?? 'EUR'),
            'method'          => $method,
            'recorded_by'     => $by,
            'external_ref'    => $reference !== '' ? $reference : null,
        ]);

        return ['ok' => true, 'created' => $r['created'], 'error' => null, 'payment' => $r['payment']];
    }
}
