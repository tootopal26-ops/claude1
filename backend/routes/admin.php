<?php

use App\Livewire\Admin\Login as AdminLogin;
use App\Livewire\Admin\TournamentCreate;
use App\Livewire\Admin\TournamentEntries;
use App\Livewire\Admin\TournamentList;
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

    Route::middleware('auth:staff')->group(function () {
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
    });
});
