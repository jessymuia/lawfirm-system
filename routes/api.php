<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::resource("clients", \App\Http\Controllers\ClientController::class);
Route::get("/clientsDropDown", [\App\Http\Controllers\ClientController::class,'clientsDropDown']);
Route::get("/advocates", [\App\Http\Controllers\AttorneyController::class,'getAdvocateUsers']);

Route::resource("users",\App\Http\Controllers\UserController::class);
Route::get('/rolesDropDown', [\App\Http\Controllers\UserController::class,'getRolesDropdown']);

Route::resource("courttypes", \App\Http\Controllers\CourtTypeController::class);
Route::resource("courts", \App\Http\Controllers\CourtController::class);

Route::resource('cases', \App\Http\Controllers\CasesController::class);
Route::get("casesDropDown", [\App\Http\Controllers\CasesController::class,'dropDown']);

Route::resource("tasks", \App\Http\Controllers\TaskController::class);
Route::resource("expenses", \App\Http\Controllers\ExpenseController::class);
Route::resource('hearings',\App\Http\Controllers\HearingController::class);
