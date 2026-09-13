<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Concerns\BuildsReportData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    use BuildsReportData;

    public function index(Request $request): View
    {
        return view('shared.reports.index', $this->buildReportData($request, $request->user()));
    }
}
