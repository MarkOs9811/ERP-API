<?php

use App\Services\OpenAIService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/test-openai', function () {
    try {
        $openAI = new OpenAIService();
        return response()->json(['success' => true, 'message' => 'Servicio OpenAI cargado correctamente']);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'error' => $e->getMessage()]);
    }
});
Route::get('/', function () {
    return view('welcome');
});
