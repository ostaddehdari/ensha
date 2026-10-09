<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentReceiptController extends Controller
{
    public function show(Request $request, PaymentReceipt $receipt): View
    {
        abort_unless($request->user()->hasPermission('payments.view'), 403);
        $receipt->load(['transaction.appointment.client.user', 'transaction.creator', 'issuer']);
        abort_unless($request->user()->isSuperAdmin() || (int) $receipt->transaction->centre_id === (int) $request->user()->centre_id, 403);
        Audit::record('مشاهده رسید مالی', $request, 'info', ['receipt' => $receipt->receipt_number], $receipt, 'payment.receipt.viewed');
        return view('finance.receipt', compact('receipt'));
    }
}
