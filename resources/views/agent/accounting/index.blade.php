@extends('layouts.panel')

@section('page_title', __('menu.accounting'))

@section('panel_content')
@include('accounting._table', [
    'entries' => $entries,
    'eventLabels' => $eventLabels,
    'summary' => $summary,
    'totals' => $totals,
    'serverChangesRemaining' => $serverChangesRemaining ?? null,
])
@endsection
