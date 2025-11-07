<?php

use Illuminate\Support\Facades\Route;
use Infofactory\StatamicAiTranslations\Controllers\ConfigController;

Route::prefix('ai-translations')->name('statamic-ai-translations.')->controller(ConfigController::class)->group(function () {
    Route::get('/config', 'edit')->name('config');
    Route::post('/config', 'update')->name('update-config');
});
