<?php

use App\Http\Controllers\FrontController;
use App\Http\Controllers\MemberController;
use App\Http\Middleware\MemberArea;
use Illuminate\Support\Facades\Route;

// The public website owners book on. The shop's own system stays at /admin.
Route::controller(FrontController::class)->name('front.')->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/booking', 'booking')->name('booking');
    Route::get('/privacy', 'privacy')->name('privacy');
    Route::get('/my-booking', 'myBooking')->name('my-booking');
    Route::post('/my-booking', 'findBooking')->middleware('throttle:10,1')->name('my-booking.find');
});

// The member area: owners sign in with their phone to see their pets, visits and packages.
Route::controller(MemberController::class)->prefix('member')->name('front.member.')->group(function () {
    Route::get('/login', 'showLogin')->name('login');
    Route::post('/login', 'login')->middleware('throttle:6,1')->name('login.attempt');
    Route::get('/activate', 'showActivate')->name('activate');
    Route::post('/activate', 'activate')->middleware('throttle:6,1')->name('activate.attempt');
    Route::post('/logout', 'logout')->name('logout');

    Route::middleware(MemberArea::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/history', 'history')->name('history');
        Route::get('/profile', 'profile')->name('profile');
        Route::put('/profile', 'updateProfile')->name('profile.update');
        Route::put('/password', 'updatePassword')->name('password.update');
        Route::post('/appointments/{appointment}/cancel', 'cancel')->name('cancel');
    });
});
