@extends('layout.v3.session')
@section('content')
    @if(0 === count($plans))
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title mb-0">No budget plans</h3>
                <a href="{{ route('budget-plans.create') }}" class="btn btn-success btn-sm ms-auto"><span class="bi bi-plus-lg"></span> Create new budget plan</a>
            </div>
            <div class="card-body">
                <p class="mb-0">No budget plan files found. Use the button above to generate an AI-powered budget plan.</p>
            </div>
        </div>
    @else
        @foreach($groups as $key => $group)
            <div class="card mb-3 {{ 'monthly' === $key ? 'border-primary' : '' }}">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0">
                        <span class="bi {{ 'monthly' === $key ? 'bi-journal-text' : 'bi-folder2-open' }}"></span> {{ $group['title'] }}
                    </h3>
                    @if($loop->first)
                        <a href="{{ route('budget-plans.create') }}" class="btn btn-success btn-sm ms-auto"><span class="bi bi-plus-lg"></span> Create new budget plan</a>
                    @endif
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                        <tr>
                            <th style="width: 15%;">Month</th>
                            <th>Plan</th>
                            <th class="d-none d-md-table-cell">Last modified</th>
                            <th class="text-end">Size</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($group['plans'] as $plan)
                            <tr>
                                <td>{{ $plan['period'] }}</td>
                                <td><a href="{{ route('budget-plans.show', $plan['path']) }}"><span class="bi bi-file-earmark-text"></span> {{ $plan['name'] }}</a></td>
                                <td class="d-none d-md-table-cell">{{ $plan['modified'] }}</td>
                                <td class="text-end">{{ number_format($plan['size'] / 1024, 1) }} KB</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif
@endsection
