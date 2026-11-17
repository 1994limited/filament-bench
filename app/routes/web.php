<?php

use Illuminate\Support\Facades\Route;

// A route that has nothing to do with Filament, to see what an installed panel costs every other request.
Route::get('/ping', fn () => response('pong', 200, ['Content-Type' => 'text/plain']));
