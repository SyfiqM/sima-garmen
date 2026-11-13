<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'active' => EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Kesalahan aturan bisnis -> JSON seragam { message, code } dengan HTTP 400/403/404/409/422
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = in_array($e->getCode(), [400, 403, 404, 409, 422], true) ? $e->getCode() : 422;

                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->getCodeBisnis(),
                ], $status);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Terlalu banyak percobaan. Coba lagi sebentar lagi.'], 429);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Belum login atau token tidak valid.'], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk aksi ini.'], 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Data tidak ditemukan.'], 404);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Validasi gagal.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    })->create();