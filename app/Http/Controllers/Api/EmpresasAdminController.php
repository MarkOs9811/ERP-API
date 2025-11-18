<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MiEmpresa;
use Exception;
use Illuminate\Http\Request;

class EmpresasAdminController extends Controller
{
    public function getEmpresas()
    {
        try {
            $empresas = MiEmpresa::with('usuarios.empleado.persona', 'sedes', 'configuraciones')->get();
            return response()->json(['success' => true, 'data' => $empresas], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener las empresas', 'error' => $e->getMessage()], 500);
        }
    }
}
