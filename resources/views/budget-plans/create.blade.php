@extends('layout.v3.session')
@section('content')
    <link rel="stylesheet" href="v1/css/budget-plans.css?v={{ $FF_BUILD_TIME }}">

    <div id="generating-overlay">
        <div class="generating-card">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <h4>Generating your budget plan…</h4>
            <p class="mb-1">Analyzing transactions and building recommendations with AI.</p>
            <p class="mb-0 text-muted">This may take up to 2 minutes.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('budget-plans.generate') }}" accept-charset="UTF-8" id="budget-plan-form">
        @csrf
        <div class="row">
            {{-- ==================== LEFT COLUMN ==================== --}}
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-calendar-check"></span> Budget for</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Which month and year is this budget plan for?</p>
                        <div class="row mb-3">
                            <label for="budget_month" class="col-sm-4 col-form-label">Month</label>
                            <div class="col-sm-8">
                                <select class="form-select" id="budget_month" name="budget_month" required>
                                    @foreach($budgetMonths as $m)
                                        <option value="{{ $m['value'] }}" @selected((int) old('budget_month', $defaultBudgetMonth) === $m['value'])>{{ $m['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <label for="budget_year" class="col-sm-4 col-form-label">Year</label>
                            <div class="col-sm-8">
                                <input type="number" class="form-control" id="budget_year" name="budget_year" value="{{ old('budget_year', $defaultBudgetYear) }}" min="2020" max="2100" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-bank"></span> Financial situation</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Your current balances right now. The bank balance is your total available after salary: salary will <strong>not</strong> be added on top.</p>
                        <div class="row mb-3">
                            <label for="bank_balance" class="col-sm-4 col-form-label">Bank balance</label>
                            <div class="col-sm-8">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" class="form-control" id="bank_balance" name="bank_balance" value="{{ old('bank_balance', $defaultBankBalance) }}" required>
                                    <span class="input-group-text">AED</span>
                                </div>
                                <div class="form-text">Emirates NBD: your total balance now (salary already received).</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label for="cash_on_hand" class="col-sm-4 col-form-label">Cash on hand</label>
                            <div class="col-sm-8">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" class="form-control" id="cash_on_hand" name="cash_on_hand" value="{{ old('cash_on_hand', $defaultCashOnHand) }}">
                                    <span class="input-group-text">AED</span>
                                </div>
                                <div class="form-text">Physical cash available (treated as emergency buffer).</div>
                            </div>
                        </div>
                        <hr>
                        <p class="text-muted mb-2"><strong>Credit card balances</strong> (outstanding amounts owed)</p>
                        @foreach($creditCards as $cc)
                            <div class="row mb-2">
                                <label for="cc_{{ $cc['key'] }}" class="col-sm-4 col-form-label">{{ $cc['label'] }}</label>
                                <div class="col-sm-8">
                                    <div class="input-group">
                                        <input type="number" step="0.01" min="0" class="form-control" id="cc_{{ $cc['key'] }}" name="cc_balances[{{ $cc['key'] }}]" value="{{ old('cc_balances.'.$cc['key'], $cc['balance']) }}">
                                        <span class="input-group-text">AED</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-cash-stack"></span> Income</h3></div>
                    <div class="card-body">
                        <div class="row">
                            <label for="salary" class="col-sm-4 col-form-label">Monthly salary</label>
                            <div class="col-sm-8">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" class="form-control" id="salary" name="salary" value="{{ old('salary', $defaultSalary) }}" required>
                                    <span class="input-group-text">AED</span>
                                </div>
                                <div class="form-text">Used as the income cap for budget calculations.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-calendar-range"></span> Budget period</h3></div>
                    <div class="card-body">
                        <p class="text-muted">When does this budget run? Usually the calendar month the salary funds.</p>
                        <div class="row mb-3">
                            <label for="budget_period_start" class="col-sm-4 col-form-label">Start date</label>
                            <div class="col-sm-8"><input type="date" class="form-control" id="budget_period_start" name="budget_period_start" value="{{ old('budget_period_start', $defaultBudgetPeriodStart) }}" required></div>
                        </div>
                        <div class="row">
                            <label for="budget_period_end" class="col-sm-4 col-form-label">End date</label>
                            <div class="col-sm-8"><input type="date" class="form-control" id="budget_period_end" name="budget_period_end" value="{{ old('budget_period_end', $defaultBudgetPeriodEnd) }}" required></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-graph-up"></span> Transaction analysis period</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Past transactions to analyze for spending patterns. A 2–3 month window gives the best results.</p>
                        <div class="row mb-3">
                            <label for="start_date" class="col-sm-4 col-form-label">Start date</label>
                            <div class="col-sm-8"><input type="date" class="form-control" id="start_date" name="start_date" value="{{ old('start_date', $defaultStart) }}" required></div>
                        </div>
                        <div class="row">
                            <label for="end_date" class="col-sm-4 col-form-label">End date</label>
                            <div class="col-sm-8"><input type="date" class="form-control" id="end_date" name="end_date" value="{{ old('end_date', $defaultEnd) }}" required></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ==================== RIGHT COLUMN ==================== --}}
            <div class="col-lg-6">
                @if(count($historicalPlans) > 0)
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title"><span class="bi bi-clock-history"></span> Past budget plans</h3></div>
                        <div class="card-body">
                            <p class="text-muted">Select previous plans to give the AI context. It will compare how well you stuck to them.</p>
                            <select class="form-select" id="reference_plans" name="reference_plans[]" multiple size="{{ min(count($historicalPlans), 8) }}">
                                @foreach($historicalPlans as $path => $label)
                                    <option value="{{ $path }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Hold Ctrl/Cmd to select multiple plans.</div>
                        </div>
                    </div>
                @endif

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-exclamation-triangle"></span> Out-of-norm expenses</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Expected unusual expenses this month (quarterly rent, annual renewals, car repair…).</p>
                        <div id="extra-expenses-container"></div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="add-expense-btn"><span class="bi bi-plus-lg"></span> Add expense</button>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-check-circle"></span> Already paid today</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Payments that already went through today. They're deducted from your starting balance.</p>
                        <div id="already-paid-container"></div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="add-paid-btn"><span class="bi bi-plus-lg"></span> Add payment</button>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-lock"></span> Fixed monthly obligations</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Confirm or update the status of your fixed monthly obligations.</p>
                        @foreach($fixedObligations as $ob)
                            <div class="obligation-row">
                                <label for="ob_{{ $ob['key'] }}">{{ $ob['label'] }}</label>
                                <span class="obligation-amount">AED {{ $ob['amount'] }}</span>
                                <select class="form-select" id="ob_{{ $ob['key'] }}" name="fixed_obligations[{{ $ob['key'] }}]">
                                    <option value="active" @selected('active' === $ob['status'])>Active</option>
                                    <option value="paused" @selected('paused' === $ob['status'])>Can pause this month</option>
                                    <option value="cancelled" @selected('cancelled' === $ob['status'])>Cancelled / stopped</option>
                                </select>
                                <input type="hidden" name="fixed_obligation_amounts[{{ $ob['key'] }}]" value="{{ $ob['amount'] }}">
                                <input type="hidden" name="fixed_obligation_labels[{{ $ob['key'] }}]" value="{{ $ob['label'] }}">
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-arrow-repeat"></span> Subscription changes</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Subscriptions that changed since last month (cancelled, paused, new, or a new price).</p>
                        <div id="sub-changes-container"></div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="add-sub-change-btn"><span class="bi bi-plus-lg"></span> Add change</button>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title"><span class="bi bi-bullseye"></span> Goals &amp; instructions</h3></div>
                    <div class="card-body">
                        <p class="text-muted">Describe your goals for the month in your own words. Be as specific as you like.</p>
                        <textarea class="form-control" id="goals" name="goals" rows="8" placeholder="E.g. Livable but disciplined month. Start saving for Kazakhstan in March, keep a buffer for car costs…">{{ old('goals', '') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                @if(!$geminiReady)
                    <div class="alert alert-warning">
                        <span class="bi bi-exclamation-triangle"></span>
                        <strong>Gemini API key not configured.</strong> Add <code>GEMINI_API_KEY</code> to your <code>.env</code> to enable AI plan generation.
                    </div>
                @endif
                <div class="d-flex">
                    <a href="{{ route('budget-plans.index') }}" class="btn btn-outline-secondary"><span class="bi bi-arrow-left"></span> Back</a>
                    <button type="submit" class="btn btn-success ms-auto" id="generate-btn" @disabled(!$geminiReady)>
                        <span class="bi bi-magic"></span> Generate budget plan
                    </button>
                </div>
            </div>
        </div>
    </form>
@endsection
@section('scripts')
    <script src="v1/js/ff/budget-plans/create.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
@endsection
