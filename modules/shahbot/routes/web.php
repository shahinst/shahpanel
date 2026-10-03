<?php

use App\Http\Middleware\RestrictAdminByIp;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Route;
use Modules\ShahBot\Http\Controllers\Admin\AgentController;
use Modules\ShahBot\Http\Controllers\Admin\BroadcastController;
use Modules\ShahBot\Http\Controllers\Admin\CodeController;
use Modules\ShahBot\Http\Controllers\Admin\DashboardController;
use Modules\ShahBot\Http\Controllers\Admin\EditorController;
use Modules\ShahBot\Http\Controllers\Admin\LotteryController;
use Modules\ShahBot\Http\Controllers\Admin\PaymentController;
use Modules\ShahBot\Http\Controllers\Admin\RefundController;
use Modules\ShahBot\Http\Controllers\Admin\SettingsController;
use Modules\ShahBot\Http\Controllers\Admin\TicketController;
use Modules\ShahBot\Http\Controllers\Admin\TutorialController;
use Modules\ShahBot\Http\Controllers\Admin\UserController;
use Modules\ShahBot\Http\Controllers\Panel\MyBotController;

/*
| The bot's admin section. The admin group is rebuilt exactly as the core
| routes/web.php builds it (and as the tunneling module does), so the section
| gate, the IP allowlist and the activity log apply here too.
*/

$adminMiddleware = ['auth', 'role:admin', 'log.activity'];
if (class_exists(RestrictAdminByIp::class)) {
    $adminMiddleware[] = 'admin.ip';
}
$adminMiddleware[] = 'admin.section';

Route::prefix(PortalPaths::slug('admin'))->name('admin.')->middleware($adminMiddleware)->group(function (): void {
    Route::prefix('shahbot')->name('shahbot.')->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
        Route::get('orders', [DashboardController::class, 'orders'])->name('orders');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/connect', [SettingsController::class, 'connect'])->name('settings.connect');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{botUser}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{botUser}/block', [UserController::class, 'toggleBlock'])->name('users.block');
        Route::post('users/{botUser}/message', [UserController::class, 'message'])->name('users.message');
        Route::post('users/{botUser}/wallet', [UserController::class, 'wallet'])->name('users.wallet');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
        Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->name('payments.approve');
        Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');

        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::post('refunds/{refund}/approve', [RefundController::class, 'approve'])->name('refunds.approve');
        Route::post('refunds/{refund}/reject', [RefundController::class, 'reject'])->name('refunds.reject');

        Route::get('codes', [CodeController::class, 'index'])->name('codes.index');
        Route::post('codes', [CodeController::class, 'store'])->name('codes.store');
        Route::post('codes/{code}/toggle', [CodeController::class, 'toggle'])->name('codes.toggle');
        Route::delete('codes/{code}', [CodeController::class, 'destroy'])->name('codes.destroy');

        Route::get('broadcasts', [BroadcastController::class, 'index'])->name('broadcasts.index');
        Route::post('broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
        Route::post('broadcasts/{broadcast}/cancel', [BroadcastController::class, 'cancel'])->name('broadcasts.cancel');

        Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::post('agents/requests/{agencyRequest}/approve', [AgentController::class, 'approve'])->name('agents.approve');
        Route::post('agents/requests/{agencyRequest}/reject', [AgentController::class, 'reject'])->name('agents.reject');
        Route::post('agents/bots/{bot}/toggle', [AgentController::class, 'toggleBot'])->name('agents.bots.toggle');

        Route::get('lotteries', [LotteryController::class, 'index'])->name('lotteries.index');
        Route::post('lotteries', [LotteryController::class, 'store'])->name('lotteries.store');
        Route::post('lotteries/{lottery}/cancel', [LotteryController::class, 'cancel'])->name('lotteries.cancel');
        Route::post('lotteries/{lottery}/draw', [LotteryController::class, 'draw'])->name('lotteries.draw');

        Route::get('editor', [EditorController::class, 'edit'])->name('editor');
        Route::post('editor/texts', [EditorController::class, 'updateTexts'])->name('editor.texts');
        Route::post('editor/keyboard', [EditorController::class, 'updateKeyboard'])->name('editor.keyboard');

        Route::get('tutorials', [TutorialController::class, 'index'])->name('tutorials.index');
        Route::post('tutorials', [TutorialController::class, 'store'])->name('tutorials.store');
        Route::put('tutorials/{tutorial}', [TutorialController::class, 'update'])->name('tutorials.update');
        Route::delete('tutorials/{tutorial}', [TutorialController::class, 'destroy'])->name('tutorials.destroy');
    });
});

// "My sales bot" for agents and sellers, rebuilt on their panels' groups.
foreach (['agent', 'seller'] as $role) {
    Route::prefix(PortalPaths::slug($role))->name($role.'.')->middleware(['auth', 'role:'.$role, 'log.activity'])->group(function (): void {
        Route::get('shahbot', [MyBotController::class, 'edit'])->name('shahbot.my-bot');
        Route::post('shahbot', [MyBotController::class, 'update'])->name('shahbot.my-bot.update');
        Route::post('shahbot/connect', [MyBotController::class, 'connect'])->name('shahbot.my-bot.connect');
        Route::get('shahbot/plans', [MyBotController::class, 'plans'])->name('shahbot.my-bot.plans');
        Route::post('shahbot/plans', [MyBotController::class, 'savePlans'])->name('shahbot.my-bot.plans.save');
        Route::post('shahbot/broadcast', [MyBotController::class, 'broadcast'])->name('shahbot.my-bot.broadcast');
    });
}
