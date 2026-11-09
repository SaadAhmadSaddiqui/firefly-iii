@extends('layout.v3.session')
@section('content')
    <link rel="stylesheet" href="v1/lib/bank-import/import.css?v={{ $FF_BUILD_TIME }}">

    <details class="card mb-3">
        <summary class="card-header"><span class="bi bi-filetype-csv"></span> Expected CSV format and import rules</summary>
        <div class="card-body">
            <p>In FAB online banking use <strong>Download Transactions</strong> (CSV). FAB encrypts its web responses, so the CSV is the only export. <strong>Unbilled transactions are not in the CSV</strong>: add them by hand, tagged <code>fab-unbilled</code>, and delete that copy once it shows up in a later CSV.</p>
            <div class="row">
                <div class="col-md-7">
<pre class="schema-pre">Posting Date,Value date,Description,Debit Amount,Credit Amount
" 08/11/2026"," 07/11/2026",3343948749 - AMAZONUFR DI  Dubai  DXB,15.51,
" 05/11/2026"," 05/11/2026",3339680654 - LATE PAYMENT FEE,230.00,
" 04/10/2026"," 04/10/2026",3287598343 - INSTQ1MUTD4JXA00,,"2,195.59"</pre>
                </div>
                <div class="col-md-5">
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>Column</th><th>Use</th></tr></thead>
                        <tbody>
                        <tr><td><code>Value date</code></td><td><strong>The transaction date</strong> (FAB often posts days later)</td></tr>
                        <tr><td><code>Description</code></td><td>Reference + merchant</td></tr>
                        <tr><td><code>Debit</code> / <code>Credit</code></td><td>Purchases / refunds. Card bill payments (<code>INST…</code>, <code>… CARD PMT</code>) are skipped: they come from the Emirates NBD export.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </details>

    @include('bank-import.partials.workflow', ['editorLabel' => 'Paste CSV', 'controls' => ''])
@endsection
@section('scripts')
    @include('bank-import.partials.scripts', ['config' => [
        'previewUrl' => route('bank-import.fab.preview'),
        'importUrl'  => route('bank-import.fab.import'),
    ]])
@endsection
