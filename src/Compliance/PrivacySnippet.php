<?php

declare(strict_types=1);

namespace Veldra\Compliance;

/**
 * Generates a plain-language privacy policy snippet for GDPR Article 13.
 *
 * The generated text explains the cookie-free, anonymous tracking approach
 * in terms a site visitor can understand. It is auto-generated and should
 * be reviewed by the site owner's legal counsel before publication.
 */
class PrivacySnippet
{
    /**
     * Generate the privacy policy snippet.
     *
     * @param string $site_name The website name.
     * @return string HTML snippet ready for copy-paste.
     */
    public function generate(string $site_name): string
    {
        $date = gmdate('F j, Y');

        return <<<HTML
<h3>Website Analytics ({$date})</h3>

<p>{$site_name} uses Veldra Analytics, a privacy-first website analytics
tool, to understand how visitors interact with our website. We are committed
to protecting your privacy.</p>

<h4>What We Collect</h4>
<ul>
  <li>The pages you visit and how you arrived at our site</li>
  <li>Your browser type and device category (desktop, mobile, or tablet)</li>
  <li>Your approximate country and city (derived from your IP address)</li>
  <li>Your screen size and viewport dimensions</li>
</ul>

<h4>What We Do NOT Collect</h4>
<ul>
  <li><strong>No cookies</strong> — we do not set, read, or store any cookies</li>
  <li><strong>No IP addresses</strong> — your IP is read transiently in memory
      for geolocation and immediately discarded; it is never written to disk</li>
  <li><strong>No personal identifiers</strong> — we do not collect your name,
      email address, or any personally identifiable information</li>
  <li><strong>No cross-site tracking</strong> — each visit is tracked in
      isolation; we cannot identify you across different websites or across
      different days</li>
</ul>

<h4>Data Storage</h4>
<p>All analytics data is stored on servers located within the European Union
(Germany/France). Raw visit data is automatically deleted after 90 days.
Aggregated, anonymised statistics are retained indefinitely but contain no
information that could identify you.</p>

<h4>Your Rights</h4>
<p>Under the General Data Protection Regulation (GDPR), you have the right to
access, rectify, or erase your personal data. Since we collect no personal
data, these rights are inherently fulfilled. If you have questions, please
contact us.</p>
HTML;
    }
}
