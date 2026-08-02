<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// This is an API-only application. Named so Laravel's default
// Authenticate::redirectTo() doesn't throw RouteNotFoundException when a
// client omits an Accept: application/json header on an unauthenticated
// request — it still resolves to a plain 401 JSON response either way.
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))->name('login');
