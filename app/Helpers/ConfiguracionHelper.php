<?php

namespace App\Helpers;

use App\Models\Configuraciones;
use Illuminate\Support\Facades\Log;

class ConfiguracionHelper
{
    // Retorna toda la configuración activa para un nombre_config
    public static function get($nombreConfig, $idEmpresa = null)
    {
        $query = Configuraciones::where('nombre', $nombreConfig);

        if ($idEmpresa) {
            $query->where('idEmpresa', $idEmpresa);
        }

        return $query->first();
    }

    // Obtener estado (1 = activo, 0 = inactivo)
    public static function estado($nombreConfig, $idEmpresa = null)
    {
        $config = self::get($nombreConfig, $idEmpresa);
        return $config?->estado ?? 0; // Si no existe, devuelve 0 por defecto
    }


    // Obtener la clave secreta para un nombre_config
    public static function clave($nombreConfig, $idEmpresa = null)
    {
        $config = self::get($nombreConfig, $idEmpresa);
        return $config?->clave;
    }

    // Obtener valor1 para un nombre_config
    public static function valor1($nombreConfig, $idEmpresa = null)
    {
        $config = self::get($nombreConfig, $idEmpresa);
        return $config?->valor1;
    }

    // Igual para valor2, valor3
    public static function valor2($nombreConfig, $idEmpresa = null)
    {
        $config = self::get($nombreConfig, $idEmpresa);
        return $config?->valor2;
    }

    public static function valor3($nombreConfig, $idEmpresa = null)
    {
        $config = self::get($nombreConfig, $idEmpresa);
        return $config?->valor3;
    }
    public static function valor4($nombreConfig, $idEmpresa = null)
    {
        $config = self::get($nombreConfig, $idEmpresa);
        return $config?->valor4;
    }
    public static function guardarValorColumna($nombreConfig, $columna, $valor, $idEmpresa = null)
    {
        try {
            $config = self::get($nombreConfig, $idEmpresa);

            if ($config) {
                $config->{$columna} = $valor;
                $config->save();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error("Error al guardar $columna en $nombreConfig: " . $e->getMessage());
            return false;
        }
    }
    public static function crearOActualizarValor($nombreConfig, $columna, $valor, $idEmpresa = null)
    {
        try {
            // updateOrCreate buscará la fila y la actualizará, o la creará si no existe
            Configuraciones::updateOrCreate(
                [
                    'nombre' => $nombreConfig,
                    'idEmpresa' => $idEmpresa
                ],
                [
                    $columna => $valor
                ]
            );
            return true;
        } catch (\Exception $e) {
            Log::error("Error en crearOActualizarValor para $nombreConfig: " . $e->getMessage());
            return false;
        }
    }
}
