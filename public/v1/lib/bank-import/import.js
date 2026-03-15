(function () {
    'use strict';

    var config = window.bankImportConfig;
    if (!config) return;

    var editor, lastContent = null, lastAcct = null, previewData = [];

    function buildOptions(list, placeholder) {
        var html = '<option value="">' + esc(placeholder) + '</option>';
        (list || []).forEach(function (item) {
            html += '<option value="' + item.id + '">' + esc(item.name) + '</option>';
        });
        return html;
    }

    var budgetOpts    = buildOptions(config.budgets || [], '-- Budget --');
    var categoryOpts  = buildOptions(config.categories || [], '-- Category --');
    var billOpts      = buildOptions(config.bills || [], '-- Subscription --');

    function init() {
        var ta = document.getElementById('code-editor');
        if (ta) {
            var opts = {
                mode: config.editorMode,
                theme: 'monokai',
                lineNumbers: true,
                lineWrapping: true,
                tabSize: 2,
                foldGutter: true,
                gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
            };
            if (config.lint) {
                opts.lint = true;
                opts.gutters.push('CodeMirror-lint-markers');
            }
            editor = CodeMirror.fromTextArea(ta, opts);
        }

        var fi = document.getElementById('import-file');
        if (fi) fi.addEventListener('change', function (e) {
            var f = e.target.files[0];
            if (!f) return;
            var r = new FileReader();
            r.onload = function (ev) { if (editor) editor.setValue(ev.target.result); };
            r.readAsText(f);
        });

        applyFormatMode();
        var formatRadioSelector = config.formatSelector ? config.formatSelector.replace(':checked', '') : '';
        var formatInputs = formatRadioSelector ? document.querySelectorAll(formatRadioSelector) : [];
        if (formatInputs && formatInputs.length) {
            formatInputs.forEach(function (input) {
                input.addEventListener('change', applyFormatMode);
            });
        }

        document.getElementById('btn-preview').addEventListener('click', doPreview);
        document.getElementById('btn-import').addEventListener('click', doImport);
        document.getElementById('btn-dry-run').addEventListener('click', doDryRun);

        document.getElementById('preview-table').addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-delete-txn');
            if (btn) deleteTransaction(btn);
        });
    }

    function applyFormatMode() {
        if (!config.formatSelector || !config.editorModeByFormat) return;
        var el = document.querySelector(config.formatSelector);
        if (!el || !editor) return;
        var format = el.value;
        var mode = config.editorModeByFormat[format];
        if (mode) {
            editor.setOption('mode', mode);
        }
        var label = document.getElementById('editor-label');
        if (label) label.textContent = format === 'json' ? 'Paste JSON' : 'Paste CSV';
    }

    function acct() {
        var s = document.getElementById('source-account');
        return s ? s.value : null;
    }

    function content() {
        return editor ? editor.getValue().trim() : '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function spin(b, on) {
        if (on) {
            b.disabled = true;
            b.dataset.o = b.innerHTML;
            b.innerHTML = '<span class="fa fa-spinner fa-spin fa-fw"></span> Working...';
        } else {
            b.disabled = false;
            if (b.dataset.o) b.innerHTML = b.dataset.o;
        }
    }

    function collectOverrides() {
        var rows = document.querySelectorAll('#preview-table tbody tr');
        var overrides = [];
        rows.forEach(function (tr) {
            var budgetSel   = tr.querySelector('.sel-budget');
            var categorySel = tr.querySelector('.sel-category');
            var billSel     = tr.querySelector('.sel-bill');
            overrides.push({
                budget_id:   budgetSel   ? budgetSel.value   : '',
                category_id: categorySel ? categorySel.value : '',
                bill_id:     billSel     ? billSel.value     : ''
            });
        });
        return overrides;
    }

    function doPreview() {
        var c = content();
        if (!c) { alert('Upload a file or paste content first.'); return; }
        var b = document.getElementById('btn-preview');
        spin(b, true);

        var fd = new FormData();
        fd.append('pasted_content', c);
        fd.append('_token', config.csrfToken);
        if (config.formatParamName && config.formatSelector) {
            var fmtEl = document.querySelector(config.formatSelector);
            if (fmtEl) fd.append(config.formatParamName, fmtEl.value);
        }

        var a = acct();
        if (config.sourceAccountParamName && a) fd.append(config.sourceAccountParamName, a);
        lastContent = c;
        lastAcct = a;

        fetch(config.previewUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { spin(b, false); if (d.error) { alert(d.error); return; } showPreview(d); })
            .catch(function (e) { spin(b, false); alert('Preview failed: ' + e.message); });
    }

    function doImport() {
        if (!confirm('This will create all previewed transactions. Continue?')) return;
        var b = document.getElementById('btn-import');
        spin(b, true);

        var fd = new FormData();
        fd.append('pasted_content', lastContent || content());
        fd.append('_token', config.csrfToken);
        fd.append('overrides', JSON.stringify(collectOverrides()));
        if (config.formatParamName && config.formatSelector) {
            var fmtEl = document.querySelector(config.formatSelector);
            if (fmtEl) fd.append(config.formatParamName, fmtEl.value);
        }
        if (config.sourceAccountParamName && lastAcct) fd.append(config.sourceAccountParamName, lastAcct);

        fetch(config.importUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { spin(b, false); if (d.error) { alert(d.error); return; } showResults(d); })
            .catch(function (e) { spin(b, false); alert('Import failed: ' + e.message); });
    }

    function doDryRun() {
        var b = document.getElementById('btn-dry-run');
        spin(b, true);

        var fd = new FormData();
        fd.append('pasted_content', lastContent || content());
        fd.append('_token', config.csrfToken);
        fd.append('dry_run', '1');
        fd.append('overrides', JSON.stringify(collectOverrides()));
        if (config.formatParamName && config.formatSelector) {
            var fmtEl = document.querySelector(config.formatSelector);
            if (fmtEl) fd.append(config.formatParamName, fmtEl.value);
        }
        if (config.sourceAccountParamName && lastAcct) fd.append(config.sourceAccountParamName, lastAcct);

        fetch(config.importUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { spin(b, false); if (d.error) { alert(d.error); return; } showDryRunResults(d); })
            .catch(function (e) { spin(b, false); alert('Dry run failed: ' + e.message); });
    }

    function showPreview(data) {
        var sec = document.getElementById('preview-section');
        var tb = document.querySelector('#preview-table tbody');
        document.getElementById('preview-count').textContent = data.total;
        document.getElementById('skipped-count').textContent = data.skipped_count;
        tb.innerHTML = '';

        if (!data.transactions.length) {
            tb.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No transactions found.</td></tr>';
            sec.style.display = 'block';
            return;
        }

        previewData = data.transactions;
        data.transactions.forEach(function (t, idx) {
            var tr = document.createElement('tr');
            var btnHtml = '<button type="button" class="btn btn-xs btn-danger btn-delete-txn" title="Remove from source"' +
                ' data-idx="' + idx + '"' +
                '><span class="fa fa-trash"></span></button>';

            if (t.skipped) {
                tr.className = 'preview-skipped';
                var pfx = t.type === 'deposit' ? '+' : '-';
                tr.innerHTML =
                    '<td>' + esc(t.date) + '</td>' +
                    '<td><span class="label label-default">skipped</span></td>' +
                    '<td class="text-right">' + pfx + ' ' + esc(t.currency) + ' ' + parseFloat(t.amount || 0).toFixed(2) + '</td>' +
                    '<td>' + esc(t.description) + '</td>' +
                    '<td colspan="2" class="text-muted"><em>' + esc(t.skip_reason || '') + '</em></td>' +
                    '<td></td>' +
                    '<td></td><td></td><td></td>' +
                    '<td>' + btnHtml + '</td>';
            } else {
                var tc = t.type === 'deposit' ? 'success' : (t.type === 'transfer' ? 'info' : 'danger');
                var pfx2 = t.type === 'deposit' ? '+' : '-';
                var fn = t.foreign_amount && t.foreign_currency ? ' (' + t.foreign_currency + ' ' + t.foreign_amount + ')' : '';
                tr.innerHTML =
                    '<td>' + esc(t.date) + '</td>' +
                    '<td><span class="label label-' + tc + '">' + esc(t.type) + '</span></td>' +
                    '<td class="text-right">' + pfx2 + ' ' + esc(t.currency) + ' ' + parseFloat(t.amount).toFixed(2) + esc(fn) + '</td>' +
                    '<td>' + esc(t.description) + '</td>' +
                    '<td>' + esc(t.source || '') + '</td>' +
                    '<td>' + esc(t.destination || '') + '</td>' +
                    '<td>' + (t.tags || []).map(function (g) { return '<span class="label label-default">' + esc(g) + '</span>'; }).join(' ') + '</td>' +
                    '<td><select class="form-control input-sm sel-budget">' + budgetOpts + '</select></td>' +
                    '<td><select class="form-control input-sm sel-category">' + categoryOpts + '</select></td>' +
                    '<td><select class="form-control input-sm sel-bill">' + billOpts + '</select></td>' +
                    '<td>' + btnHtml + '</td>';
            }
            tb.appendChild(tr);
        });

        sec.style.display = 'block';
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function deleteTransaction(btn) {
        var row = btn.closest('tr');
        var idx = parseInt(btn.dataset.idx, 10);
        var item = previewData[idx];
        if (!item) return;

        var isSkipped = !!item.skipped;
        var editorContent = content();
        if (!editorContent) return;

        var updated = editorContent;
        if ((config.editorMode === 'application/json' || (config.editorModeByFormat && editor)) && editor) {
            var currentMode = editor.getOption ? editor.getOption('mode') : config.editorMode;
            if (currentMode === 'application/json') {
                try {
                    var data = JSON.parse(editorContent);
                    if (data.transactions && item.original_id) {
                        data.transactions = data.transactions.filter(function (txn) {
                            var txnId = txn.id !== undefined ? txn.id : txn.transRefNo;
                            return txnId !== item.original_id;
                        });
                        updated = JSON.stringify(data, null, '\t');
                    }
                } catch (err) {
                    alert('Could not parse editor JSON: ' + err.message);
                    return;
                }
            } else if (item.original_raw) {
                var lines = editorContent.split(/\r?\n/);
                var needle = item.original_raw.trim();
                var found = false;
                var filtered = lines.filter(function (line) {
                    if (!found && line.trim() === needle) {
                        found = true;
                        return false;
                    }
                    return true;
                });
                updated = filtered.join('\n');
            }
        } else if (item.original_raw) {
            var lines = editorContent.split(/\r?\n/);
            var needle = item.original_raw.trim();
            var found = false;
            var filtered = lines.filter(function (line) {
                if (!found && line.trim() === needle) {
                    found = true;
                    return false;
                }
                return true;
            });
            updated = filtered.join('\n');
        }

        if (editor) editor.setValue(updated);
        lastContent = updated;

        row.parentNode.removeChild(row);

        var countEl = document.getElementById(isSkipped ? 'skipped-count' : 'preview-count');
        var cur = parseInt(countEl.textContent, 10) || 0;
        if (cur > 0) countEl.textContent = cur - 1;
    }

    function showDryRunResults(data) {
        var sec = document.getElementById('dry-run-section');
        var summary = document.getElementById('dry-run-summary');
        var tb = document.querySelector('#dry-run-table tbody');

        summary.innerHTML =
            '<dt>Would be created</dt><dd><strong class="text-success">' + data.created + '</strong></dd>' +
            '<dt>Duplicates</dt><dd><strong class="text-warning">' + data.duplicates + '</strong></dd>' +
            '<dt>Would fail</dt><dd>' + (data.failed > 0 ? '<strong class="text-danger">' + data.failed + '</strong>' : '0') + '</dd>';

        tb.innerHTML = '';
        var details = data.details || [];
        details.forEach(function (d) {
            var tr = document.createElement('tr');
            var statusLabel, statusClass;
            if (d.status === 'created') {
                statusLabel = 'Would be created';
                statusClass = 'success';
                tr.className = 'dry-run-created';
            } else if (d.status === 'duplicate') {
                statusLabel = 'Duplicate';
                statusClass = 'warning';
                tr.className = 'dry-run-duplicate';
            } else {
                statusLabel = 'Would fail';
                statusClass = 'danger';
                tr.className = 'dry-run-failed';
            }
            var pfx = d.type === 'deposit' ? '+' : '-';
            var budgetCell  = d.budget_name   ? '<span class="label label-info">'    + esc(d.budget_name)   + '</span>' : '';
            var categoryCell = d.category_name ? '<span class="label label-primary">' + esc(d.category_name) + '</span>' : '';
            var billCell    = d.bill_name     ? '<span class="label label-warning">'  + esc(d.bill_name)     + '</span>' : '';
            tr.innerHTML =
                '<td>' + esc(d.date) + '</td>' +
                '<td>' + esc(d.description) + '</td>' +
                '<td class="text-right">' + pfx + ' ' + esc(d.currency) + ' ' + parseFloat(d.amount).toFixed(2) + '</td>' +
                '<td>' + budgetCell + '</td>' +
                '<td>' + categoryCell + '</td>' +
                '<td>' + billCell + '</td>' +
                '<td><span class="label label-' + statusClass + '">' + esc(statusLabel) + '</span></td>' +
                '<td>' + (d.message ? esc(d.message) : '') + '</td>';
            tb.appendChild(tr);
        });

        document.getElementById('results-section').style.display = 'none';
        sec.style.display = 'block';
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function showResults(data) {
        var sec = document.getElementById('results-section');
        var body = document.getElementById('results-body');
        var h = '<dl class="dl-horizontal">' +
            '<dt>Created</dt><dd><strong class="text-success">' + data.created + '</strong></dd>' +
            '<dt>Duplicates skipped</dt><dd>' + data.duplicates + '</dd>' +
            '<dt>Failed</dt><dd>' + (data.failed > 0 ? '<strong class="text-danger">' + data.failed + '</strong>' : '0') + '</dd>' +
            '</dl>';
        if (data.errors && data.errors.length) {
            h += '<div class="callout callout-danger"><h4>Errors</h4><ul>';
            data.errors.forEach(function (e) { h += '<li>' + esc(e) + '</li>'; });
            h += '</ul></div>';
        }
        body.innerHTML = h;
        document.getElementById('preview-section').style.display = 'none';
        document.getElementById('dry-run-section').style.display = 'none';
        sec.style.display = 'block';
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
