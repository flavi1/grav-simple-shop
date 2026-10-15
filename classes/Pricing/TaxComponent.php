<?php

namespace Grav\Plugin\SimpleShop\Pricing;

/**
 * Une taxe élémentaire (une ligne de la table des taux) : nom affiché, taux en points de base, taxe composée (calculée sur
 * le prix déjà taxé), application aux frais de livraison, catégorie de facture (S normal ou réduit, Z zéro, E exonéré,
 * O hors champ) et mention (obligatoire pour une exonération). Immuable.
 */
final class TaxComponent implements \JsonSerializable
{
    public const CATEGORIES = ['S', 'Z', 'E', 'O', 'AE', 'K', 'G', 'L', 'M'];

    public function __construct(
        public readonly string $name,
        public readonly int $basisPoints,
        public readonly bool $compound = false,
        public readonly bool $shipping = true,
        public readonly string $category = 'S',
        public readonly string $mention = ''
    ) {
        if ($basisPoints < 0 || $basisPoints >= 10000) {
            throw new \InvalidArgumentException("Taux invalide : {$name} ({$basisPoints} points de base).");
        }
    }

    public static function fromArray(array $a): self
    {
        return new self((string) ($a['name'] ?? ''), (int) ($a['bp'] ?? 0), !empty($a['compound']), !array_key_exists('shipping', $a) || !empty($a['shipping']),
            (string) ($a['category'] ?? 'S'), (string) ($a['mention'] ?? ''));
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'bp' => $this->basisPoints, 'compound' => $this->compound, 'shipping' => $this->shipping,
            'category' => $this->category, 'mention' => $this->mention];
    }
}
