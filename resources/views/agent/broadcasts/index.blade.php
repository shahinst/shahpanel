@extends('layouts.panel')

@section('page_title', __('broadcasts.page_title'))

@section('panel_content')
@include('partials.panel-page-hero', [
    'title' => __('broadcasts.page_title'),
    'subtitle' => __('ui.broadcasts_agent_subtitle'),
    'icon' => 'bx-broadcast',
    'actions' => '<a href="'.route('agent.broadcasts.create').'" class="btn btn-light btn-sm"><i class="bx bx-plus"></i> '.e(__('broadcasts.send_new')).'</a>',
])

<div class="panel-modern-card">
    <div class="card-head"><h3>{{ __('broadcasts.page_title') }}</h3></div>
    <div class="card-body">
        <x-table :headers="[__('broadcasts.title'), __('broadcasts.status'), __('broadcasts.date')]">
            @forelse ($broadcasts as $broadcast)
                <tr>
                    <td>{{ $broadcast->title }}</td>
                    <td>{{ $broadcast->status->label() }}</td>
                    <td>{{ jalali_date($broadcast->created_at) }}</td>
                </tr>
                @if ($broadcast->body)
                    <tr><td colspan="3"><small class="text-muted">{{ $broadcast->body }}</small></td></tr>
                @endif
            @empty
                <tr><td colspan="3" class="text-center text-muted py-4">{{ __('app.no_results') }}</td></tr>
            @endforelse
        </x-table>
    </div>
    @if ($broadcasts->hasPages())
        <div class="card-foot">{{ $broadcasts->links() }}</div>
    @endif
</div>
@endsection
