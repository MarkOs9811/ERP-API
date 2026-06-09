<?php

namespace App\Helpers;

use App\Models\Configuraciones;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache; // 👈 IMPORTANTE: Agregamos la fachada Cache

class ConfiguracionHelper
{
    // Retorna toda la configuración activa para un nombre_config (OPTIMIZADO CON CACHÉ)
    public static function get($nombreConfig, $idEmpresa = null)
    {
        // 1. Creamos una llave única para el post-it. Ej: "config_sunat_empresa_1"
        $cacheKey = "config_{$nombreConfig}_empresa_{$idEmpresa}";

        // 2. recordamos el valor por 24 horas (86400 segundos). 
        // Si ya está en memoria RAM, ni siquiera ejecuta la consulta SQL.
        return Cache::remember($cacheKey, 86400, function () use ($nombreConfig, $idEmpresa) {
            $query = Configuraciones::where('nombre', $nombreConfig);

            if ($idEmpresa) {
                $query->where('idEmpresa', $idEmpresa);
            }

            return $query->first();
        });
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

    // ==========================================
    // SECCIÓN DE ESCRITURA (INVALIDACIÓN DE CACHÉ)
    // ==========================================

    public static function guardarValorColumna($nombreConfig, $columna, $valor, $idEmpresa = null)
    {
        try {
            // Buscamos en la BD para actualizar (el get trae de caché, pero no importa porque Laravel sabe actualizar el objeto)
            $config = Configuraciones::where('nombre', $nombreConfig)->where('idEmpresa', $idEmpresa)->first();

            if ($config) {
                $config->{$columna} = $valor;
                $config->save();

                // 🚨 LIMPIEZA DE CACHÉ: Como el valor cambió, borramos el "Post-it"
                $cacheKey = "config_{$nombreConfig}_empresa_{$idEmpresa}";
                Cache::forget($cacheKey);

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
            Configuraciones::updateOrCreate(
                [
                    'nombre' => $nombreConfig,
                    'idEmpresa' => $idEmpresa
                ],
                [
                    $columna => $valor
                ]
            );

            // 🚨 LIMPIEZA DE CACHÉ: Como el valor cambió o se creó, borramos el "Post-it"
            $cacheKey = "config_{$nombreConfig}_empresa_{$idEmpresa}";
            Cache::forget($cacheKey);

            return true;
        } catch (\Exception $e) {
            Log::error("Error en crearOActualizarValor para $nombreConfig: " . $e->getMessage());
            return false;
        }
    }
}
