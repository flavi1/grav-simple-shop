<?php

namespace Grav\Plugin\SimpleShop\Delivery;

/**
 * Géocodage d'adresses françaises pour le retrait sur place (distance client ↔ lieu de retrait).
 *
 * Service par défaut : géocodeur de la Géoplateforme (IGN), qui sert la Base Adresse Nationale — données ouvertes
 * (Licence Ouverte), service public sans compte ni clé, successeur de api-adresse.data.gouv.fr. L'API suit le format
 * d'addok (moteur libre) : un géocodeur auto-hébergé compatible peut le remplacer (`delivery.geocoding.url`).
 *
 * Données envoyées : l'adresse du lieu de retrait (à l'enregistrement dans l'administration) et, pour un client,
 * seulement son code postal et sa ville (précision de la commune, suffisante pour un rayon en km).
 *
 * Classe sans dépendance à Grav : testable en ligne de commande (transport injectable).
 */
final class Geocoder
{
    public const DEFAULT_URL = 'https://data.geopf.fr/geocodage/search';

    /** Score minimal de confiance du géocodeur (0 à 1). */
    private const MIN_SCORE = 0.4;

    /** @var string */
    private $url;
    /** @var int */
    private $timeout;
    /** @var callable(string, int): array{status: int, body: string} */
    private $transport;

    public function __construct(array $settings = [], ?callable $transport = null)
    {
        $url = trim((string) ($settings['url'] ?? ''));
        $this->url = preg_match('~^https://[^\s?#]+$~', $url) ? $url : self::DEFAULT_URL;
        $timeout = (int) ($settings['timeout'] ?? 4);
        $this->timeout = $timeout >= 1 && $timeout <= 20 ? $timeout : 4;
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** Réglages `delivery.geocoding` de la configuration du plugin. */
    public static function fromConfig(array $config, ?callable $transport = null): self
    {
        return new self((array) ($config['delivery']['geocoding'] ?? []), $transport);
    }

    /**
     * Adresse complète (lieu de retrait).
     *
     * @return array{lat: float, lon: float, label: string, score: float}|null null = adresse introuvable.
     *
     * @throws \RuntimeException Service injoignable ou réponse illisible.
     */
    public function geocodeAddress(string $street, string $postcode, string $city): ?array
    {
        return $this->search(trim($street . ' ' . $postcode . ' ' . $city), $postcode);
    }

    /**
     * Commune seulement (client) : code postal + ville.
     *
     * @throws \RuntimeException Service injoignable ou réponse illisible.
     */
    public function geocodeTown(string $postcode, string $city): ?array
    {
        return $this->search(trim($postcode . ' ' . $city), $postcode);
    }

    private function search(string $q, string $postcode): ?array
    {
        if (mb_strlen($q) < 3) {
            return null;
        }
        $params = ['q' => $q, 'limit' => 1];
        if (preg_match('/^\d{5}$/', trim($postcode))) {
            $params['postcode'] = trim($postcode);
        }

        $response = ($this->transport)($this->url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), $this->timeout);
        if (($response['status'] ?? 0) !== 200) {
            throw new \RuntimeException('géocodeur : réponse HTTP ' . (int) ($response['status'] ?? 0));
        }
        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($json) || !isset($json['features']) || !is_array($json['features'])) {
            throw new \RuntimeException('géocodeur : réponse illisible');
        }

        $f = $json['features'][0] ?? null;
        $coords = $f['geometry']['coordinates'] ?? null;
        $score = (float) ($f['properties']['score'] ?? 0);
        if (!is_array($coords) || count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1]) || $score < self::MIN_SCORE) {
            return null;
        }

        return [
            'lat'   => round((float) $coords[1], 6),
            'lon'   => round((float) $coords[0], 6),
            'label' => (string) ($f['properties']['label'] ?? ''),
            'score' => round($score, 3),
        ];
    }

    /** Distance à vol d'oiseau en km (formule de haversine). */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Transport par défaut : curl, HTTPS uniquement, TLS vérifié, sans redirection. */
    public static function curlTransport(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'simple-shop (Grav) geocoding',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('géocodeur injoignable (' . $err . ')');
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
