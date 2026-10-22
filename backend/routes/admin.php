<?php

use App\Http\Controllers\Admin\StorageAssetDownloadController;
use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\DepositList;
use App\Livewire\Admin\LeaderboardViewer;
use App\Livewire\Admin\LegalDocumentForm;
use App\Livewire\Admin\LegalDocumentList;
use App\Livewire\Admin\Login as AdminLogin;
use App\Livewire\Admin\NotificationCompose;
use App\Livewire\Admin\NotificationHistory;
use App\Livewire\Admin\PaymentSettings;
use App\Livewire\Admin\PlatformSettingsEditor;
use App\Livewire\Admin\PresetForm;
use App\Livewire\Admin\PresetList;
use App\Livewire\Admin\RiskAlertList;
use App\Livewire\Admin\StaffForm;
use App\Livewire\Admin\StaffList;
use App\Livewire\Admin\StaffSalaryRuleForm;
use App\Livewire\Admin\StaffSalaryRuleList;
use App\Livewire\Admin\StaffSalarySettlementList;
use App\Livewire\Admin\StaffSalarySettlementShow;
use App\Livewire\Admin\StorageDashboard;
use App\Livewire\Admin\TemplateForm;
use App\Livewire\Admin\TemplateList;
use App\Livewire\Admin\TournamentCreate;
use App\Livewire\Admin\TournamentEntries;
use App\Livewire\Admin\TournamentList;
use App\Livewire\Admin\TournamentResults;
use App\Livewire\Admin\UserList;
use App\Livewire\Admin\UserShow;
use App\Livewire\Admin\WithdrawalList;
use App\Livewire\Admin\WithdrawalShow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', AdminLogin::class)->name('login')->middleware('guest:staff');

    Route::post('/logout', function () {
        Auth::guard('staff')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->name('logout')->middleware('auth:staff');

    Route::middleware(['auth:staff', 'staff.security_version'])->group(function () {
        Route::redirect('/', '/admin/tournaments');

        Route::get('/tournaments', TournamentList::class)
            ->name('tournaments.index')
            ->middleware('permission:tournaments.view');

        Route::get('/tournaments/create', TournamentCreate::class)
            ->name('tournaments.create')
            ->middleware('permission:tournaments.manage');

        Route::get('/tournaments/{tournament}/entries', TournamentEntries::class)
            ->name('tournaments.entries')
            ->middleware('permission:tournaments.view');

        Route::get('/tournaments/{tournament}/results', TournamentResults::class)
            ->name('tournaments.results')
            ->middleware('permission:results.submit');

        Route::get('/storage-assets/{storageAsset}/download', StorageAssetDownloadController::class)
            ->name('storage-assets.download');

        Route::get('/notifications', NotificationHistory::class)
            ->name('notifications.index')
            ->middleware('permission:notifications.send');

        Route::get('/notifications/create', NotificationCompose::class)
            ->name('notifications.create')
            ->middleware('permission:notifications.send');

        Route::get('/presets', PresetList::class)
            ->name('presets.index')
            ->middleware('permission:staff.manage');

        Route::get('/presets/create', PresetForm::class)
            ->name('presets.create')
            ->middleware('permission:staff.manage');

        Route::get('/presets/{preset}/edit', PresetForm::class)
            ->name('presets.edit')
            ->middleware('permission:staff.manage');

        Route::get('/staff', StaffList::class)
            ->name('staff.index')
            ->middleware('permission:staff.manage');

        Route::get('/staff/create', StaffForm::class)
            ->name('staff.create')
            ->middleware('permission:staff.manage');

        Route::get('/staff/{staffUser}/edit', StaffForm::class)
            ->name('staff.edit')
            ->middleware('permission:staff.manage');

        Route::get('/audit-logs', AuditLogViewer::class)
            ->name('audit-logs.index')
            ->middleware('permission:audit_logs.view');

        Route::get('/risk-alerts', RiskAlertList::class)
            ->name('risk-alerts.index')
            ->middleware('permission:audit_logs.view');

        Route::get('/users', UserList::class)
            ->name('users.index')
            ->middleware('permission:users.view');

        Route::get('/users/{user}', UserShow::class)
            ->name('users.show')
            ->middleware('permission:users.view');

        Route::get('/templates', TemplateList::class)
            ->name('templates.index')
            ->middleware('permission:recurring_tournaments.manage');

        Route::get('/templates/create', TemplateForm::class)
            ->name('templates.create')
            ->middleware('permission:recurring_tournaments.manage');

        Route::get('/templates/{template}/edit', TemplateForm::class)
            ->name('templates.edit')
            ->middleware('permission:recurring_tournaments.manage');

        Route::get('/withdrawals', WithdrawalList::class)
            ->name('withdrawals.index')
            ->middleware('permission:withdrawals.manage');

        Route::get('/withdrawals/{withdrawal}', WithdrawalShow::class)
            ->name('withdrawals.show')
            ->middleware('permission:withdrawals.manage');

        Route::get('/deposits', DepositList::class)
            ->name('deposits.index')
            ->middleware('permission:payments.view');

        Route::get('/payment-settings', PaymentSettings::class)
            ->name('payment-settings')
            ->middleware('permission:payments.manage');

        Route::get('/leaderboards', LeaderboardViewer::class)
            ->name('leaderboards.index')
            ->middleware('permission:leaderboards.manage');

        Route::get('/staff-salary/rules', StaffSalaryRuleList::class)
            ->name('staff-salary.rules.index')
            ->middleware('permission:salary.manage');

        Route::get('/staff-salary/rules/create', StaffSalaryRuleForm::class)
            ->name('staff-salary.rules.create')
            ->middleware('permission:salary.manage');

        Route::get('/staff-salary/rules/{rule}/edit', StaffSalaryRuleForm::class)
            ->name('staff-salary.rules.edit')
            ->middleware('permission:salary.manage');

        Route::get('/staff-salary/settlements', StaffSalarySettlementList::class)
            ->name('staff-salary.settlements.index')
            ->middleware('permission:salary.view');

        Route::get('/staff-salary/settlements/{settlement}', StaffSalarySettlementShow::class)
            ->name('staff-salary.settlements.show')
            ->middleware('permission:salary.view');

        Route::get('/storage', StorageDashboard::class)
            ->name('storage.index')
            ->middleware('permission:storage.view');

        Route::get('/legal', LegalDocumentList::class)
            ->name('legal.index')
            ->middleware('permission:legal.manage');

        Route::get('/legal/create', LegalDocumentForm::class)
            ->name('legal.create')
            ->middleware('permission:legal.manage');

        Route::get('/settings', PlatformSettingsEditor::class)
            ->name('settings.index')
            ->middleware('permission:settings.manage');
    });
});
