<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Route;
use RuntimeLens\Tests\Fixtures\App\Http\Controllers\FixtureController;
use RuntimeLens\Tests\Fixtures\App\Http\Middleware\PassThroughMiddleware;

Route::middleware(PassThroughMiddleware::class)->group(function (): void {
    Route::get('/clean', [FixtureController::class, 'clean']);
    Route::get('/repeated', [FixtureController::class, 'repeated']);
    Route::get('/in-lists', [FixtureController::class, 'inLists']);
    Route::get('/many-queries', [FixtureController::class, 'manyQueries']);
    Route::get('/huge-in-list', [FixtureController::class, 'hugeInList']);
    Route::get('/binary-binding', [FixtureController::class, 'binaryBinding']);
    Route::get('/view', [FixtureController::class, 'view']);
    Route::get('/http-call', [FixtureController::class, 'httpCall']);
    Route::get('/http-failure', [FixtureController::class, 'httpFailure']);
    Route::get('/boom', [FixtureController::class, 'boom']);
    Route::get('/not-found', [FixtureController::class, 'notFound']);
    Route::post('/graphql', [FixtureController::class, 'clean']);
    Route::get('/telescope/requests', [FixtureController::class, 'clean']);
    Route::get('/up', [FixtureController::class, 'clean']);
    Route::get('/closure', function (ConnectionInterface $db): string {
        $db->select('select 1');

        return 'ok';
    });
    Route::redirect('/vendor-redirect', '/clean');
});
