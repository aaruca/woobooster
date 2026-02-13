/**
 * WooBooster Admin JS
 * Vanilla JS — AJAX autocomplete, dynamic form logic, rule tester, toggle.
 */
(function () {
  'use strict';

  var cfg = window.wooboosterAdmin || {};

  /* ── Autocomplete ─────────────────────────────────────────────────── */

  function initAutocomplete(inputId, hiddenId, dropdownId, getTaxonomy) {
    var display  = document.getElementById(inputId);
    var hidden   = document.getElementById(hiddenId);
    var dropdown = document.getElementById(dropdownId);
    if (!display || !hidden || !dropdown) return;

    var debounce = null;
    var currentPage = 1;

    display.addEventListener('input', function () {
      clearTimeout(debounce);
      hidden.value = '';
      currentPage = 1;
      debounce = setTimeout(function () { searchTerms(display.value, 1); }, 300);
    });

    display.addEventListener('focus', function () {
      if (!dropdown.children.length && display.value.length === 0) {
        searchTerms('', 1);
      } else if (dropdown.children.length) {
        dropdown.style.display = 'block';
      }
    });

    document.addEventListener('click', function (e) {
      if (!dropdown.contains(e.target) && e.target !== display) {
        dropdown.style.display = 'none';
      }
    });

    function searchTerms(search, page) {
      var taxonomy = getTaxonomy();
      if (!taxonomy) { dropdown.style.display = 'none'; return; }

      var fd = new FormData();
      fd.append('action', 'woobooster_search_terms');
      fd.append('nonce', cfg.nonce);
      fd.append('taxonomy', taxonomy);
      fd.append('search', search);
      fd.append('page', page);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) return;
          if (page === 1) dropdown.innerHTML = '';

          res.data.terms.forEach(function (t) {
            var item = document.createElement('div');
            item.className = 'wb-autocomplete__item';
            item.textContent = t.name + ' (' + t.count + ')';
            item.dataset.slug = t.slug;
            item.dataset.name = t.name;
            item.addEventListener('click', function () {
              display.value = t.name;
              hidden.value  = t.slug;
              dropdown.style.display = 'none';
            });
            dropdown.appendChild(item);
          });

          if (res.data.has_more) {
            var more = document.createElement('div');
            more.className = 'wb-autocomplete__more';
            more.textContent = cfg.i18n.loading || 'Load more…';
            more.addEventListener('click', function () {
              more.remove();
              searchTerms(search, page + 1);
            });
            dropdown.appendChild(more);
          }

          dropdown.style.display = dropdown.children.length ? 'block' : 'none';
        });
    }
  }

  /* ── Action Source Toggle ──────────────────────────────────────────── */

  function initSourceToggle() {
    var source = document.getElementById('wb-action-source');
    var field  = document.getElementById('wb-action-value-field');
    if (!source || !field) return;

    function toggle() {
      field.style.display = source.value === 'attribute' ? 'none' : '';
    }
    source.addEventListener('change', toggle);
    toggle();
  }

  /* ── Rule Toggle (inline) ─────────────────────────────────────────── */

  function initRuleToggles() {
    document.querySelectorAll('.wb-toggle-rule').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ruleId = btn.dataset.ruleId;
        var fd = new FormData();
        fd.append('action', 'woobooster_toggle_rule');
        fd.append('nonce', cfg.nonce);
        fd.append('rule_id', ruleId);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res.success) { location.reload(); }
          });
      });
    });
  }

  /* ── Delete Confirmation ──────────────────────────────────────────── */

  function initDeleteConfirm() {
    document.querySelectorAll('.wb-delete-rule').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!confirm(cfg.i18n.confirmDelete)) {
          e.preventDefault();
        }
      });
    });
  }

  /* ── Rule Tester ──────────────────────────────────────────────────── */

  function initRuleTester() {
    var input   = document.getElementById('wb-test-product');
    var btn     = document.getElementById('wb-test-btn');
    var results = document.getElementById('wb-test-results');
    if (!input || !btn || !results) return;

    btn.addEventListener('click', function () {
      var val = input.value.trim();
      if (!val) return;
      results.style.display = 'block';
      results.innerHTML = '<p class="wb-text--muted">' + (cfg.i18n.testing || 'Testing…') + '</p>';

      var fd = new FormData();
      fd.append('action', 'woobooster_test_rule');
      fd.append('nonce', cfg.nonce);
      fd.append('product', val);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) {
            results.innerHTML = '<div class="wb-message wb-message--danger"><span>' + (res.data.message || 'Error') + '</span></div>';
            return;
          }
          renderDiagnostics(res.data);
        });
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
    });

    function renderDiagnostics(d) {
      var html = '<div class="wb-test-grid">';

      // Product info.
      html += '<div class="wb-test-section"><h4>Product</h4>';
      html += '<p><strong>#' + d.product_id + '</strong> — ' + esc(d.product_name) + '</p></div>';

      // Matched rule.
      html += '<div class="wb-test-section"><h4>Matched Rule</h4>';
      if (d.matched_rule) {
        var r = d.matched_rule;
        html += '<p><strong>' + esc(r.name) + '</strong> (priority ' + r.priority + ')</p>';
        html += '<p>Condition: <code>' + esc(r.condition_attribute) + ' ' + esc(r.condition_operator) + ' ' + esc(r.condition_value) + '</code></p>';
        html += '<p>Action: ' + esc(r.action_source) + ' → <code>' + esc(r.action_value || '—') + '</code> (order: ' + esc(r.action_orderby) + ', limit: ' + r.action_limit + ')</p>';
      } else {
        html += '<p class="wb-text--muted">No rule matched.</p>';
      }
      html += '</div>';

      // Resulting products.
      html += '<div class="wb-test-section"><h4>Recommended Products (' + d.product_ids.length + ')</h4>';
      if (d.products && d.products.length) {
        html += '<table class="wb-mini-table"><thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Stock</th></tr></thead><tbody>';
        d.products.forEach(function (p) {
          html += '<tr><td>' + p.id + '</td><td>' + esc(p.name) + '</td><td>' + p.price + '</td><td>' + esc(p.stock) + '</td></tr>';
        });
        html += '</tbody></table>';
      } else {
        html += '<p class="wb-text--muted">No products returned.</p>';
      }
      html += '</div>';

      // Timing.
      html += '<div class="wb-test-section"><h4>Performance</h4>';
      html += '<p>Execution time: <strong>' + d.time_ms + 'ms</strong></p></div>';

      // Condition keys.
      html += '<div class="wb-test-section wb-test-section--collapsible"><h4>Condition Keys (' + d.keys.length + ')</h4>';
      html += '<div class="wb-code-block"><code>' + d.keys.join('<br>') + '</code></div></div>';

      html += '</div>'; // .wb-test-grid
      results.innerHTML = html;
    }

    function esc(s) {
      if (!s) return '';
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }
  }

  /* ── Init ──────────────────────────────────────────────────────────── */

  document.addEventListener('DOMContentLoaded', function () {
    // Condition autocomplete.
    initAutocomplete(
      'wb-condition-value-display',
      'wb-condition-value',
      'wb-condition-dropdown',
      function () {
        var el = document.getElementById('wb-condition-attr');
        return el ? el.value : '';
      }
    );

    // Action autocomplete.
    initAutocomplete(
      'wb-action-value-display',
      'wb-action-value',
      'wb-action-dropdown',
      function () {
        var src = document.getElementById('wb-action-source');
        if (!src) return '';
        if (src.value === 'category') return 'product_cat';
        if (src.value === 'tag') return 'product_tag';
        return '';
      }
    );

    initSourceToggle();
    initRuleToggles();
    initDeleteConfirm();
    initRuleTester();
  });
})();
