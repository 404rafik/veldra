<?php

declare(strict_types=1);

namespace Veldra\Tracker;

use GeoIp2\Database\Reader;
use GeoIp2\Model\City;

/**
 * Resolves country and city from an IP address using a self-hosted MaxMind GeoLite2 database.
 *
 * The raw IP is never stored — it is used transiently in memory solely for
 * geolocation lookup, then discarded. The MMDB file must be updated weekly
 * via WP-Cron or manual trigger.
 */
class GeoResolver
{
    private const MMDB_OPTION_KEY = 'veldra_mmdb_path';
    private const DEFAULT_MMDB_PATH = __DIR__ . '/../../assets/GeoLite2-City.mmdb';

    private ?Reader $reader = null;

    /**
     * Resolve an IP address to country code and city name.
     *
     * @param string $ip The visitor's IP address.
     * @return array{country_code: string, city: string} Empty strings on failure.
     */
    public function resolve(string $ip): array
    {
        try {
            $reader = $this->get_reader();

            if ($reader === null) {
                return ['country_code' => '', 'city' => ''];
            }

            /** @var City $record */
            $record = $reader->city($ip);

            $country_code = $record->country->isoCode ?? '';
            $city         = $record->city->name ?? '';

            return [
                'country_code' => is_string($country_code) ? $country_code : '',
                'city'         => is_string($city) ? $city : '',
            ];
        } catch (\Exception $e) {
            return ['country_code' => '', 'city' => ''];
        }
    }

    /**
     * Get or initialise the MMDB reader.
     */
    private function get_reader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        $mmdb_path = get_option(self::MMDB_OPTION_KEY, self::DEFAULT_MMDB_PATH);

        if (!is_string($mmdb_path) || !file_exists($mmdb_path)) {
            return null;
        }

        try {
            $this->reader = new Reader($mmdb_path);
            return $this->reader;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if a MaxMind database is available for lookups.
     */
    public function is_available(): bool
    {
        return $this->get_reader() !== null;
    }

    /**
     * Update the MMDB path in options (used by admin settings).
     */
    public static function set_mmdb_path(string $path): void
    {
        update_option(self::MMDB_OPTION_KEY, $path);
    }
}
