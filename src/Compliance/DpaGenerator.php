<?php

declare(strict_types=1);

namespace Veldra\Compliance;

/**
 * Generates a GDPR-compliant Data Processing Agreement (DPA) as a downloadable PDF.
 *
 * The DPA confirms:
 * - EU data residency (Hetzner/OVH)
 * - No personal data collected (cookie-free, no IP stored)
 * - Two-layer retention model with hard-deletion of raw data
 *
 * This is a plain-text DPA generator (PDF via TCPDF would be a premium enhancement).
 * For MVP, it generates structured markdown that the site owner can save/print.
 */
class DpaGenerator
{
    /**
     * Generate the DPA content.
     *
     * @param string $site_name     The website name.
     * @param string $site_url      The website URL.
     * @param string $legal_entity  The legal entity name (e.g., company name).
     * @param string $contact_email Contact email for data protection inquiries.
     * @return string The DPA text content.
     */
    public function generate(
        string $site_name,
        string $site_url,
        string $legal_entity,
        string $contact_email
    ): string {
        $date = gmdate('F j, Y');

        return <<<DPA
================================================================================
                      DATA PROCESSING AGREEMENT (DPA)
                          Under GDPR Article 28
================================================================================

Date: {$date}

1. PARTIES
----------
Data Controller: {$legal_entity} ({$site_name} — {$site_url})
Contact: {$contact_email}

Data Processor: Veldra Analytics (veldra.dev)
Representative: Veldra GmbH (EU-based)

2. SCOPE AND PURPOSE
--------------------
This DPA governs the processing of pseudonymous website analytics data
collected through the Veldra WordPress plugin ("the Plugin") installed on
{$site_url}. The purpose is solely to provide anonymised, aggregated website
traffic statistics.

3. DATA PROCESSING DETAILS
--------------------------
Categories of data subjects: Website visitors.

Types of data processed:
  • Page URL path and page title
  • HTTP Referrer header (host only)
  • Viewport size and screen resolution
  • Browser family and device type (derived from User-Agent)
  • Country and city (derived from IP via local MMDB lookup — IP never stored)
  • UTM campaign parameters (where present)

NO personal data is collected. Specifically:
  • NO cookies are set or read
  • NO IP addresses are stored (read transiently in memory only, then discarded)
  • NO cross-site tracking identifiers are used
  • NO fingerprinting techniques are employed
  • NO localStorage or sessionStorage is used

4. DATA RESIDENCY
-----------------
All data is stored exclusively on ISO 27001-certified infrastructure within
the European Union:
  • Primary: Hetzner Cloud (Frankfurt, Germany)
  • Fallback: OVH Cloud (Gravelines, France)

No data transfers to third countries (including the US) occur.

5. RETENTION AND DELETION
-------------------------
Two-layer retention model:

  a) Raw data (veldra_pageviews, veldra_events tables):
     Retained for 90 days (Free tier) or 13 months (Premium tier),
     then permanently and irreversibly deleted via hard DELETE operation.

  b) Aggregated summary data (veldra_daily_summary table):
     Contains only anonymised counts with no individual identifiers.
     Retained indefinitely as it falls outside GDPR's scope (Recital 26).

6. TECHNICAL AND ORGANISATIONAL MEASURES
----------------------------------------
  • Encryption: TLS 1.3 in transit; encryption at rest (AES-256)
  • Access control: Role-based access; no third-party access to raw data
  • Anonymisation: Daily-rotating salt prevents cross-day linkability
  • Auditing: WP-Cron job logs aggregation and deletion operations
  • Authentication: Short-lived JWT tokens (15-min expiry, RS256)

7. SUB-PROCESSORS
-----------------
None. Veldra does not engage sub-processors for data processing activities.

8. DATA SUBJECT RIGHTS
----------------------
Since no personal data is collected, the right to erasure, access,
rectification, and data portability are inherently fulfilled.
Any site owner can delete all stored data by:
  a) Running the plugin's data pruning routine from Settings, or
  b) Deactivating and deleting the plugin (which removes all plugin tables).

9. GOVERNING LAW
----------------
This DPA is governed by the laws of the Federal Republic of Germany
and the General Data Protection Regulation (GDPR) (EU) 2016/679.

10. CONTACT
-----------
For data protection inquiries, contact: {$contact_email}

================================================================================
                     END OF DATA PROCESSING AGREEMENT
================================================================================
DPA;
    }

    /**
     * Mark the DPA as generated in options (used by compliance widget).
     */
    public function mark_generated(): void
    {
        update_option('veldra_dpa_generated', true);
    }
}
