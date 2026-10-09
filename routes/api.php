<?php

use App\Http\Controllers\Api\AccessLinkController;
use App\Http\Controllers\Api\AtmController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SlaPolicyController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VersionController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('version', VersionController::class);
    // Enlace de acceso de un solo uso (crear contraseña): público, con el mismo límite que el inicio de sesión.
    Route::post('access/check', [AccessLinkController::class, 'check'])->middleware('throttle:login');
    Route::post('access/complete', [AccessLinkController::class, 'complete'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('me/password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');

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
        Route::get('me/summary', [TicketController::class, 'technicianSummary']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign']);
        Route::post('tickets/{ticket}/status', [TicketController::class, 'status']);
        Route::post('tickets/{ticket}/visits', [VisitController::class, 'store']);
        Route::post('tickets/{ticket}/confirm', [TicketController::class, 'confirm']);
        Route::post('tickets/{ticket}/reject', [TicketController::class, 'reject']);
        Route::patch('tickets/{ticket}', [TicketController::class, 'update']);
        Route::post('tickets/{ticket}/comments', [TicketController::class, 'comment']);
        Route::post('tickets/{ticket}/watch', [TicketController::class, 'watch']);
        Route::delete('tickets/{ticket}/watch', [TicketController::class, 'watch']);
        Route::get('sla-policies', [SlaPolicyController::class, 'index']);
        Route::get('visits/{visit}/constancia', [VisitController::class, 'constancia'])->name('visits.constancia');

        Route::middleware('role:admin,operator')->group(function () {
            Route::get('dashboard', DashboardController::class)->middleware('throttle:heavy');
            Route::get('atms', [AtmController::class, 'index']);
            Route::get('atms/lookup/{number}', [AtmController::class, 'lookup']);
            Route::get('reports/summary', [ReportController::class, 'summary'])->middleware('throttle:heavy');
            Route::get('reports/atms', [ReportController::class, 'atms'])->middleware('throttle:heavy');
            Route::get('reports/atms/{atm}', [ReportController::class, 'atm'])->middleware('throttle:heavy');
            Route::get('reports/technicians', [ReportController::class, 'technicians'])->middleware('throttle:heavy');
            Route::get('reports/faults', [ReportController::class, 'faults'])->middleware('throttle:heavy');
            Route::get('reports/detail', [ReportController::class, 'detail'])->middleware('throttle:heavy');
            Route::get('reports/parts', [ReportController::class, 'parts'])->middleware('throttle:heavy');
            Route::get('reports/consolidado', [ReportController::class, 'consolidado'])->middleware('throttle:heavy');
        });

        Route::middleware('role:admin')->group(function () {
            Route::post('atms', [AtmController::class, 'store']);
            Route::put('atms/{atm}', [AtmController::class, 'update']);
            Route::post('catalogs/{type}', [CatalogController::class, 'store']);
            Route::put('catalogs/{type}/{id}', [CatalogController::class, 'update']);
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/access-link', [AccessLinkController::class, 'create']);
            Route::post('import/excel', ImportController::class)->middleware('throttle:heavy');
            Route::put('sla-policies', [SlaPolicyController::class, 'update']);
            Route::get('audit', AuditController::class);
        });
    });
});
