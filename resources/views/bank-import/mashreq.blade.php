@extends('layout.v3.session')
@section('content')
    <link rel="stylesheet" href="v1/lib/bank-import/import.css?v={{ $FF_BUILD_TIME }}">

    <details class="card mb-3">
        <summary class="card-header"><span class="bi bi-filetype-csv"></span> Expected format (CSV or JSON)</summary>
        <div class="card-body">
            <p>Export your credit card transactions from the Mashreq app or online banking, as CSV or JSON. JSON exports wrapped as <code>{"data": {"transactions": [...]}}</code> are accepted as-is.</p>
            <div class="row">
                <div class="col-md-6">
                    <h5>CSV</h5>
<pre class="schema-pre">Date,Description,Currency,Original Amount,Local Amount
22-Sep-2025,TALABAT POSTPAID DUBAI,AED,-45.00,-45.00
15-Oct-2025,APPLE.COM/BILL ITUNES.COM,USD,-4.99,-18.35
01-Nov-2025,LOYALTY POINTS REDEMPTION,AED,150.00,150.00</pre>
                </div>
                <div class="col-md-6">
                    <h5>JSON</h5>
<pre class="schema-pre">{
  "transactions": [
    {
      "transDate": "2026-03-12",
      "postDate": "2026-03-14",
      "transDescription": "MERCHANT NAME LOCATION",
      "amount": -25,
      "transRefNo": "1475081703",
      "currency": "AED",
      "sourceAmount": 25
    }
  ]
}</pre>
                </div>
            </div>
            <p class="mt-2 mb-0">Negative amounts are purchases, positive are credits. Card bill payments are skipped: they're imported as transfers from the Emirates NBD export.</p>
        </div>
    </details>

    @include('bank-import.partials.workflow', [
        'editorLabel' => 'Paste JSON',
        'controls'    => '
            <div class="mb-3">
                <span class="form-label d-block">Format</span>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="import_format" id="format-json" value="json" checked>
                    <label class="form-check-label" for="format-json">JSON</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="import_format" id="format-csv" value="csv">
                    <label class="form-check-label" for="format-csv">CSV</label>
                </div>
            </div>',
    ])
@endsection
@section('scripts')
    @include('bank-import.partials.scripts', ['config' => [
        'previewUrl'         => route('bank-import.mashreq.preview'),
        'importUrl'          => route('bank-import.mashreq.import'),
        'formatParamName'    => 'format',
        'formatSelector'     => 'input[name=import_format]:checked',
        'editorModeByFormat' => ['csv' => 'text/plain', 'json' => 'application/json'],
    ]])
@endsection
