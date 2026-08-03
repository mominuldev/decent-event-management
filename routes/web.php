<?php

use Illuminate\Support\Facades\Route;

// The React SPA owns client-side routing; every non-API/webhook/scanner
// path renders the shell so React Router can take over. `/login` is a
// named route in its own right (Laravel's default Authenticate::redirectTo()
// resolves route('login') for guests who omit an Accept: application/json
// header) but otherwise renders the same shell.
Route::view('/login', 'app')->name('login');

Route::view('/{any?}', 'app')->where('any', '^(?!api|webhooks|scanner).*$');
