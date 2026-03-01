(function () {
    'use strict';

    var config = window.bankImportConfig;
    if (!config) return;

    var editor, lastContent = null, lastAcct = null;

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

        document.getElementById('btn-preview').addEventListener('click', doPreview);
        document.getElementById('btn-import').addEventListener('click', doImport);
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

    function doPreview() {
        var c = content();
        if (!c) { alert('Upload a file or paste content first.'); return; }
        var b = document.getElementById('btn-preview');
        spin(b, true);

        var fd = new FormData();
        fd.append('pasted_content', c);
        fd.append('_token', config.csrfToken);

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
        if (config.sourceAccountParamName && lastAcct) fd.append(config.sourceAccountParamName, lastAcct);

        fetch(config.importUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { spin(b, false); if (d.error) { alert(d.error); return; } showResults(d); })
            .catch(function (e) { spin(b, false); alert('Import failed: ' + e.message); });
    }

    function showPreview(data) {
        var sec = document.getElementById('preview-section');
        var tb = document.querySelector('#preview-table tbody');
        document.getElementById('preview-count').textContent = data.total;
        document.getElementById('skipped-count').textContent = data.skipped_count;
        tb.innerHTML = '';

        if (!data.transactions.length) {
            tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No transactions found.</td></tr>';
            sec.style.display = 'block';
            return;
        }

        data.transactions.forEach(function (t) {
            var tr = document.createElement('tr');
            var tc = t.type === 'deposit' ? 'success' : (t.type === 'transfer' ? 'info' : 'danger');
            var pfx = t.type === 'deposit' ? '+' : '-';
            var fn = t.foreign_amount && t.foreign_currency ? ' (' + t.foreign_currency + ' ' + t.foreign_amount + ')' : '';
            tr.innerHTML =
                '<td>' + esc(t.date) + '</td>' +
                '<td><span class="label label-' + tc + '">' + esc(t.type) + '</span></td>' +
                '<td class="text-right">' + pfx + ' ' + esc(t.currency) + ' ' + parseFloat(t.amount).toFixed(2) + esc(fn) + '</td>' +
                '<td>' + esc(t.description) + '</td>' +
                '<td>' + esc(t.source || '') + '</td>' +
                '<td>' + esc(t.destination || '') + '</td>' +
                '<td>' + (t.tags || []).map(function (g) { return '<span class="label label-default">' + esc(g) + '</span>'; }).join(' ') + '</td>';
            tb.appendChild(tr);
        });

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
        sec.style.display = 'block';
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
