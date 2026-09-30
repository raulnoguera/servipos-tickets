<?php

use App\Http\Controllers\Api\AtmController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('catalogs', [CatalogController::class, 'all']);

        Route::get('push/config', [PushSubscriptionController::class, 'config']);
        Route::post('push/subscriptions', [PushSubscriptionController::class, 'store']);
        Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy']);
        Route::post('push/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:5,1');
        Route::put('me/notifications', [PushSubscriptionController::class, 'preferences']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread', [NotificationController::class, 'unread']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');

        // Tickets: el técnico solo ve y trabaja los suyos (TicketPolicy).
        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign']);
        Route::post('tickets/{ticket}/status', [TicketController::class, 'status']);
        Route::post('tickets/{ticket}/visits', [VisitController::class, 'store']);
        Route::get('visits/{visit}/constancia', [VisitController::class, 'constancia'])->name('visits.constancia');

        Route::middleware('role:admin,operator')->group(function () {
            Route::get('dashboard', DashboardController::class);
            Route::get('atms', [AtmController::class, 'index']);
            Route::get('atms/lookup/{number}', [AtmController::class, 'lookup']);
            Route::get('reports/parts', [ReportController::class, 'parts']);
            Route::get('reports/consolidado', [ReportController::class, 'consolidado']);
            Route::get('users', [UserController::class, 'index']);
        });

        Route::middleware('role:admin')->group(function () {
            Route::post('atms', [AtmController::class, 'store']);
            Route::put('atms/{atm}', [AtmController::class, 'update']);
            Route::post('catalogs/{type}', [CatalogController::class, 'store']);
            Route::put('catalogs/{type}/{id}', [CatalogController::class, 'update']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('import/excel', ImportController::class);
        });
    });
});
