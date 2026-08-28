<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(HandleCors::class);
        // Уведомления платёжного провайдера приходят без CSRF-токена
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'admin/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Отказ от `can:` — в общем для проекта виде {success, message}.
        // Стандартный ответ Laravel ({"message": ...}) выбивался бы из
        // контракта, на который смотрит серверная часть leget-main.
        //
        // Ловим Symfony-исключение, а не AuthorizationException: Handler::render
        // прогоняет prepareException ДО пользовательских колбэков, и к моменту
        // проверки AuthorizationException уже превращён в AccessDeniedHttpException.
        // Колбэк на исходный класс просто не срабатывает — молча, с дефолтным телом.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Недостаточно прав.',
            ], Response::HTTP_FORBIDDEN);
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('app:daily-billing')
            ->dailyAt('06:30')
            ->timezone('Europe/Moscow');
    })
    ->create();
