<?php

namespace Grav\Plugin\SimpleShop\Pricing;

/**
 * Taux de taxe : code (ex. TVA_5_5) + taux en points de base (550 = 5,5 %). Immuable.
 */
final class TaxRate implements \JsonSerializable
{
    public function __construct(public readonly string $code, public readonly int $basisPoints)
    {
        if ($code === '' || $basisPoints < 0 || $basisPoints >= 10000) {
            throw new \InvalidArgumentException("Taux de taxe invalide : {$code} ({$basisPoints} points de base).");
        }
    }

    /** Taux décimal de la configuration (0.055, ou 5.5 en pourcentage) → points de base. */
    public static function fromDecimal(string $code, float $rate): self
    {
        if ($rate > 1) {
            $rate /= 100;
        }

        return new self($code, (int) round($rate * 10000));
    }

    /** Montant TTC d'un montant HT : arrondi au centime, demi supérieur. */
    public function addTo(Money $ht): Money
    {
        return Money::of(self::divRound($ht->amount * (10000 + $this->basisPoints), 10000), $ht->currency);
    }

    /** Montant HT d'un montant TTC : arrondi au centime, demi supérieur. */
    public function removeFrom(Money $ttc): Money
    {
        return Money::of(self::divRound($ttc->amount * 10000, 10000 + $this->basisPoints), $ttc->currency);
    }

    /** Taxe due sur une base HT (arrondie au centime) : règle des factures (TVA par taux = base × taux). */
    public function taxOn(Money $base): Money
    {
        return Money::of(self::divRound($base->amount * $this->basisPoints, 10000), $base->currency);
    }

    /** Taux décimal (0.055), pour l'affichage et les exports. */
    public function toDecimal(): float
    {
        return $this->basisPoints / 10000;
    }

    public function jsonSerialize(): array
    {
        return ['code' => $this->code, 'basis_points' => $this->basisPoints];
    }

    /** Division entière arrondie au plus proche, demi supérieur (montants positifs ou nuls). */
    private static function divRound(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            return -self::divRound(-$numerator, $denominator);
        }

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
