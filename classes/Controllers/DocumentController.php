<?php

namespace Grav\Plugin\SimpleShop\Controllers;

use Grav\Common\Utils;
use Grav\Plugin\Mvc\AbstractController;
use Grav\Plugin\Mvc\Response;
use Grav\Plugin\SimpleShop\Config\PageMeta;
use Grav\Plugin\SimpleShop\Config\Routes;
use Grav\Plugin\SimpleShop\Gateways\DocumentPaymentGatewayInterface;
use Grav\Plugin\SimpleShop\Gateways\GatewayRegistry;
use Grav\Plugin\SimpleShop\Gateways\PaymentContext;
use Grav\Plugin\SimpleShop\Gateways\RedirectDocumentPaymentGatewayInterface;
use Grav\Plugin\SimpleShop\Invoicing\DocumentManager;
use Grav\Plugin\SimpleShop\Payments\DocumentPayments;

/**
 * Page publique d'un document envoyé depuis l'administration (facture, acompte, demande de paiement) : `/document?t=<jeton>`
 * (jeton aléatoire de 32 caractères reçu par e-mail, sans compte client). Le client voit le montant et l'échéance,
 * télécharge le PDF (original, ou copie acquittée une fois réglé) et choisit un moyen de paiement parmi ceux retenus pour
 * le document. Fonctionne sans JavaScript (formulaires, redirections).
 *
 * Paramètres : `t` jeton ; `moyen` passerelle choisie ; `pdf` = original | paid ; `retour` = 1 (retour d'une passerelle
 * à redirection).
 */
class DocumentController extends AbstractController
{
    public function show(): Response
    {
        $token = (string) $this->get('t', FILTER_DEFAULT, '');
        $doc = DocumentManager::enabled() ? DocumentManager::findByToken($token) : null;
        if ($doc === null) {
            return $this->page(['not_found' => true], 404);
        }

        $pdf = (string) $this->get('pdf', FILTER_DEFAULT, '');
        if (in_array($pdf, ['original', 'paid'], true)) {
            $path = DocumentManager::pdfPath($doc, $pdf);
            if ($path === null || !is_file($path)) {
                return $this->page(['not_found' => true], 404);
            }

            return (new Response($this->grav))->setContentType('application/pdf')->useDefaultLayout(false)
                ->setHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
                ->setHeader('Cache-Control', 'private, no-store')
                ->setBody((string) file_get_contents($path));
        }

        $pageUrl = $this->pageUrl($token);
        $settlement = DocumentManager::settlement($doc);
        $gateways = GatewayRegistry::forDocuments((array) ($doc['payload']['methods'] ?? []));
        $ctx = $this->context($doc, $settlement['remaining_cents'], $pageUrl);
        $payable = $settlement['remaining_cents'] > 0 && $doc['kind'] !== 'credit_note';

        // Paiement (formulaire d'une passerelle) ou démarrage d'un paiement hébergé.
        if ($this->isPost() && $payable) {
            $nonce = (string) $this->post('nonce', FILTER_DEFAULT, '');
            $method = (string) $this->post('method', FILTER_DEFAULT, '');
            $gateway = $gateways[$method] ?? null;
            if ($nonce === '' || !Utils::verifyNonce($nonce, PaymentContext::NONCE_ACTION) || !$gateway instanceof DocumentPaymentGatewayInterface) {
                $this->addMessage('Votre session a expiré ou ce moyen de paiement n’est pas disponible : veuillez réessayer.', 'error');

                return $this->redirect($pageUrl, 303);
            }
            $action = (string) $this->post('action', FILTER_DEFAULT, '');
            $ctx = $this->context($doc, $settlement['remaining_cents'], $pageUrl, $method);
            if ($action === 'start_hosted_document_payment' && $gateway instanceof RedirectDocumentPaymentGatewayInterface) {
                $started = $gateway->startHostedDocumentPayment($ctx);
                if (!empty($started['url'])) {
                    return $this->redirect((string) $started['url'], 303);
                }
                $this->addMessage('Impossible de démarrer le paiement : ' . ($started['error'] ?? 'erreur inconnue.'), 'error');

                return $this->redirect($pageUrl . '&moyen=' . rawurlencode($method), 303);
            }
            if ($action === 'execute_document_payment' && !$gateway->isOffline()) {
                return $this->finish($doc, $method, $gateway->processDocumentPayment($ctx, $this->request()->getParsedBody()), $ctx, $pageUrl);
            }
        }

        // Retour d'une passerelle à redirection (vérifié auprès d'elle).
        $method = (string) $this->get('moyen', FILTER_DEFAULT, '');
        if ($this->get('retour', FILTER_DEFAULT, '') === '1' && ($gateways[$method] ?? null) instanceof RedirectDocumentPaymentGatewayInterface) {
            if (!$payable) {
                return $this->redirect($pageUrl, 303);
            }
            $ctx = $this->context($doc, $settlement['remaining_cents'], $pageUrl, $method);
            $result = $gateways[$method]->confirmDocumentReturn($ctx, $this->request()->getQueryParams());
            if (!empty($result['cancelled'])) {
                $this->addMessage('Le paiement a été annulé. Vous pouvez réessayer ou choisir un autre moyen.', 'notice');

                return $this->redirect($pageUrl, 303);
            }

            return $this->finish($doc, $method, $result, $ctx, $pageUrl);
        }

        if ($method === '' && count($gateways) === 1) {
            $method = (string) array_key_first($gateways);
        }
        $selected = $payable ? ($gateways[$method] ?? null) : null;
        if ($selected) {
            $ctx = $this->context($doc, $settlement['remaining_cents'], $pageUrl, $method);
        }

        return $this->page([
            'not_found'    => false,
            'document'     => $doc,
            'settlement'   => $settlement,
            'payable'      => $payable,
            'currency'     => $ctx->currency,
            'gateways'     => array_map(static fn ($g) => $g->getLabel(), $gateways),
            'selected'     => $selected ? $method : null,
            'gateway_html' => $selected ? $selected->renderDocumentPayment($ctx) : '',
            'page_url'     => $pageUrl,
            'pdf_url'      => $pageUrl . '&pdf=original',
            'paid_url'     => $settlement['state'] === 'paid' && $doc['kind'] !== 'payment_request' ? $pageUrl . '&pdf=paid' : null,
            'due_date'     => $doc['due_date'] ? date('d/m/Y', strtotime((string) $doc['due_date']) ?: time()) : '',
        ]);
    }

