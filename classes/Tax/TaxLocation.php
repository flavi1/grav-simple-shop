<?php

namespace Grav\Plugin\SimpleShop\Tax;

/** Adresse fiscale : pays (ISO 3166-1, deux lettres), région (code), code postal. Immuable. */
final class TaxLocation
{
    public readonly string $country;
    public readonly string $region;
    public readonly string $postcode;

    public function __construct(string $country, string $region = '', string $postcode = '')
    {
        $this->country = strtoupper(trim($country));
        $this->region = strtoupper(trim($region));
        $this->postcode = strtoupper(str_replace(' ', '', trim($postcode)));
    }

    /**
     * Correspondance avec une ligne de la table des taux : null si la ligne ne s'applique pas, sinon un score de précision
     * (code postal 3, région 2, pays 1, toutes 0).
     */
    public function match(array $row): ?int
    {
        if ($row['country'] !== '' && $row['country'] !== $this->country) {
            return null;
        }
        if ($row['region'] !== '' && $row['region'] !== $this->region) {
            return null;
        }
        if ($row['postcodes'] !== []) {
            foreach ($row['postcodes'] as $pattern) {
                if (self::postcodeMatches($this->postcode, (string) $pattern)) {
                    return 3;
                }
            }

            return null;
        }

        return $row['region'] !== '' ? 2 : ($row['country'] !== '' ? 1 : 0);
    }

    /** Code postal : exact, préfixe avec « * » (ex. 20*), ou intervalle numérique « 97100...97199 ». */
    public static function postcodeMatches(string $postcode, string $pattern): bool
    {
        $pattern = strtoupper(str_replace(' ', '', trim($pattern)));
        if ($pattern === '' || $postcode === '') {
            return false;
        }
        if (str_contains($pattern, '...')) {
            [$min, $max] = explode('...', $pattern, 2);

            return ctype_digit($postcode) && ctype_digit($min) && ctype_digit($max) && (int) $postcode >= (int) $min && (int) $postcode <= (int) $max;
        }
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($postcode, substr($pattern, 0, -1));
        }

        return $postcode === $pattern;
    }
}
