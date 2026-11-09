@extends('layout.v3.session')
@section('content')
    <div class="row">
        <div class="col-lg-6 col-md-12">
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title"><span class="bi bi-download"></span> {{ __('firefly.backup_download_title') }}</h3>
                </div>
                <div class="card-body">
                    @if(!$isSupported)
                        <div class="alert alert-danger mb-0">{{ __('firefly.backup_only_postgresql') }}</div>
                    @elseif(!$pgDumpFound)
                        <div class="alert alert-danger mb-0">{{ __('firefly.backup_pg_dump_not_found') }}</div>
                    @else
                        <p>{{ __('firefly.backup_download_expl') }}</p>
                        <form action="{{ route('backup.backup') }}" method="post">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                <span class="bi bi-download"></span> {{ __('firefly.backup_download_button') }}
                            </button>
                        </form>
                        <p class="text-muted mt-2 mb-0"><small>{{ __('firefly.backup_download_note') }}</small></p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="card mb-3 border-warning">
                <div class="card-header">
                    <h3 class="card-title"><span class="bi bi-upload"></span> {{ __('firefly.backup_restore_title') }}</h3>
                </div>
                <div class="card-body">
                    @if(!$isSupported)
                        <div class="alert alert-danger mb-0">{{ __('firefly.backup_only_postgresql') }}</div>
                    @elseif(!$psqlFound)
                        <div class="alert alert-danger mb-0">{{ __('firefly.backup_psql_not_found') }}</div>
                    @else
                        <div class="alert alert-warning">
                            <strong>{{ __('firefly.backup_restore_warning_title') }}</strong><br>
                            {{ __('firefly.backup_restore_warning') }}
                        </div>
                        <form action="{{ route('backup.restore') }}" method="post" enctype="multipart/form-data"
                              data-confirm="{{ __('firefly.backup_restore_confirm') }}">
                            @csrf
                            <div class="mb-3">
                                <label for="backup_file" class="form-label">{{ __('firefly.backup_restore_select_file') }}</label>
                                <input type="file" name="backup_file" id="backup_file" class="form-control" accept=".tar.gz,.gz" required>
                            </div>
                            <button type="submit" class="btn btn-warning">
                                <span class="bi bi-upload"></span> {{ __('firefly.backup_restore_button') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title"><span class="bi bi-terminal"></span> {{ __('firefly.backup_cli_title') }}</h3>
                </div>
                <div class="card-body">
                    <p>{{ __('firefly.backup_cli_expl') }}</p>
                    <pre class="mb-0">php artisan firefly:backup
php artisan firefly:restore /path/to/backup.tar.gz
php artisan firefly:backup-remote</pre>
                </div>
            </div>
        </div>
    </div>

    {{-- Google Drive setup --}}
    <div class="row">
        <div class="col-12">
            <div class="card mb-3 {{ $gdriveConnected ? 'border-success' : '' }}">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0"><span class="bi bi-cloud"></span> {{ __('firefly.gdrive_setup_title') }}</h3>
                    @if($gdriveConnected)
                        <span class="badge text-bg-success ms-auto">{{ __('firefly.gdrive_status_connected') }}</span>
                    @endif
                </div>
                <div class="card-body">
                    @if(!$gdriveConfigured)
                        <div class="alert alert-info mb-0">
                            <strong>{{ __('firefly.gdrive_setup_needed_title') }}</strong>
                            <p>{{ __('firefly.gdrive_setup_needed_expl') }}</p>
                            <ol class="mb-0">
                                <li>{{ __('firefly.gdrive_setup_step1') }}</li>
                                <li>{{ __('firefly.gdrive_setup_step2') }}</li>
                                <li>{{ __('firefly.gdrive_setup_step3') }}</li>
                                <li>{{ __('firefly.gdrive_setup_step4') }}</li>
                            </ol>
                        </div>
                    @elseif(!$gdriveConnected)
                        <p>{{ __('firefly.gdrive_connect_expl') }}</p>
                        <a href="{{ route('backup.gdrive.connect') }}" class="btn btn-info">
                            <span class="bi bi-box-arrow-in-right"></span> {{ __('firefly.gdrive_connect_button') }}
                        </a>
                    @else
                        <p>{{ __('firefly.gdrive_connected_expl') }}</p>
                        <form action="{{ route('backup.gdrive.disconnect') }}" method="post" class="d-inline"
                              data-confirm="{{ __('firefly.gdrive_disconnect_confirm') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                <span class="bi bi-box-arrow-right"></span> {{ __('firefly.gdrive_disconnect_button') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($gdriveConnected)
        <div class="row">
            <div class="col-lg-6 col-md-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><span class="bi bi-cloud-upload"></span> {{ __('firefly.gdrive_backup_title') }}</h3>
                    </div>
                    <div class="card-body">
                        @if(!$isSupported || !$pgDumpFound)
                            <div class="alert alert-danger mb-0">{{ __('firefly.backup_pg_dump_not_found') }}</div>
                        @else
                            <p>{{ __('firefly.gdrive_backup_expl') }}</p>
                            <form action="{{ route('backup.gdrive.backup') }}" method="post">
                                @csrf
                                <button type="submit" class="btn btn-primary">
                                    <span class="bi bi-cloud-upload"></span> {{ __('firefly.gdrive_backup_button') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12">
                <div class="card mb-3 border-warning">
                    <div class="card-header">
                        <h3 class="card-title"><span class="bi bi-cloud-download"></span> {{ __('firefly.gdrive_restore_title') }}</h3>
                    </div>
                    <div class="card-body">
                        <p id="gdrive-files-loading"><span class="spinner-border spinner-border-sm"></span> {{ __('firefly.gdrive_loading_files') }}</p>
                        <p id="gdrive-files-empty" class="text-muted d-none">{{ __('firefly.gdrive_no_backups') }}</p>
                        <table class="table table-sm table-hover d-none" id="gdrive-files-table">
                            <thead>
                            <tr>
                                <th>{{ __('firefly.gdrive_file_name') }}</th>
                                <th>{{ __('firefly.gdrive_file_date') }}</th>
                                <th>{{ __('firefly.gdrive_file_size') }}</th>
                                <th class="text-end">{{ __('firefly.gdrive_file_actions') }}</th>
                            </tr>
                            </thead>
                            <tbody id="gdrive-files-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><span class="bi bi-journal-text"></span> {{ __('firefly.gdrive_plans_title') }}</h3>
                    </div>
                    <div class="card-body">
                        <p>{{ __('firefly.gdrive_plans_expl') }}</p>
                        <p class="text-muted"><small>{{ __('firefly.gdrive_plans_local_count', ['count' => $localPlanCount]) }}</small></p>
                        <form action="{{ route('backup.gdrive.push-plans') }}" method="post" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-info">
                                <span class="bi bi-cloud-upload"></span> {{ __('firefly.gdrive_push_plans_button') }}
                            </button>
                        </form>
                        <form action="{{ route('backup.gdrive.pull-plans') }}" method="post" class="d-inline ms-2"
                              data-confirm="{{ __('firefly.gdrive_pull_plans_confirm') }}">
                            @csrf
                            <button type="submit" class="btn btn-warning">
                                <span class="bi bi-cloud-download"></span> {{ __('firefly.gdrive_pull_plans_button') }}
                            </button>
                        </form>
                        <div class="alert alert-warning mt-3 mb-0"><small>{{ __('firefly.gdrive_pull_plans_warning') }}</small></div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
@section('scripts')
    <script nonce="{{ $JS_NONCE }}">
        (function () {
            'use strict';
            // Confirmation prompts. Inline onsubmit handlers are blocked by the CSP, so attach them here.
            document.addEventListener('submit', function (event) {
                const message = event.target.getAttribute('data-confirm');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            }, true);

            @if($gdriveConnected)
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const text = {
                restoreConfirm: @json(__('firefly.gdrive_restore_confirm')),
                deleteConfirm: @json(__('firefly.gdrive_delete_confirm')),
                restoreButton: @json(__('firefly.gdrive_restore_btn')),
                deleteButton: @json(__('firefly.gdrive_delete_btn')),
                loadError: @json(__('firefly.gdrive_load_error')),
            };

            // Build each row with DOM calls (never innerHTML) so file names/ids from Drive can't inject markup.
            function actionForm(url, fileId, confirmText, buttonClass, iconClass, label) {
                const form = document.createElement('form');
                form.method = 'post';
                form.action = url;
                form.className = 'd-inline ms-1';
                form.setAttribute('data-confirm', confirmText);
                for (const [name, value] of [['_token', csrfToken], ['file_id', fileId]]) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                const button = document.createElement('button');
                button.type = 'submit';
                button.className = 'btn btn-sm ' + buttonClass;
                const icon = document.createElement('span');
                icon.className = 'bi ' + iconClass;
                button.append(icon, ' ' + label);
                form.appendChild(button);
                return form;
            }

            fetch(@json(route('backup.gdrive.files')), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    document.getElementById('gdrive-files-loading').classList.add('d-none');
                    const files = data.files || [];
                    if (0 === files.length) {
                        document.getElementById('gdrive-files-empty').classList.remove('d-none');
                        return;
                    }
                    document.getElementById('gdrive-files-table').classList.remove('d-none');
                    const tbody = document.getElementById('gdrive-files-tbody');
                    files.forEach(function (file) {
                        const row = document.createElement('tr');
                        const created = new Date(file.createdTime);
                        const cells = [
                            file.name,
                            created.toLocaleDateString() + ' ' + created.toLocaleTimeString(),
                            file.size ? (parseInt(file.size, 10) / 1024).toFixed(1) + ' KB' : '—',
                        ];
                        cells.forEach(function (value) {
                            const cell = document.createElement('td');
                            cell.textContent = value;
                            row.appendChild(cell);
                        });
                        const actions = document.createElement('td');
                        actions.className = 'text-end text-nowrap';
                        actions.append(
                            actionForm(@json(route('backup.gdrive.restore')), file.id, text.restoreConfirm, 'btn-warning', 'bi-upload', text.restoreButton),
                            actionForm(@json(route('backup.gdrive.delete')), file.id, text.deleteConfirm, 'btn-danger', 'bi-trash', text.deleteButton),
                        );
                        row.appendChild(actions);
                        tbody.appendChild(row);
                    });
                })
                .catch(function () {
                    const loading = document.getElementById('gdrive-files-loading');
                    loading.textContent = text.loadError;
                    loading.className = 'text-danger';
                });
            @endif
        })();
    </script>
@endsection
