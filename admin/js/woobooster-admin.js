/**
 * WooBooster Admin JS
 * Vanilla JS — AJAX autocomplete, dynamic form logic, rule tester, toggle.
 */
(function () {
  'use strict';

  var cfg = window.wooboosterAdmin || {};

  /* ── Autocomplete ─────────────────────────────────────────────────── */

  function initAutocomplete(inputId, hiddenId, dropdownId, getTaxonomy) {
    var display = document.getElementById(inputId);
    var hidden = document.getElementById(hiddenId);
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
              hidden.value = t.slug;
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
    var field = document.getElementById('wb-action-value-field');
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
    var input = document.getElementById('wb-test-product');
    var btn = document.getElementById('wb-test-btn');
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
    // Condition repeater.
    initConditionRepeater();

    // Action repeater.
    initActionRepeater();

    initRuleToggles();
    initDeleteConfirm();
    initRuleTester();
    initCheckUpdate();
    initImportExport();
  });

  /* ── Action Repeater ─────────────────────────────────────────────── */

  function initActionRepeater() {
    var container = document.getElementById('wb-action-repeater');
    var addBtn = document.getElementById('wb-add-action');
    if (!container) return;

    // Init existing rows.
    container.querySelectorAll('.wb-action-row').forEach(function (row) {
      bindActionRow(row);
    });

    // Add Action.
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        var newIdx = container.querySelectorAll('.wb-action-row').length;
        var row = createActionRow(newIdx);
        container.appendChild(row);
        bindActionRow(row);
        renumberActionFields();
      });
    }

    // Remove Action.
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-action')) {
        var row = e.target.closest('.wb-action-row');
        if (container.querySelectorAll('.wb-action-row').length > 1) {
          row.remove();
          renumberActionFields();
        } else {
          alert('At least one action is required.');
        }
      }
    });

    function bindActionRow(row) {
      initActionRowToggle(row);
      initActionRowAutocomplete(row);
    }

    function createActionRow(idx) {
      var row = document.createElement('div');
      row.className = 'wb-action-row';
      row.dataset.index = idx;
      var prefix = 'actions[' + idx + ']';

      row.innerHTML =
        '<div class="wb-action-row__header">' +
        '<span class="wb-action-row__title">Action <span class="wb-action-number">' + (idx + 1) + '</span></span>' +
        '<button type="button" class="wb-btn wb-btn--danger wb-btn--xs wb-remove-action" title="Remove Action">&times;</button>' +
        '</div>' +
        '<div class="wb-action-row__body">' +

        // Source Type
        '<div class="wb-field">' +
        '<label class="wb-field__label">Source Type</label>' +
        '<div class="wb-field__control">' +
        '<select name="' + prefix + '[action_source]" class="wb-select wb-action-source">' +
        '<option value="category">Category</option>' +
        '<option value="tag">Tag</option>' +
        '<option value="attribute">Same Attribute</option>' +
        '</select>' +
        '<p class="wb-field__desc wb-attribute-desc" style="display:none;">"Same Attribute" uses the condition\'s attribute and value.</p>' +
        '</div></div>' +

        // Source Value
        '<div class="wb-field wb-action-value-field">' +
        '<label class="wb-field__label">Source Value</label>' +
        '<div class="wb-field__control">' +
        '<div class="wb-autocomplete">' +
        '<input type="text" class="wb-input wb-autocomplete__input wb-action-value-display" placeholder="Search terms…" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[action_value]" class="wb-action-value-hidden">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>' +
        '</div></div>' +

        // Order By & Limit Row
        '<div class="wb-field-row">' +

        // Order By
        '<div class="wb-field">' +
        '<label class="wb-field__label">Order By</label>' +
        '<div class="wb-field__control">' +
        '<select name="' + prefix + '[action_orderby]" class="wb-select">' +
        '<option value="rand">Random</option>' +
        '<option value="date">Newest</option>' +
        '<option value="price">Price (Low to High)</option>' +
        '<option value="price_desc">Price (High to Low)</option>' +
        '<option value="bestselling">Bestselling</option>' +
        '<option value="rating">Rating</option>' +
        '</select>' +
        '</div></div>' +

        // Limit
        '<div class="wb-field">' +
        '<label class="wb-field__label">Limit</label>' +
        '<div class="wb-field__control">' +
        '<input type="number" name="' + prefix + '[action_limit]" value="4" min="1" class="wb-input wb-input--sm">' +
        '</div></div>' +

        '</div>' + // .wb-field-row

        '</div>'; // .wb-action-row__body

      return row;
    }

    function renumberActionFields() {
      container.querySelectorAll('.wb-action-row').forEach(function (row, i) {
        row.dataset.index = i;
        var num = row.querySelector('.wb-action-number');
        if (num) num.textContent = i + 1;

        var prefix = 'actions[' + i + ']';
        row.querySelectorAll('[name]').forEach(function (el) {
          var name = el.getAttribute('name');
          if (name) {
            el.setAttribute('name', name.replace(/actions\[\d+\]/, prefix));
          }
        });
      });
    }

    function initActionRowToggle(row) {
      var source = row.querySelector('.wb-action-source');
      var valField = row.querySelector('.wb-action-value-field');
      var attrDesc = row.querySelector('.wb-attribute-desc');

      function toggle() {
        if (source.value === 'attribute') {
          valField.style.display = 'none';
          attrDesc.style.display = 'block';
        } else {
          valField.style.display = 'block';
          attrDesc.style.display = 'none';
        }
      }

      if (source) {
        source.addEventListener('change', toggle);
        toggle();
      }
    }

    function initActionRowAutocomplete(row) {
      var display = row.querySelector('.wb-action-value-display');
      var hidden = row.querySelector('.wb-action-value-hidden');
      var dropdown = row.querySelector('.wb-autocomplete__dropdown');
      var sourceSelect = row.querySelector('.wb-action-source');

      if (!display || !hidden || !dropdown || !sourceSelect) return;

      var debounce = null;

      function getTaxonomy() {
        if (sourceSelect.value === 'category') return 'product_cat';
        if (sourceSelect.value === 'tag') return 'product_tag';
        return '';
      }

      function searchTerms(search) {
        var taxonomy = getTaxonomy();
        if (!taxonomy) { dropdown.style.display = 'none'; return; }

        var fd = new FormData();
        fd.append('action', 'woobooster_search_terms');
        fd.append('nonce', cfg.nonce);
        fd.append('taxonomy', taxonomy);
        fd.append('search', search);
        fd.append('page', 1);

        fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.success) return;
            dropdown.innerHTML = '';
            res.data.terms.forEach(function (t) {
              var item = document.createElement('div');
              item.className = 'wb-autocomplete__item';
              item.textContent = t.name + ' (' + t.count + ')';
              item.addEventListener('click', function () {
                display.value = t.name;
                hidden.value = t.slug;
                dropdown.style.display = 'none';
              });
              dropdown.appendChild(item);
            });
            dropdown.style.display = dropdown.children.length ? 'block' : 'none';
          });
      }

      display.addEventListener('input', function () {
        clearTimeout(debounce);
        hidden.value = '';
        debounce = setTimeout(function () { searchTerms(display.value); }, 300);
      });

      display.addEventListener('focus', function () {
        if (!dropdown.children.length && display.value.length === 0) {
          searchTerms('');
        } else if (dropdown.children.length) {
          dropdown.style.display = 'block';
        }
      });

      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== display) {
          dropdown.style.display = 'none';
        }
      });
    }
  }

  /* ── Condition Repeater ──────────────────────────────────────────── */

  function initConditionRepeater() {
    var container = document.getElementById('wb-condition-groups');
    var addGroupBtn = document.getElementById('wb-add-group');
    if (!container) return;

    // Hierarchical taxonomies.
    var hierarchical = ['product_cat'];

    // Init existing rows.
    container.querySelectorAll('.wb-condition-row').forEach(function (row) {
      initRowAutocomplete(row);
      initRowChildToggle(row);
    });

    // Wire up existing remove buttons.
    container.addEventListener('click', function (e) {
      if (e.target.classList.contains('wb-remove-condition')) {
        e.target.closest('.wb-condition-row').remove();
        renumberFields();
      }
      if (e.target.classList.contains('wb-remove-group')) {
        var group = e.target.closest('.wb-condition-group');
        var divider = group.previousElementSibling;
        if (divider && divider.classList.contains('wb-or-divider')) divider.remove();
        group.remove();
        renumberFields();
      }
      if (e.target.classList.contains('wb-add-condition')) {
        addConditionToGroup(e.target.closest('.wb-condition-group'));
      }
    });

    // Add OR Group.
    if (addGroupBtn) {
      addGroupBtn.addEventListener('click', function () {
        var groups = container.querySelectorAll('.wb-condition-group');
        var newIdx = groups.length;

        var divider = document.createElement('div');
        divider.className = 'wb-or-divider';
        divider.textContent = '— OR —';
        container.appendChild(divider);

        var group = createGroupEl(newIdx);
        container.appendChild(group);
      });
    }

    function createGroupEl(groupIdx) {
      var group = document.createElement('div');
      group.className = 'wb-condition-group';
      group.dataset.group = groupIdx;

      group.innerHTML = '<div class="wb-condition-group__header">' +
        '<span class="wb-condition-group__label">Condition Group ' + (groupIdx + 1) + '</span>' +
        '<button type="button" class="wb-btn wb-btn--danger wb-btn--xs wb-remove-group">&times;</button>' +
        '</div>';

      var row = createConditionRow(groupIdx, 0);
      group.appendChild(row);

      var addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'wb-btn wb-btn--subtle wb-btn--sm wb-add-condition';
      addBtn.textContent = '+ AND Condition';
      group.appendChild(addBtn);

      return group;
    }

    function addConditionToGroup(group) {
      var rows = group.querySelectorAll('.wb-condition-row');
      var gIdx = parseInt(group.dataset.group, 10);
      var cIdx = rows.length;

      var row = createConditionRow(gIdx, cIdx);
      var addBtn = group.querySelector('.wb-add-condition');
      group.insertBefore(row, addBtn);
    }

    function createConditionRow(gIdx, cIdx) {
      var prefix = 'conditions[' + gIdx + '][' + cIdx + ']';
      var row = document.createElement('div');
      row.className = 'wb-condition-row';
      row.dataset.condition = cIdx;

      // Build taxonomy options from existing select.
      var existingSelect = container.querySelector('.wb-condition-attr');
      var options = '<option value="">Attribute…</option>';
      if (existingSelect) {
        Array.prototype.slice.call(existingSelect.options).forEach(function (opt) {
          if (opt.value) options += '<option value="' + opt.value + '">' + opt.textContent + '</option>';
        });
      }

      row.innerHTML =
        '<select name="' + prefix + '[attribute]" class="wb-select wb-condition-attr" required>' + options + '</select>' +
        '<input type="hidden" name="' + prefix + '[operator]" value="equals">' +
        '<div class="wb-autocomplete wb-condition-value-wrap">' +
        '<input type="text" class="wb-input wb-autocomplete__input wb-condition-value-display" placeholder="Value…" autocomplete="off">' +
        '<input type="hidden" name="' + prefix + '[value]" class="wb-condition-value-hidden">' +
        '<div class="wb-autocomplete__dropdown"></div>' +
        '</div>' +
        '<label class="wb-checkbox wb-condition-children-label" style="display:none;">' +
        '<input type="checkbox" name="' + prefix + '[include_children]" value="1"> + Children' +
        '</label>' +
        '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition">&times;</button>';

      initRowAutocomplete(row);
      initRowChildToggle(row);
      return row;
    }

    function initRowAutocomplete(row) {
      var display = row.querySelector('.wb-condition-value-display');
      var hidden = row.querySelector('.wb-condition-value-hidden');
      var dropdown = row.querySelector('.wb-autocomplete__dropdown');
      var attrSelect = row.querySelector('.wb-condition-attr');
      if (!display || !hidden || !dropdown || !attrSelect) return;

      var debounce = null;

      display.addEventListener('input', function () {
        clearTimeout(debounce);
        hidden.value = '';
        debounce = setTimeout(function () { searchRowTerms(display, hidden, dropdown, attrSelect, display.value); }, 300);
      });

      display.addEventListener('focus', function () {
        if (!dropdown.children.length) {
          searchRowTerms(display, hidden, dropdown, attrSelect, '');
        } else {
          dropdown.style.display = 'block';
        }
      });

      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== display) {
          dropdown.style.display = 'none';
        }
      });
    }

    function searchRowTerms(display, hidden, dropdown, attrSelect, search) {
      var taxonomy = attrSelect.value;
      if (!taxonomy) { dropdown.style.display = 'none'; return; }

      var fd = new FormData();
      fd.append('action', 'woobooster_search_terms');
      fd.append('nonce', cfg.nonce);
      fd.append('taxonomy', taxonomy);
      fd.append('search', search);
      fd.append('page', 1);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.success) return;
          dropdown.innerHTML = '';

          res.data.terms.forEach(function (t) {
            var item = document.createElement('div');
            item.className = 'wb-autocomplete__item';
            item.textContent = t.name + ' (' + t.count + ')';
            item.addEventListener('click', function () {
              display.value = t.name;
              hidden.value = t.slug;
              dropdown.style.display = 'none';
            });
            dropdown.appendChild(item);
          });

          dropdown.style.display = dropdown.children.length ? 'block' : 'none';
        });
    }

    function initRowChildToggle(row) {
      var attrSelect = row.querySelector('.wb-condition-attr');
      var label = row.querySelector('.wb-condition-children-label');
      if (!attrSelect || !label) return;

      function toggle() {
        label.style.display = hierarchical.indexOf(attrSelect.value) !== -1 ? '' : 'none';
      }
      attrSelect.addEventListener('change', toggle);
      toggle();
    }

    function renumberFields() {
      container.querySelectorAll('.wb-condition-group').forEach(function (group, gIdx) {
        group.dataset.group = gIdx;
        group.querySelectorAll('.wb-condition-row').forEach(function (row, cIdx) {
          row.dataset.condition = cIdx;
          var prefix = 'conditions[' + gIdx + '][' + cIdx + ']';
          row.querySelectorAll('[name]').forEach(function (el) {
            var name = el.getAttribute('name');
            el.setAttribute('name', name.replace(/conditions\[\d+\]\[\d+\]/, prefix));
          });
        });
      });
    }
  }

  /* ── Check for Updates ──────────────────────────────────────────── */

  function initCheckUpdate() {
    var btn = document.getElementById('wb-check-update');
    var result = document.getElementById('wb-update-result');
    if (!btn || !result) return;

    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = cfg.i18n.loading || 'Checking…';
      result.textContent = '';

      var fd = new FormData();
      fd.append('action', 'woobooster_check_update');
      fd.append('nonce', cfg.nonce);

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          btn.disabled = false;
          btn.textContent = 'Check for Updates Now';

          if (res.success) {
            result.style.color = res.data.has_update ? '#d63638' : '#00a32a';
            result.textContent = res.data.message;
          } else {
            result.style.color = '#d63638';
            result.textContent = res.data.message || 'Error checking for updates.';
          }
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = 'Check for Updates Now';
          result.style.color = '#d63638';
          result.textContent = 'Network error. Check your connection.';
        });
    });
  }
  /* ── Import / Export ────────────────────────────────────────────── */

  function initImportExport() {
    var exportBtn = document.getElementById('wb-export-rules');
    var importBtn = document.getElementById('wb-import-rules-btn');
    var fileInput = document.getElementById('wb-import-file');

    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        // Direct download via window.location
        window.location.href = cfg.ajaxUrl + '?action=woobooster_export_rules&nonce=' + cfg.nonce;
      });
    }

    if (importBtn && fileInput) {
      importBtn.addEventListener('click', function () {
        fileInput.click();
      });

      fileInput.addEventListener('change', function () {
        if (!fileInput.files.length) return;
        var file = fileInput.files[0];

        // Simple validation
        if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
          alert('Please select a valid JSON file.');
          return;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
          var jsonContent = e.target.result;
          uploadImport(jsonContent);
        };
        reader.readAsText(file);
      });
    }

    function uploadImport(jsonContent) {
      if (!confirm('Are you sure you want to import rules? This will add to existing rules.')) return;

      var fd = new FormData();
      fd.append('action', 'woobooster_import_rules');
      fd.append('nonce', cfg.nonce);
      fd.append('json', jsonContent);

      importBtn.disabled = true;
      importBtn.textContent = 'Importing…';

      fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          importBtn.disabled = false;
          importBtn.textContent = 'Import';
          fileInput.value = ''; // Reset

          if (res.success) {
            alert(res.data.message);
            window.location.reload();
          } else {
            alert(res.data.message || 'Error importing rules.');
          }
        })
        .catch(function () {
          importBtn.disabled = false;
          importBtn.textContent = 'Import';
          alert('Network error.');
        });
    }
  }
})();
