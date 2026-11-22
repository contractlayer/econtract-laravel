<?php

use ContractLayer\EContract\Http\Controllers\EContractWebhookController;
use ContractLayer\EContract\Http\Middleware\VerifyEContractSignature;
use Illuminate\Support\Facades\Route;

// No "web" middleware group: a webhook has no session and no CSRF token
Route::post(config('econtract.webhook_path'), EContractWebhookController::class)
    ->middleware(VerifyEContractSignature::class)
    ->name('econtract.webhook');
