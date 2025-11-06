<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificaciones;
use Illuminate\Http\Request;

class NotificacionesController extends Controller
{
    public function  getNotificaciones()
    {
        try {
            $notificaciones = Notificaciones::get();
            return response()->json(['success' => true, 'data' => $notificaciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'message' => 'Error', $e->getMessage()], 500);
        }
    }
}
