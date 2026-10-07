<?php

use Illuminate\Support\Facades\Route;
// هنا بنعرف لارافل على الـ Controller الجديد بتاعنا
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EhrApiController;
use App\Http\Controllers\ErpPharmacyApiController;
use App\Http\Controllers\OnlineConsultationApiController;
use App\Http\Controllers\SubscriptionController;

Route::view('/', 'home');
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::match(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'], '/Back End/EHR_System.php', [EhrApiController::class, 'handle']);
Route::match(['GET', 'POST', 'OPTIONS'], '/Back End/ERP_Pharmacy_System.php', [ErpPharmacyApiController::class, 'handle']);
Route::match(['GET', 'POST', 'OPTIONS'], '/Back End/Online_Consultation_API.php', [OnlineConsultationApiController::class, 'handle']);
Route::post('/Back End/subscribe.php', [SubscriptionController::class, 'subscribePharmacy']);
Route::post('/Back End/create_payment_intent.php', [SubscriptionController::class, 'createEhrPaymentIntent']);
Route::post('/Back End/subscribe_ehr.php', [SubscriptionController::class, 'subscribeEhr']);
Route::match(['POST', 'OPTIONS'], '/Back End/cancel_subscription.php', [SubscriptionController::class, 'cancelPharmacy']);
Route::match(['POST', 'OPTIONS'], '/Back End/cancel_subscription_ehr.php', [SubscriptionController::class, 'cancelEhr']);

$frontendPages = [
	'/Admin_Dashboard.html' => 'frontend.admin-dashboard',
	'/Appointments Page AR.html' => 'frontend.appointments-ar',
	'/Appointments Page.html' => 'frontend.appointments',
	'/Appointments_Page.html' => 'frontend.appointments-legacy',
	'/EHR_Page.html' => 'frontend.ehr',
	'/ERP Pharmacy Sign in Page AR.html' => 'frontend.pharmacy-sign-in-ar-legacy',
	'/ERP_Dashboard_AR.html' => 'frontend.pharmacy-dashboard-ar',
	'/ERP_Dashboard.html' => 'frontend.pharmacy-dashboard',
	'/ERP_Pharmacy_Sign_in_Page_AR.html' => 'frontend.pharmacy-sign-in-ar',
	'/ERP_Pharmacy_Sign_in_Page.html' => 'frontend.pharmacy-sign-in',
	'/ERP_POS_System_AR.html' => 'frontend.pharmacy-pos-ar',
	'/ERP_POS_System.html' => 'frontend.pharmacy-pos',
	'/Health_Chatbot.html' => 'frontend.chatbot',
	'/Home_Page.html' => 'home',
	'/Home_Page_AR.html' => 'frontend.home-ar',
	'/index.html' => 'frontend.index',
	'/Online_Consultation_Page.html' => 'frontend.consultations',
	'/Subscription.html' => 'frontend.subscription',
	'/Subscription_EHR.html' => 'frontend.subscription-ehr',
];

foreach ($frontendPages as $uri => $view) {
	Route::view($uri, $view);
}

$legacyPageAliases = [
	'/Home Page.html' => 'home',
	'/Home Page AR.html' => 'frontend.home-ar',
	'/ERP Dashboard.html' => 'frontend.pharmacy-dashboard',
	'/ERP Dashboard AR.html' => 'frontend.pharmacy-dashboard-ar',
	'/ERP Pharmacy Sign in Page.html' => 'frontend.pharmacy-sign-in',
	'/ERP Pharmacy Sign in Page AR.html' => 'frontend.pharmacy-sign-in-ar-legacy',
];

foreach ($legacyPageAliases as $uri => $view) {
	Route::view($uri, $view);
}
