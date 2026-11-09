@extends('layout.v3.session')
@section('content')
    <link rel="stylesheet" href="v1/lib/bank-import/import.css?v={{ $FF_BUILD_TIME }}">

    <details class="card mb-3">
        <summary class="card-header"><span class="bi bi-filetype-json"></span> Expected JSON format and import rules</summary>
        <div class="card-body">
            <p>Export your transactions from the Emirates NBD or Emirates Islamic app as JSON (a top-level <code>transactions</code> array). The Emirates Islamic portal pages at <strong>300 rows</strong>: export long periods in parts and import every part.</p>
            <div class="row">
                <div class="col-md-7">
<pre class="schema-pre">{
  "transactions": [
    {
      "id": "TXN-001-EXAMPLE",
      "type": "ECOMMERCE",
      "creditDebitIndicator": "DR",
      "amount": 125.50,
      "currencyCode": "AED",
      "date": 1709251200000,
      "status": "SETTLED",
      "referenceNumber": "FT26025ABC123",
      "purpose": { "narrations": ["125.50,AED"] },
      "terminal": { "name": "AMAZON.AE", "city": "DUBAI", "country": "AE" }
    }
  ]
}</pre>
                </div>
                <div class="col-md-5">
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>Rule</th><th>What happens</th></tr></thead>
                        <tbody>
                        <tr><td>FAILED / CANCELLED / REVERSED / REFUNDED</td><td>Skipped</td></tr>
                        <tr><td>Card bill payment in a card export</td><td>Skipped: the Emirates NBD export already has it as a transfer. Import NBD first.</td></tr>
                        <tr><td><code>REV RMA …</code> credit</td><td>Skipped: a cancelled card payment coming back, not income</td></tr>
                        <tr><td>Pending hold &gt; 21 days old</td><td>Skipped: expired pre-authorisation</td></tr>
                        <tr><td>Pending hold + settled charge of the same amount</td><td>Imported, tagged <code>possible-duplicate</code>: check it</td></tr>
                        <tr><td>Payment to a card not in Firefly</td><td>Imported, tagged <code>unidentified-card</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </details>

    @include('bank-import.partials.workflow', [
        'editorLabel' => 'Paste JSON',
        'controls'    => '
            <div class="mb-3">
                <label for="source-account" class="form-label">Target account</label>
                <select id="source-account" class="form-select">
                    <option value="1"'.(1 === $defaultAccount ? ' selected' : '').'>Emirates NBD (current account)</option>
                    <option value="49">Emirates Islamic RTA credit card</option>
                </select>
            </div>',
    ])
@endsection
@section('scripts')
    @include('bank-import.partials.scripts', ['config' => [
        'previewUrl'             => route('bank-import.enbd.preview'),
        'importUrl'              => route('bank-import.enbd.import'),
        'sourceAccountParamName' => 'source_account_id',
    ]])
@endsection
