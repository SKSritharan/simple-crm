<?php

use App\Http\Controllers\CrmAppController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/admin/crm-app', CrmAppController::class)
    ->middleware(['panel:admin', Authenticate::class])->name('crm.app');

Route::get('/', function () {
    return view('welcome');
});
