<?php

use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\CRM\LeadNoteController;
use App\Http\Controllers\CRM\LeadTaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LeadController::class, 'index']);
Route::get('/leads', [LeadController::class, 'index']);
Route::get('/leads/{lead}', [LeadController::class, 'show']);

Route::post('/leads/{lead}/notes', [LeadNoteController::class, 'store']);
Route::post('/leads/{lead}/tasks', [LeadTaskController::class, 'store']);

Route::patch('/leads/{lead}/tasks/{task}/complete', [LeadTaskController::class, 'complete']);
Route::patch('/leads/{lead}/tasks/{task}/reopen', [LeadTaskController::class, 'reopen']);

Route::patch(
    '/leads/{lead}/registrations/{registration}/convert',
    [LeadController::class, 'markConverted']
);