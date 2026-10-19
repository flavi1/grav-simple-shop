<?php

namespace Grav\Plugin\SimpleShop\Gateways;

use Grav\Common\Grav;

/**
 * Virement bancaire (moyen hors ligne) : le client voit le montant, l'IBAN, le BIC, le titulaire et la référence à
 * indiquer ; le vendeur enregistre le paiement à réception (Admin2 > Ventes). Activé par `payments.bank_transfer.enabled`
 * (coordonnées dans la même section). Pourra rejoindre un plugin dédié (`simple-shop-payment-transfer`) sans changer de
 * contrat.
 */
final class BankTransferGateway implements DocumentPaymentGatewayInterface
{
    public function getId(): string
    {
        return 'bank_transfer';
    }

    public function getLabel(): string
    {
        return 'Virement bancaire';
    }

    public function isOffline(): bool
    {
        return true;
    }

    /** Coordonnées bancaires configurées (`holder`, `iban`, `bic`, `instructions`). */
    public static function account(): array
    {
        $c = (array) Grav::instance()['config']->get('plugins.simple-shop.payments.bank_transfer', []);

        return [
            'holder'       => trim((string) ($c['holder'] ?? '')),
            'iban'         => strtoupper(preg_replace('/\s+/', '', (string) ($c['iban'] ?? ''))),
            'bic'          => strtoupper(preg_replace('/\s+/', '', (string) ($c['bic'] ?? ''))),
            'instructions' => trim((string) ($c['instructions'] ?? '')),
        ];
    }

    /** IBAN lisible par groupes de 4 caractères. */
    public static function formatIban(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }

    public function renderDocumentPayment(PaymentContext $ctx): string
    {
        $a = self::account();
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $rows = [
            ['Montant', $ctx->amountText() . "\u{00A0}" . $ctx->currency],
            ['Titulaire', $a['holder']],
            ['IBAN', self::formatIban($a['iban'])],
            ['BIC', $a['bic']],
            ['Référence à indiquer', $ctx->documentNumber],
        ];
        $html = '<div class="shop-transfer"><dl>';
        foreach ($rows as [$label, $value]) {
            if ($value !== '') {
                $html .= '<dt>' . $e($label) . '</dt><dd>' . $e($value) . '</dd>';
            }
        }
        $html .= '</dl>';
        if ($a['instructions'] !== '') {
            $html .= '<p>' . $e($a['instructions']) . '</p>';
        }
        $html .= '<p>Le document sera marqué réglé dès réception du virement.</p></div>';

        return $html;
    }

    public function processDocumentPayment(PaymentContext $ctx, array $postData): array
    {
        return ['success' => false, 'error' => 'Le virement se fait depuis votre banque, avec les coordonnées indiquées.'];
    }
}
