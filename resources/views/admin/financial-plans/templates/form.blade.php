@extends('layouts.panel')

@section('page_title', $template->exists ? __('financial_plans.edit_template') : __('financial_plans.create_template'))

@section('panel_content')
<x-card>
    <form method="POST" action="{{ $template->exists ? route('admin.financial-plan-templates.update', $template) : route('admin.financial-plan-templates.store') }}">
        @csrf
        @if ($template->exists)
            @method('PUT')
        @endif
        <div class="row">
            <x-form.group :label="__('financial_plans.name')" class="col-md-6">
                <input type="text" name="name" class="form-control" required value="{{ old('name', $template->name) }}">
            </x-form.group>
            <x-form.group :label="__('financial_plans.sort_order')" class="col-md-6">
                <input type="number" name="sort_order" min="0" class="form-control" value="{{ old('sort_order', $template->sort_order ?? 0) }}">
            </x-form.group>
            <x-form.group :label="__('financial_plans.credit_amount')" class="col-md-4">
                <input type="number" name="credit_amount" step="1000" min="1" class="form-control" required value="{{ old('credit_amount', $template->credit_amount) }}">
                <div class="form-text">{{ __('financial_plans.credit_help') }}</div>
            </x-form.group>
            <x-form.group :label="__('financial_plans.purchase_price')" class="col-md-4">
                <input type="number" name="purchase_price" step="1000" min="1" class="form-control" required value="{{ old('purchase_price', $template->purchase_price) }}">
                <div class="form-text">{{ __('financial_plans.price_help') }}</div>
            </x-form.group>
            <x-form.group :label="__('financial_plans.discount_percent')" class="col-md-4">
                <div class="input-group">
                    <input type="number" name="discount_percent" step="0.01" min="0" max="100" class="form-control" required value="{{ old('discount_percent', $template->discount_percent ?? 0) }}">
                    <span class="input-group-text">%</span>
                </div>
                <div class="form-text">{{ __('financial_plans.discount_help') }}</div>
            </x-form.group>
            <x-form.group :label="__('financial_plans.description')" class="col-12">
                <textarea name="description" rows="3" class="form-control">{{ old('description', $template->description) }}</textarea>
            </x-form.group>
            <div class="col-12 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $template->is_active ?? true))>
                    <label class="form-check-label" for="is_active">{{ __('financial_plans.is_active') }}</label>
                </div>
            </div>
            <x-form.actions>
                <x-button type="submit">{{ __('app.save') }}</x-button>
                <a href="{{ route('admin.financial-plan-templates.index') }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
            </x-form.actions>
        </div>
    </form>
</x-card>
@endsection
