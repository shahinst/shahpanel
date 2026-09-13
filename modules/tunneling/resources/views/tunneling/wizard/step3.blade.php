@extends('layouts.panel')

@section('page_title', __('tunneling.wizard_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('tunneling.wizard_title'),
    'subtitle' => __('tunneling.wizard_subtitle'),
    'icon' => 'bx-magic',
    'actions' => '<a href="'.route('admin.tunneling.index').'" class="btn btn-light">'.e(__('app.cancel')).'</a>',
])

@include('tunneling::wizard._stepper', ['current' => 3])

@php
    $selectedKinds = old('tunnel_kinds', $data['tunnel_kinds'] ?? []);
    $balanceChoice = old('kind_balance_choice', $data['kind_selection_mode'] ?? 'balanced');
    $priorityFirst = old('kind_priority_first', $data['kind_priority_order'][0] ?? null);
@endphp

<form method="POST" action="{{ route('admin.tunneling.wizard.step3.store') }}">
    @csrf

    <div class="panel-modern-card mb-3">
        <div class="card-head"><h3><i class="bx bx-git-merge align-middle"></i> {{ __('tunneling.wizard_step3_title') }}</h3></div>
        <div class="card-body">
            <p class="text-muted">{{ __('tunneling.wizard_step3_hint') }}</p>

            <div class="row g-2 mb-3" id="wizard-kind-list">
                @foreach ($kinds as $kind)
                    <div class="col-sm-6 col-lg-4">
                        <div class="form-check">
                            <input class="form-check-input wizard-kind-checkbox" type="checkbox" name="tunnel_kinds[]"
                                   id="kind-{{ $kind->value }}" value="{{ $kind->value }}"
                                   @checked(in_array($kind->value, (array) $selectedKinds, true))>
                            <label class="form-check-label" for="kind-{{ $kind->value }}">{{ $kind->label() }}</label>
                        </div>
                    </div>
                @endforeach
            </div>

            <div id="wizard-multi-kind-options" class="border rounded p-3" style="display: none;">
                <strong class="d-block mb-2">{{ __('tunneling.wizard_multi_kind_title') }}</strong>

                <div class="form-check mb-1">
                    <input class="form-check-input" type="radio" name="kind_balance_choice" id="balance-choice-balanced"
                           value="balanced" @checked($balanceChoice !== 'priority')>
                    <label class="form-check-label" for="balance-choice-balanced">{{ __('tunneling.wizard_balance_balanced') }}</label>
                    <small class="text-muted d-block">{{ __('tunneling.wizard_balance_balanced_hint') }}</small>
                </div>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="kind_balance_choice" id="balance-choice-priority"
                           value="priority" @checked($balanceChoice === 'priority')>
                    <label class="form-check-label" for="balance-choice-priority">{{ __('tunneling.wizard_balance_priority') }}</label>
                    <small class="text-muted d-block">{{ __('tunneling.wizard_balance_priority_hint') }}</small>
                </div>

                <div id="wizard-priority-first-wrap" style="display: none;">
                    <x-form.group :label="__('tunneling.wizard_priority_first')">
                        <select name="kind_priority_first" id="wizard-priority-first" class="form-select">
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind->value }}" class="wizard-priority-first-option" data-kind="{{ $kind->value }}"
                                        @selected($priorityFirst === $kind->value)>{{ $kind->label() }}</option>
                            @endforeach
                        </select>
                    </x-form.group>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="{{ route('admin.tunneling.wizard.step2') }}" class="btn btn-light"><i class="bx bx-chevron-right"></i> {{ __('tunneling.wizard_back') }}</a>
        <button class="btn btn-primary">{{ __('tunneling.wizard_next') }} <i class="bx bx-chevron-left"></i></button>
    </div>
</form>

<script>
(function () {
    var checkboxes = document.querySelectorAll('.wizard-kind-checkbox');
    var multiWrap = document.getElementById('wizard-multi-kind-options');
    var priorityRadio = document.getElementById('balance-choice-priority');
    var balancedRadio = document.getElementById('balance-choice-balanced');
    var priorityFirstWrap = document.getElementById('wizard-priority-first-wrap');
    var priorityFirstOptions = document.querySelectorAll('.wizard-priority-first-option');

    function checkedValues() {
        return Array.prototype.filter.call(checkboxes, function (c) { return c.checked; })
            .map(function (c) { return c.value; });
    }

    function syncMultiKindVisibility() {
        var selected = checkedValues();
        multiWrap.style.display = selected.length > 1 ? '' : 'none';

        priorityFirstOptions.forEach(function (opt) {
            opt.hidden = selected.indexOf(opt.dataset.kind) === -1;
        });

        if (selected.length <= 1) {
            return;
        }

        // If the currently selected "first priority" option got unchecked, fall back to the first checked kind.
        var firstSelect = document.getElementById('wizard-priority-first');
        if (selected.indexOf(firstSelect.value) === -1) {
            firstSelect.value = selected[0];
        }
    }

    function syncPriorityFirstVisibility() {
        priorityFirstWrap.style.display = priorityRadio.checked ? '' : 'none';
    }

    checkboxes.forEach(function (c) { c.addEventListener('change', syncMultiKindVisibility); });
    priorityRadio.addEventListener('change', syncPriorityFirstVisibility);
    balancedRadio.addEventListener('change', syncPriorityFirstVisibility);

    syncMultiKindVisibility();
    syncPriorityFirstVisibility();
})();
</script>
@endsection
