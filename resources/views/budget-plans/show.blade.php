@extends('layout.v3.session')
@section('content')
    <link rel="stylesheet" href="v1/css/budget-plans.css?v={{ $FF_BUILD_TIME }}">
    <div class="row">
        <div class="col-xl-10 offset-xl-1">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0"><span class="bi bi-file-earmark-text"></span> {{ $subTitle }}</h3>
                    <a href="{{ route('budget-plans.index') }}" class="btn btn-outline-secondary btn-sm ms-auto">
                        <span class="bi bi-arrow-left"></span> All plans
                    </a>
                </div>
                {{-- Plans can be AI-generated: raw HTML in the Markdown is escaped and unsafe links are dropped. --}}
                <div class="card-body budget-plan-content">
                    {!! \Illuminate\Support\Str::markdown($content, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                </div>
            </div>
        </div>
    </div>
@endsection
