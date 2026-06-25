/**
 * Veldra Tracker — Privacy-first analytics script.
 *
 * Injects a single pageview event on load. Sends a heartbeat on page exit
 * to capture session duration. No cookies, no localStorage, no fingerprinting.
 * Uses navigator.sendBeacon with fetch fallback.
 *
 * @see /wp-json/veldra/v1/track
 * @license GPL-2.0-or-later
 */
(function () {
  'use strict';

  // ── Configuration ──────────────────────────────────────────────────
  var config = window.VELDRA_CONFIG || {};
  var endpoint = config.endpoint || '/wp-json/veldra/v1/track';

  // ── Page-load timestamp ────────────────────────────────────────────
  var pageLoadTs = Date.now();
  var heartbeatSent = false;

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
      ts: pageLoadTs,
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

  /** Send a heartbeat with session duration on page exit */
  function sendHeartbeat() {
    if (heartbeatSent) return;
    heartbeatSent = true;

    var duration_ms = Date.now() - pageLoadTs;
    // Skip sub-second visits (tab spamming, redirects)
    if (duration_ms < 1000) return;

    var payload = buildPayload();
    payload.duration_ms = duration_ms;
    send(payload);
  }

  // ── Exit detection ────────────────────────────────────────────────
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      sendHeartbeat();
    }
  });

  window.addEventListener('beforeunload', function () {
    sendHeartbeat();
  });

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
