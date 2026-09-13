<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $transactions = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('seller.transactions.index', compact('transactions'));
    }
}
