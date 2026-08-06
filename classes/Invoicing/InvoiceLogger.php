<?php

namespace Grav\Plugin\SimpleShop\Invoicing;

use Grav\Common\Grav;

/**
 * Journal dédié à la facturation (Factur-X / e-reporting), volontairement
 * SÉPARÉ du log général de Grav (`logs/grav.log`), afin de faciliter le
 * suivi/l'audit des anomalies de facturation (notamment les erreurs de
 * validation XSD, voir {@see \Grav\Plugin\SimpleShop\Invoicing\Invoice::build()})
 * sans devoir les rechercher au milieu du reste des logs applicatifs.
 *
 * Emplacement du fichier : `logs/simple-shop/invoices.log` (chemin calculé
 * via le stream `log://` de Grav, avec repli sur la constante `GRAV_ROOT` si
 * ce stream n'est pas disponible pour une raison quelconque). Le répertoire
 * `simple-shop/` est créé automatiquement si besoin.
 *
 * Format de chaque ligne : `[YYYY-MM-DD HH:MM:SS] NIVEAU: message`.
 *
 * Ne lève JAMAIS d'exception : toute erreur d'écriture est absorbée et
 * journalisée via `error_log()` en filet de sécurité, afin de ne jamais
 * faire échouer une génération de facture pour un simple problème de log.
 *
 * @package Grav\Plugin\SimpleShop\Invoicing
 */
class InvoiceLogger
{
    /**
     * Chemin relatif du fichier de log, sous le répertoire `logs/` de Grav.
     *
     * @var string
     */
    private const LOG_RELATIVE_PATH = 'simple-shop/invoices.log';

    /**
     * Journalise un message de niveau INFO.
     *
     * @param string $message Message à journaliser.
     *
     * @return void
     */
    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    /**
     * Journalise un message de niveau WARNING (ex. non-conformité XSD
     * détectée mais génération non bloquée, voir doc de {@see Invoice}).
     *
     * @param string $message Message à journaliser.
     *
     * @return void
     */
    public static function warning(string $message): void
    {
        self::write('WARNING', $message);
    }

    /**
     * Journalise un message de niveau ERROR (ex. échec complet de la
     * génération du PDF Factur-X).
     *
     * @param string $message Message à journaliser.
     *
     * @return void
     */
    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    /**
     * Calcule le chemin absolu du répertoire de logs Grav
     * (`logs/`), avec repli sur `GRAV_ROOT . '/logs'` si le stream `log://`
     * n'est pas résolvable.
     *
     * @return string Le chemin absolu du répertoire `logs/` de Grav.
     */
    private static function logsBaseDir(): string
    {
        try {
            $grav = Grav::instance();
            $resolved = $grav['locator']->findResource('log://', true, true);
            if ($resolved) {
                return rtrim($resolved, '/');
            }
        } catch (\Throwable $e) {
            // Ignoré : repli ci-dessous.
        }

        if (defined('GRAV_ROOT')) {
            return rtrim(GRAV_ROOT, '/') . '/logs';
        }

        return rtrim(sys_get_temp_dir(), '/');
    }

    /**
     * Écrit effectivement une ligne dans `logs/simple-shop/invoices.log`.
     *
     * @param string $level   Niveau ('INFO', 'WARNING', 'ERROR').
     * @param string $message Message à journaliser.
     *
     * @return void
     */
    private static function write(string $level, string $message): void
    {
        $line = sprintf('[%s] %s: %s' . PHP_EOL, date('Y-m-d H:i:s'), $level, $message);

        try {
            $filePath = self::logsBaseDir() . '/' . self::LOG_RELATIVE_PATH;
            $dir = dirname($filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Filet de sécurité supplémentaire ci-dessous.
        }

        // Filet de sécurité systématique, indépendant du système de fichiers Grav.
        error_log('[SimpleShop][Invoicing] ' . $level . ': ' . $message);
    }
}
