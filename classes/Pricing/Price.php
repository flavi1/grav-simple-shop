<?php

namespace Grav\Plugin\SimpleShop\Pricing;

/**
 * Prix unitaire d'un produit : montant saisi (HT ou TTC), taxe, et l'autre montant dérivé une seule fois. Immuable.
 *
 * C'est la réponse contractuelle d'un calcul de prix (`shop.price_calculation` d'un type de produit) : elle porte tout ce
 * qu'il faut — montant en centimes, devise, code et taux de taxe, base de définition — le cœur n'ajoute ni ne devine rien.
 */
final class Price implements \JsonSerializable
{
    public const HT = 'ht';
    public const TTC = 'ttc';

    private function __construct(
        public readonly Money $unitHt,
        public readonly Money $unitTtc,
        public readonly TaxRate $tax,
        public readonly string $enteredAs
    ) {
    }

    /** @param string $enteredAs Price::HT ou Price::TTC : base sur laquelle le montant a été saisi. */
    public static function fromEntered(Money $entered, TaxRate $tax, string $enteredAs): self
    {
        if ($entered->amount < 0) {
            throw new \InvalidArgumentException('Un prix ne peut pas être négatif.');
        }

        return match ($enteredAs) {
            self::HT  => new self($entered, $tax->addTo($entered), $tax, self::HT),
            self::TTC => new self($tax->removeFrom($entered), $entered, $tax, self::TTC),
            default   => throw new \InvalidArgumentException("Base de prix inconnue : « {$enteredAs} » (ht ou ttc)."),
        };
    }

    public function currency(): string
    {
        return $this->unitTtc->currency;
    }

    /** Montant saisi (référence). */
    public function entered(): Money
    {
        return $this->enteredAs === self::HT ? $this->unitHt : $this->unitTtc;
    }

    /** Totaux d'une ligne de `$quantity` exemplaires. @return array{ht: Money, ttc: Money} */
    public function line(int $quantity): array
    {
        return ['ht' => $this->unitHt->multiply($quantity), 'ttc' => $this->unitTtc->multiply($quantity)];
    }

    public function jsonSerialize(): array
    {
        return [
            'unit_ht_cents'  => $this->unitHt->amount,
            'unit_ttc_cents' => $this->unitTtc->amount,
            'currency'       => $this->currency(),
            'tax_code'       => $this->tax->code,
            'tax_bp'         => $this->tax->basisPoints,
            'entered_as'     => $this->enteredAs,
        ];
    }

    /** Inverse de jsonSerialize() (instantané d'une ligne de commande). */
    public static function fromArray(array $a): self
    {
        $currency = (string) ($a['currency'] ?? 'EUR');

        return new self(
            Money::of((int) $a['unit_ht_cents'], $currency),
            Money::of((int) $a['unit_ttc_cents'], $currency),
            new TaxRate((string) $a['tax_code'], (int) $a['tax_bp']),
            ($a['entered_as'] ?? self::TTC) === self::HT ? self::HT : self::TTC
        );
    }
}
