<?php

namespace Grav\Plugin\SimpleShop\Pricing;

/**
 * Taxe applicable à un montant : code (clé du taux, ex. `taux-reduit`, ou ancien code `reduit` / `TVA_5_5`) et taux effectif en points
 * de base (550 = 5,5 %), composée d'une ou plusieurs taxes élémentaires (TaxComponent : ex. taxe fédérale + taxe de
 * province, priorités et taxes composées comme dans WooCommerce). Une seule composante dans le cas courant. Immuable.
 */
final class TaxRate implements \JsonSerializable
{
    /** @var TaxComponent[] */
    public readonly array $components;

    /** @param TaxComponent[] $components Vide : une composante unique de ce taux (catégorie S, ou Z si taux nul). */
    public function __construct(public readonly string $code, public readonly int $basisPoints, array $components = [])
    {
        if ($code === '' || $basisPoints < 0 || $basisPoints >= 10000) {
            throw new \InvalidArgumentException("Taux de taxe invalide : {$code} ({$basisPoints} points de base).");
        }
        $this->components = $components !== [] ? array_values($components)
            : [new TaxComponent($code, $basisPoints, false, true, $basisPoints > 0 ? 'S' : 'Z')];
    }

    /** Taxe faite de plusieurs composantes : taux effectif = somme des simples + composées sur le montant déjà taxé. */
    public static function fromComponents(string $code, array $components): self
    {
        $simple = 0;
        $factor = 1.0;
        foreach ($components as $c) {
            if ($c->compound) {
                $factor *= 1 + $c->basisPoints / 10000;
            } else {
                $simple += $c->basisPoints;
            }
        }
        $effective = (int) round(((1 + $simple / 10000) * $factor - 1) * 10000);

        return new self($code, min(9999, $effective), $components);
    }

    /** Une seule composante, non composée (le cas de la TVA) : exprimable telle quelle sur une facture EN 16931. */
    public function isSimple(): bool
    {
        return count($this->components) === 1 && !$this->components[0]->compound;
    }

    /** Catégorie de facture (S, Z, E…) de la première composante. */
    public function category(): string
    {
        return $this->components[0]->category;
    }

    /** Mentions des composantes (exonérations), sans doublon. */
    public function mentions(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($c) => $c->mention, $this->components))));
    }

    /** Même taxe limitée aux composantes qui s'appliquent aux frais de livraison (null : la livraison n'est pas taxée). */
    public function forShipping(): ?self
    {
        $kept = array_values(array_filter($this->components, static fn ($c) => $c->shipping));
        if ($kept === []) {
            return null;
        }

        return count($kept) === count($this->components) ? $this : self::fromComponents($this->code, $kept);
    }

    /**
     * Montant de chaque composante pour une base HT (arrondi au centime par composante).
     *
     * @return list<array{component: TaxComponent, amount: int}>
     */
    public function breakdown(Money $ht): array
    {
        $out = [];
        $simpleTotal = 0;
        foreach ($this->components as $c) {
            if (!$c->compound) {
                $amount = self::divRound($ht->amount * $c->basisPoints, 10000);
                $simpleTotal += $amount;
                $out[] = ['component' => $c, 'amount' => $amount];
            }
        }
        $running = $ht->amount + $simpleTotal;
        foreach ($this->components as $c) {
            if ($c->compound) {
                $amount = self::divRound($running * $c->basisPoints, 10000);
                $running += $amount;
                $out[] = ['component' => $c, 'amount' => $amount];
            }
        }

        return $out;
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
        if ($this->isSimple()) {
            return Money::of(self::divRound($ht->amount * (10000 + $this->basisPoints), 10000), $ht->currency);
        }

        return Money::of($ht->amount + array_sum(array_column($this->breakdown($ht), 'amount')), $ht->currency);
    }

    /** Montant HT d'un montant TTC : arrondi au centime, demi supérieur. */
    public function removeFrom(Money $ttc): Money
    {
        $guess = self::divRound($ttc->amount * 10000, 10000 + $this->basisPoints);
        if ($this->isSimple()) {
            return Money::of($guess, $ttc->currency);
        }
        // Plusieurs composantes arrondies chacune : HT dont le TTC recalculé est le plus proche (égal si possible).
        $best = $guess;
        $bestGap = PHP_INT_MAX;
        for ($h = max(0, $guess - 3); $h <= $guess + 3; $h++) {
            $gap = abs($this->addTo(Money::of($h, $ttc->currency))->amount - $ttc->amount);
            if ($gap < $bestGap) {
                [$best, $bestGap] = [$h, $gap];
            }
        }

        return Money::of($best, $ttc->currency);
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
        return ['code' => $this->code, 'basis_points' => $this->basisPoints, 'components' => $this->components];
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
