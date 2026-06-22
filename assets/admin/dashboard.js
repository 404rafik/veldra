/**
 * Veldra Dashboard — Chart.js analytics UI for WP-Admin.
 *
 * Fetches data from the internal REST API and renders charts.
 * No external API calls — all data comes from the local WordPress instance.
 *
 * @see /wp-json/veldra/v1/data
 */
(function () {
  'use strict';

  var config = window.VELDRA_ADMIN || {};
  if (!config.dataUrl || !config.nonce) return;

  /** Format a number with locale separators */
  function fmt(n) {
    return Number(n).toLocaleString();
  }

  /** Fetch dashboard data for a date range */
  function fetchData(start, end) {
    var url = config.dataUrl + '?start=' + start + '&end=' + end + '&_wpnonce=' + config.nonce;
    return fetch(url).then(function (r) { return r.json(); });
  }

  /** Render a traffic line chart */
  function renderTrafficChart(data) {
    var ctx = document.getElementById('veldra-traffic-chart');
    if (!ctx || !data || !data.length) return;

    var labels = data.map(function (d) { return d.date; });
    var visits = data.map(function (d) { return Number(d.visits); });
    var pageviews = data.map(function (d) { return Number(d.pageviews); });

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Visitors',
          data: visits,
          borderColor: '#22c55e',
          backgroundColor: 'rgba(34, 197, 94, 0.1)',
          fill: true,
          tension: 0.3,
          pointRadius: 2,
        }, {
          label: 'Pageviews',
          data: pageviews,
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          fill: true,
          tension: 0.3,
          pointRadius: 2,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: '#f0f0f0' } }
        }
      }
    });
  }

  /** Render a device doughnut chart */
  function renderDeviceChart(data) {
    var ctx = document.getElementById('veldra-devices-chart');
    if (!ctx || !data || !data.length) return;

    var labels = data.map(function (d) { return d.device_type || 'Unknown'; });
    var values = data.map(function (d) { return Number(d.visits); });
    var colors = { desktop: '#3b82f6', mobile: '#22c55e', tablet: '#f59e0b' };
    var bg = labels.map(function (l) { return colors[l.toLowerCase()] || '#94a3b8'; });

    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{ data: values, backgroundColor: bg }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right' } }
      }
    });
  }

  /** Render a sortable table */
  function renderTable(containerId, data, columns) {
    var container = document.getElementById(containerId);
    if (!container || !data || !data.length) return;

    var html = '<table class="wp-list-table widefat striped"><thead><tr>';
    columns.forEach(function (c) { html += '<th>' + c.label + '</th>'; });
    html += '</tr></thead><tbody>';

    data.forEach(function (row) {
      html += '<tr>';
      columns.forEach(function (c) {
        var val = row[c.key];
        if (c.format === 'number') val = fmt(val);
        if (c.key === 'path') {
          val = '<code>' + (val.length > 60 ? val.slice(0, 57) + '...' : val) + '</code>';
        }
        html += '<td>' + (val || '—') + '</td>';
      });
      html += '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  }

  /** Update the overview cards */
  function updateCards(overview) {
    if (!overview) return;
    var setText = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    setText('veldra-visitors', fmt(overview.total_visits));
    setText('veldra-pageviews', fmt(overview.total_pageviews));
    setText('veldra-bounce', '—');
    setText('veldra-duration', '—');
  }

  /** Main dashboard update */
  function updateDashboard(start, end) {
    fetchData(start, end).then(function (data) {
      if (!data || data.error) return;

      // Destroy existing charts if any
      Chart.helpers.each(Chart.instances, function (instance) {
        instance.destroy();
      });

      updateCards(data.overview);
      renderTrafficChart(data.traffic);
      renderDeviceChart(data.devices);
      renderTable('veldra-top-content', data.content, [
        { key: 'path', label: 'Page' },
        { key: 'visits', label: 'Visitors', format: 'number' },
        { key: 'pageviews', label: 'Pageviews', format: 'number' },
      ]);
      renderTable('veldra-referrers', data.referrers, [
        { key: 'referrer_host', label: 'Source' },
        { key: 'visits', label: 'Visitors', format: 'number' },
        { key: 'pageviews', label: 'Pageviews', format: 'number' },
      ]);
      renderTable('veldra-countries', data.countries, [
        { key: 'country_code', label: 'Country' },
        { key: 'visits', label: 'Visitors', format: 'number' },
        { key: 'pageviews', label: 'Pageviews', format: 'number' },
      ]);
    });
  }

  // ── Init ──────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    var rangeSelect = document.getElementById('veldra-range');
    var customRange = document.getElementById('veldra-custom-range');
    var startInput = document.getElementById('veldra-start');
    var endInput = document.getElementById('veldra-end');
    var applyBtn = document.getElementById('veldra-apply');

    if (!rangeSelect) return;

    /** Get date range from selector */
    function getRange() {
      var val = rangeSelect.value;
      if (val === 'custom') {
        return { start: startInput.value, end: endInput.value };
      }
      var days = parseInt(val, 10);
      var end = new Date();
      var start = new Date();
      start.setDate(start.getDate() - days);
      return {
        start: start.toISOString().slice(0, 10),
        end: end.toISOString().slice(0, 10),
      };
    }

    /** Load data for the current range */
    function load() {
      var range = getRange();
      if (range.start && range.end) {
        updateDashboard(range.start, range.end);
      }
    }

    // Range selector change
    rangeSelect.addEventListener('change', function () {
      if (rangeSelect.value === 'custom') {
        customRange.style.display = 'inline-block';
        return;
      }
      customRange.style.display = 'none';
      load();
    });

    // Custom range apply
    if (applyBtn) {
      applyBtn.addEventListener('click', load);
    }

    // Set default date inputs
    if (startInput && endInput) {
      var now = new Date();
      var monthAgo = new Date();
      monthAgo.setDate(monthAgo.getDate() - 30);
      startInput.value = monthAgo.toISOString().slice(0, 10);
      endInput.value = now.toISOString().slice(0, 10);
    }

    // Initial load
    load();
  });
})();
