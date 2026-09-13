@php
    $steps = [
        1 => __('tunneling.wizard_step1_title'),
        2 => __('tunneling.wizard_step2_title'),
        3 => __('tunneling.wizard_step3_title'),
        4 => __('tunneling.wizard_step4_title'),
        5 => __('tunneling.wizard_step5_title'),
    ];
@endphp
<div class="panel-modern-card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            @foreach ($steps as $number => $label)
                <div class="d-flex align-items-center gap-2 {{ $loop->last ? '' : 'flex-grow-1' }}">
                    <span class="badge rounded-pill {{ $number < $current ? 'bg-success' : ($number === $current ? 'bg-primary' : 'bg-secondary') }}"
                          style="width: 28px; height: 28px; line-height: 20px;">
                        {{ $number < $current ? '✓' : persian_digits($number) }}
                    </span>
                    <span class="small {{ $number === $current ? 'fw-bold' : 'text-muted' }}">{{ $label }}</span>
                </div>
                @if (! $loop->last)
                    <div class="flex-grow-1 border-top mx-1" style="min-width: 12px;"></div>
                @endif
            @endforeach
        </div>
    </div>
</div>
