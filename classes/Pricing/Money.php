<?php

namespace Grav\Plugin\SimpleShop\Pricing;

/**
 * Montant d'argent : entier en unités mineures (centimes) + devise ISO 4217. Immuable.
 *
 * Jamais de `float` pour un montant : les calculs (lignes, totaux, Stripe, factures) se font en entiers.
 */
final class Money implements \JsonSerializable
{
    /** Saisie admise : chiffres, point (ou virgule) décimal, deux décimales au plus. */
    public const INPUT_PATTERN = '^[0-9]+([.,][0-9]{1,2})?$';

    private function __construct(public readonly int $amount, public readonly string $currency)
    {
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, self::checkCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * Montant saisi dans un formulaire (« 12.95 », « 12,95 », « 12 ») → centimes. Deux décimales au plus.
     *
     * @throws \InvalidArgumentException Saisie invalide (négative, plus de 2 décimales, texte).
     */
    public static function parse(string $input, string $currency): self
    {
        $input = trim($input);
        if (!preg_match('/' . self::INPUT_PATTERN . '/', $input)) {
            throw new \InvalidArgumentException("Montant invalide : « {$input} » (exemple attendu : 12.95).");
        }
        $parts = explode('.', str_replace(',', '.', $input));
        $cents = ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '0', 2, '0');

        return self::of($cents, $currency);
    }

    /**
     * Ancienne valeur décimale (ex. `12.2749` HT d'une fiche saisie avant le passage aux centimes) → centimes,
     * arrondi au plus proche (demi supérieur). Réservé à la lecture des anciennes données.
     */
    public static function fromLegacyDecimal(float|int|string $value, string $currency): self
    {
        if (!is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException('Montant invalide : ' . var_export($value, true));
        }
        // Calcul sur la représentation décimale (et non en binaire) : 12.2749 → 1227.49 → 1227.
        $s = number_format((float) $value, 6, '.', '');
        [$int, $dec] = explode('.', $s);
        $cents = (int) $int * 100 + (int) substr($dec, 0, 2);
        if ((int) substr($dec, 2, 1) >= 5) {
            $cents++;
        }

        return self::of($cents, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->amount * $quantity, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /** « 12.95 » (point décimal, deux décimales) : valeur d'un champ de formulaire ou d'un export. */
    public function toDecimalString(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Valeur en unités principales, pour l'affichage seulement (jamais pour calculer). */
    public function toFloat(): float
    {
        return $this->amount / 100;
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new \LogicException("Devises différentes : {$this->currency} et {$other->currency}.");
        }
    }

    private static function checkCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException("Devise invalide : « {$currency} » (code ISO 4217 attendu, ex. EUR).");
        }

        return $currency;
    }
}
