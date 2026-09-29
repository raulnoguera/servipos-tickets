<?php

use Illuminate\Support\Facades\Route;

// La interfaz es una SPA (React); todas las rutas que no son de la API la cargan.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up|build|storage).*$')->name('app');
