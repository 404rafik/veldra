/**
 * Veldra Tracker — Privacy-first analytics script.
 *
 * Injects a single pageview event on load. No cookies, no localStorage,
 * no fingerprinting. Uses navigator.sendBeacon with fetch fallback.
 *
 * @see /wp-json/veldra/v1/track
 * @license GPL-2.0-or-later
 */
(function () {
  'use strict';

  // ── Configuration ──────────────────────────────────────────────────
  var config = window.VELDRA_CONFIG || {};
  var endpoint = config.endpoint || '/wp-json/veldra/v1/track';

  // ── Utilities ──────────────────────────────────────────────────────

  /** Get a URL parameter by name */
  function getParam(name) {
    var match = location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
    return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
  }

  /** Get the origin host from a referrer */
  function getReferrerHost(url) {
    try {
      var parsed = new URL(url);
      return parsed.host;
    } catch (_) {
      return '';
    }
  }

  /** Build the tracking payload */
  function buildPayload() {
    var w = window;
    var d = document;
    var scr = d.documentElement || d.body;

    return {
      path: location.pathname + location.search,
      title: d.title,
      referrer: d.referrer || '',
      screen: w.screen ? w.screen.width + 'x' + w.screen.height : '',
      viewport: w.innerWidth + 'x' + w.innerHeight,
      tz: Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '',
      utm_source: getParam('utm_source'),
      utm_medium: getParam('utm_medium'),
      utm_campaign: getParam('utm_campaign'),
    };
  }

  /** Send payload via sendBeacon or fetch */
  function send(payload) {
    var body = JSON.stringify(payload);
    var headers = { type: 'application/json' };

    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, new Blob([body], headers));
    } else {
      try {
        fetch(endpoint, {
          method: 'POST',
          body: body,
          headers: headers,
          keepalive: true,
          credentials: 'omit',
        });
      } catch (_) {
        // Fail silently — no console errors
      }
    }
  }

  // ── Init ───────────────────────────────────────────────────────────
  // Fire on DOMContentLoaded to avoid blocking rendering
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      send(buildPayload());
    });
  } else {
    send(buildPayload());
  }
})();
