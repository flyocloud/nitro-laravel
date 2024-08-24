<?php

use Illuminate\Support\Facades\Route;

Route::get('/foobar-weclome', function () {
    return view('welcome');
});
