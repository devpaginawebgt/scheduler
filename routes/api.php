<?php

use App\Http\Controllers\Api\ChatwootBotController;
use App\Http\Controllers\Api\WhatsAppFlowController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/whatsapp/flows', [WhatsAppFlowController::class, 'handle'])
    ->middleware('verify.meta.signature');

Route::post('/chatwoot/webhook', [ChatwootBotController::class, 'handle'])
    ->middleware('verify.chatwoot.signature');
