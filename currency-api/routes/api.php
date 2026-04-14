<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CurrencyController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Get all supported currencies
    Route::get('/currencies', [CurrencyController::class, 'getCurrencies']);

    // Convert currency
    Route::post('/convert', [CurrencyController::class, 'convert']);

    // Get exchange rates for a base currency
    Route::get('/rates/{base}', [CurrencyController::class, 'getRates']);

    // Get historical rates (last 7 days)
    Route::get('/historical/{base}/{target}', [CurrencyController::class, 'getHistorical']);

    // Get multiple conversions at once
    Route::post('/convert/bulk', [CurrencyController::class, 'bulkConvert']);
});
