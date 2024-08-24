<?php

use App\Http\Controllers\TierController;
use Illuminate\Support\Facades\Route;

Route::get('/tier/{slug}', [TierController::class, 'show']);
