<?php

namespace Grav\Plugin\SimpleShop\Services;

use Grav\Common\Grav;
use Grav\Plugin\SimpleShop\Config\Routes;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager;
use Grav\Plugin\SimpleShop\Payments\PaymentLedger;

/**
 * E-mails des documents saisis dans l'administration : envoi du document (PDF joint, commentaire du vendeur, lien de
 * paiement), reçu de chaque paiement, copie acquittée quand tout est réglé. Non bloquant (journalisé).
 */
final class DocumentMailer
{
    /** Vrai pendant un enregistrement sans e-mail au client (case « ne pas prévenir le client » de l'administration). */
    public static bool $mute = false;

    public function __construct(private Grav $grav)
    {
    }

    /** Lien public du document (page de paiement) : https://site/document/<jeton>. */
    public static function documentUrl(array $doc): string
    {
        $grav = Grav::instance();
        $route = Routes::get((array) $grav['config']->get('plugins.simple-shop', []), 'document');

        return rtrim((string) $grav['base_url_absolute'], '/') . $route . '?t=' . $doc['access_token'];
    }

    /** Envoie le document au client (pièce jointe : le PDF émis). */
    public function sendDocument(array $doc, ?string $comment = null): bool
    {
        $c = (array) ($doc['payload']['customer'] ?? []);
        $email = trim((string) ($c['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $st = DocumentManager::settlement($doc);
        $path = DocumentManager::pdfPath($doc);

        return (new OrderMailer($this->grav))->sendTemplate('email/document.html.twig', $this->vars($doc) + [
            'comment'   => $comment ?? (string) ($doc['payload']['comment'] ?? ''),
            'remaining' => $st['remaining_cents'],
        ], $doc['kind_label'] . ' ' . $doc['invoice_number'], $email, $path);
    }

    /** Reçu d'un paiement ; si le document est réglé, la copie acquittée est jointe. */
    public function sendReceipt(array $doc, array $payment): bool
    {
        $c = (array) ($doc['payload']['customer'] ?? []);
        $email = trim((string) ($c['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || (int) $payment['amount_cents'] <= 0) {
            return false;
        }
        $st = DocumentManager::settlement($doc);
        $paid = $st['state'] === 'paid';
        $attachment = $paid && $doc['kind'] !== 'payment_request' ? DocumentManager::pdfPath($doc, 'paid') : null;

        return (new OrderMailer($this->grav))->sendTemplate('email/document-receipt.html.twig', $this->vars($doc) + [
            'payment_amount' => (int) $payment['amount_cents'],
            'payment_method' => PaymentLedger::methodLabel((string) $payment['method']),
            'payment_date'   => date('d/m/Y', strtotime((string) $payment['paid_at']) ?: time()),
            'remaining'      => $st['remaining_cents'],
            'fully_paid'     => $paid,
        ], ($paid ? 'Règlement reçu, merci — ' : 'Paiement reçu — ') . $doc['invoice_number'], $email, $attachment);
    }

    private function vars(array $doc): array
    {
        $c = (array) ($doc['payload']['customer'] ?? []);

        return [
            'document'      => $doc,
            'kind_label'    => $doc['kind_label'],
            'number'        => $doc['invoice_number'],
            'customer_name' => trim(((string) ($c['firstname'] ?? '')) . ' ' . ((string) ($c['lastname'] ?? ''))) ?: (string) ($c['company'] ?? ''),
            'total'         => (int) $doc['total_cents'],
            'currency'      => (string) ($doc['payload']['amounts']['currency'] ?? 'EUR'),
            'due_date'      => $doc['due_date'] ? date('d/m/Y', strtotime((string) $doc['due_date']) ?: time()) : '',
            'url'           => self::documentUrl($doc),
        ];
    }
}
