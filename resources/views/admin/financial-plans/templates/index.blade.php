@extends('layouts.panel')

@section('page_title', __('financial_plans.page_title_templates'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('financial_plans.page_title_templates'),
    'subtitle' => __('financial_plans.templates_hint'),
    'icon' => 'bx-wallet-alt',
    'actions' => '<a href="'.route('admin.financial-plan-templates.create').'" class="btn btn-primary btn-sm"><i class="bx bx-plus"></i> '.__('financial_plans.create_template').'</a>',
])

@if (session('success'))
    <x-alert type="success" class="mb-3">{{ session('success') }}</x-alert>
@endif

<div class="panel-modern-card">
    <div class="card-body">
        <x-table :headers="[
            __('financial_plans.name'),
            __('financial_plans.credit_amount'),
            __('financial_plans.purchase_price'),
            __('financial_plans.discount_percent'),
            __('financial_plans.is_active'),
            __('app.actions'),
        ]">
            @forelse ($templates as $template)
                <tr>
                    <td>
                        <strong>{{ $template->name }}</strong>
                        @if ($template->description)
                            <div class="small text-muted">{{ $template->description }}</div>
                        @endif
                    </td>
                    <td>{{ format_toman($template->credit_amount) }}</td>
                    <td>{{ format_toman($template->purchase_price) }}</td>
                    <td>{{ persian_digits(number_format((float) $template->discount_percent, 2)) }}٪</td>
                    <td>
                        @if ($template->is_active)
                            <span class="badge bg-success">{{ __('financial_plans.status_active') }}</span>
                        @else
                            <span class="badge bg-secondary">غیرفعال</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('admin.financial-plan-templates.edit', $template) }}" class="btn btn-sm btn-outline-primary">{{ __('app.edit') }}</a>
                        <form method="POST" action="{{ route('admin.financial-plan-templates.destroy', $template) }}" class="d-inline" onsubmit="return confirm('{{ __('app.delete') }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('app.delete') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">{{ __('financial_plans.no_templates') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($templates->hasPages())
        <div class="card-foot">{{ $templates->links() }}</div>
    @endif
</div>
@endsection
