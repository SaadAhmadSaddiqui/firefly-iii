{{-- Shared bank import script setup. The page passes $config (bank-specific keys). --}}
@php
    $bankImportConfig = array_merge([
        'csrfToken'  => csrf_token(),
        'budgets'    => $budgets,
        'categories' => $categories,
        'bills'      => $bills,
    ], $config);
@endphp
<script nonce="{{ $JS_NONCE }}">
    window.bankImportConfig = @json($bankImportConfig);
</script>
<script src="v1/lib/bank-import/import.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
