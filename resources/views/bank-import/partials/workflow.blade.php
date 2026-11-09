{{-- Shared bank import workflow: editor, preview, dry run, results. The page supplies $controls (left card body). --}}
<div class="row">
    <div class="col-lg-4 col-md-5">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><span class="bi bi-upload"></span> Upload</h3>
            </div>
            <div class="card-body">
                {!! $controls !!}
                <div class="mb-2">
                    <label for="import-file" class="form-label">Upload file</label>
                    <input type="file" id="import-file" class="form-control" accept="{{ $accept }}">
                </div>
                <p class="text-muted mb-0"><small>Or paste the export into the box on the right.</small></p>
            </div>
        </div>
    </div>
    <div class="col-lg-8 col-md-7">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><span class="bi bi-code-square"></span> <span id="editor-label">{{ $editorLabel }}</span></h3>
            </div>
            <div class="card-body p-0">
                <textarea id="code-editor" class="form-control border-0 rounded-0" spellcheck="false" aria-label="{{ $editorLabel }}"></textarea>
            </div>
        </div>
    </div>
</div>

<div class="mb-3">
    <button id="btn-preview" class="btn btn-info">
        <span class="bi bi-eye"></span> Preview transactions
    </button>
</div>

<div class="row" id="preview-section" style="display: none;">
    <div class="col-12">
        <div class="card mb-3 border-success">
            <div class="card-header">
                <h3 class="card-title"><span class="bi bi-list-check"></span> Preview (<span id="preview-count">0</span> transactions, <span id="skipped-count">0</span> skipped)</h3>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-hover mb-0" id="preview-table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Tags</th>
                        <th>Budget</th>
                        <th>Category</th>
                        <th>Subscription</th>
                        <th style="width: 60px;"></th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="card-footer">
                <button id="btn-dry-run" class="btn btn-warning me-2">
                    <span class="bi bi-eyedropper"></span> Dry run
                </button>
                <button id="btn-import" class="btn btn-success">
                    <span class="bi bi-check-lg"></span> Confirm import
                </button>
            </div>
        </div>
    </div>
</div>

<div class="row" id="dry-run-section" style="display: none;">
    <div class="col-12">
        <div class="card mb-3 border-warning">
            <div class="card-header">
                <h3 class="card-title"><span class="bi bi-eyedropper"></span> Dry run results</h3>
            </div>
            <div class="card-body">
                <dl class="row" id="dry-run-summary"></dl>
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="dry-run-table">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                            <th>Budget</th>
                            <th>Category</th>
                            <th>Subscription</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row" id="results-section" style="display: none;">
    <div class="col-lg-6">
        <div class="card mb-3 border-success">
            <div class="card-header">
                <h3 class="card-title"><span class="bi bi-check-circle"></span> Import results</h3>
            </div>
            <div class="card-body" id="results-body"></div>
        </div>
    </div>
</div>
