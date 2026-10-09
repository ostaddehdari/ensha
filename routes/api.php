<?php

use App\Http\Controllers\WordPressApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/centres/{centre}')->middleware('throttle:30,1')->group(function(){
    Route::get('/availability',[WordPressApiController::class,'availability'])->name('api.wordpress.availability');
    Route::post('/appointments',[WordPressApiController::class,'book'])->middleware('throttle:10,1')->name('api.wordpress.book');
});
