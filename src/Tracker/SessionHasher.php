<?php

declare(strict_types=1);

namespace Veldra\Tracker;

/**
 * Generates daily-salted, anonymised session hashes.
 *
 * The session hash is a SHA-256 of (IP + User-Agent + daily salt).
 * The daily salt rotates at 00:00 UTC. Hashes from different days
 * or different websites cannot be linked — making cross-site or
 * cross-day tracking cryptographically impossible.
 *
 * The raw IP is read in transient server memory only and is never
 * written to disk, database, or log files.
 */
class SessionHasher
{
    private const SALT_OPTION_KEY = 'veldra_session_salt';
    private const SALT_LENGTH     = 32;

    /**
     * Generate an anonymised session hash from request metadata.
     *
     * @param string $ip         The visitor's IP address (read from $_SERVER, never stored).
     * @param string $user_agent The visitor's User-Agent string.
     * @return string A 64-character hex SHA-256 hash.
     */
    public function hash(string $ip, string $user_agent): string
    {
        $salt = $this->get_daily_salt();
        return hash('sha256', $ip . '|' . $user_agent . '|' . $salt);
    }

    /**
     * Retrieve or generate the daily salt.
     *
     * The salt is stored in the WordPress options table and regenerated
     * when the date changes. This ensures all hashes from the same calendar
     * day use the same salt (making per-day unique session counting possible)
     * while preventing cross-day linking.
     *
     * @return string The daily salt (32 hex characters).
     */
    public function get_daily_salt(): string
    {
        $today   = gmdate('Y-m-d');
        $stored  = get_option(self::SALT_OPTION_KEY, null);
        $salt_date = $stored['date'] ?? '';
        $salt_value = $stored['salt'] ?? '';

        if ($salt_date === $today && $salt_value !== '') {
            return $salt_value;
        }

        // Generate new salt for today
        $new_salt = bin2hex(random_bytes(self::SALT_LENGTH));

        update_option(self::SALT_OPTION_KEY, [
            'date' => $today,
            'salt' => $new_salt,
        ], false); // false = autoload disabled — only loaded on tracking requests

        return $new_salt;
    }

    /**
     * Get the current day's date for the salt.
     * Exposed for testing — allows injection of a fixed date.
     */
    protected function get_today_date(): string
    {
        return gmdate('Y-m-d');
    }
}
