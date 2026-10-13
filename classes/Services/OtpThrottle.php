<?php

namespace Grav\Plugin\SimpleShop\Services;

/**
 * Limiteur d'envois de codes OTP, 100 % côté serveur (aucun JavaScript).
 *
 * Le délai de 60 s entre deux envois est géré par {@see CustomerManager}
 * (par session). Cette classe ajoute deux plafonds qui, eux, ne dépendent pas
 * de la session (un robot qui jette son cookie ne les contourne pas) :
 *
 *  - par adresse destinataire : protège la boîte d'un tiers ;
 *  - par adresse IP du demandeur : limite l'abus depuis une même source.
 *
 * Stockage : un petit fichier JSON dans `user://data/simple-shop/`, protégé
 * par `flock()`. Les adresses et IP sont hachées (SHA-256 salé) : le fichier
 * ne contient ni e-mail ni IP en clair. Les horodatages de plus d'une heure
 * sont purgés à chaque accès.
 */
class OtpThrottle
{
    /** Raison de refus : trop d'envois vers cette adresse e-mail. */
    public const REASON_EMAIL = 'email';

    /** Raison de refus : trop d'envois depuis cette adresse IP. */
    public const REASON_IP = 'ip';

    /** Fenêtre glissante des plafonds, en secondes. */
    private const WINDOW = 3600;

    private string $file;
    private string $salt;
    private int $maxPerEmail;
    private int $maxPerIp;

    /**
     * @param string $file        Chemin du fichier de stockage JSON.
     * @param string $salt        Sel du hachage (ex. `system.security.salt`).
     * @param int    $maxPerEmail Envois maximum par adresse e-mail et par heure.
     * @param int    $maxPerIp    Envois maximum par IP et par heure.
     */
    public function __construct(string $file, string $salt, int $maxPerEmail = 5, int $maxPerIp = 20)
    {
        $this->file        = $file;
        $this->salt        = $salt;
        $this->maxPerEmail = max(1, $maxPerEmail);
        $this->maxPerIp    = max(1, $maxPerIp);
    }

    /**
     * Réserve un envoi : vérifie les plafonds et, s'ils ne sont pas atteints,
     * enregistre l'envoi (dans le même verrou, donc sans course entre deux
     * requêtes simultanées).
     *
     * @param string   $email Adresse destinataire.
     * @param string   $ip    IP du demandeur (chaîne vide si inconnue).
     * @param int|null $now   Horodatage courant (tests).
     *
     * @return string|null Null si l'envoi est autorisé, sinon
     *                     {@see self::REASON_EMAIL} ou {@see self::REASON_IP}.
     */
    public function acquire(string $email, string $ip, ?int $now = null): ?string
    {
        $now = $now ?? time();

        $emailKey = 'email:' . $this->hash(strtolower(trim($email)));
        $ipKey    = $ip !== '' ? 'ip:' . $this->hash($ip) : null;

        $handle = $this->open();
        if ($handle === null) {
            // Stockage indisponible : on laisse passer plutôt que de bloquer
            // tous les clients (le délai de 60 s par session reste actif).
            return null;
        }

        try {
            $data = $this->read($handle, $now);

            if (count($data[$emailKey] ?? []) >= $this->maxPerEmail) {
                return self::REASON_EMAIL;
            }
            if ($ipKey !== null && count($data[$ipKey] ?? []) >= $this->maxPerIp) {
                return self::REASON_IP;
            }

            $data[$emailKey][] = $now;
            if ($ipKey !== null) {
                $data[$ipKey][] = $now;
            }

            $this->write($handle, $data);

            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Ouvre le fichier et pose un verrou exclusif.
     *
     * @return resource|null
     */
    private function open()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * Lit le contenu et purge les horodatages hors fenêtre.
     *
     * @param resource $handle
     *
     * @return array<string, int[]>
     */
    private function read($handle, int $now): array
    {
        rewind($handle);
        $raw  = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $limit = $now - self::WINDOW;
        foreach ($data as $key => $stamps) {
            $stamps = is_array($stamps)
                ? array_values(array_filter($stamps, static fn ($t) => is_int($t) && $t > $limit))
                : [];
            if ($stamps === []) {
                unset($data[$key]);
            } else {
                $data[$key] = $stamps;
            }
        }

        return $data;
    }

    /**
     * Réécrit le fichier.
     *
     * @param resource $handle
     * @param array<string, int[]> $data
     */
    private function write($handle, array $data): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($data));
        fflush($handle);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $this->salt . '|' . $value);
    }
}