    /** Résultat d'une passerelle : enregistre le paiement (montant contrôlé, idempotent), puis revient sur la page. */
    private function finish(array $doc, string $method, array $result, PaymentContext $ctx, string $pageUrl): Response
    {
        if (empty($result['success'])) {
            $this->addMessage('Le paiement n’a pas abouti : ' . ($result['error'] ?? 'raison inconnue.'), 'error');

            return $this->redirect($pageUrl . '&moyen=' . rawurlencode($method), 303);
        }
        $amount = isset($result['amount_cents']) ? (int) $result['amount_cents'] : $ctx->amountCents;
        $r = DocumentPayments::recordGatewayPayment($doc, $method, isset($result['payment_reference']) ? (string) $result['payment_reference'] : null, $amount);
        if (!$r['ok']) {
            $this->addMessage((string) $r['error'], 'error');
        } else {
            $this->addMessage('Merci, votre paiement est enregistré. Un reçu vous est envoyé par e-mail.', 'success');
        }

        return $this->redirect($pageUrl, 303);
    }

    /** Contexte de paiement ; l'adresse de retour désigne la passerelle (`moyen`) pour la vérification au retour. */
    private function context(array $doc, int $amount, string $pageUrl, string $method = ''): PaymentContext
    {
        $c = (array) ($doc['payload']['customer'] ?? []);

        return new PaymentContext((int) $doc['id'], (string) $doc['invoice_number'], (string) $doc['kind'], (string) $doc['order_reference'], $amount,
            (string) ($doc['payload']['amounts']['currency'] ?? 'EUR'), (string) ($c['email'] ?? ''),
            $doc['kind_label'] . ' ' . $doc['invoice_number'], $pageUrl,
            $pageUrl . ($method !== '' ? '&moyen=' . rawurlencode($method) : '') . '&retour=1', (string) $doc['access_token']);
    }

    private function pageUrl(string $token): string
    {
        $route = Routes::get((array) $this->grav['config']->get('plugins.simple-shop', []), 'document');

        return rtrim((string) $this->grav['base_url_absolute'], '/') . $route . '?t=' . rawurlencode($token);
    }

    private function page(array $vars, int $code = 200): Response
    {
        return PageMeta::apply($this->view('simple-shop/document', $vars, $code), 'document', (array) $this->grav['config']->get('plugins.simple-shop', []))
            ->setMeta('robots', 'noindex, nofollow');
    }
}
