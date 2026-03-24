<?php

use App\Http\Controllers\Api\Kine\FileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/progress-reports/download/{token}', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'downloadPdf'])
                        ->name('kine.progress-reports.download');

Route::get('/files/{path}', [FileController::class, 'serve'])
->where('path', '.*')
->name('files.serve');
