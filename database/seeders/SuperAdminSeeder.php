<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\Persona;
use App\Models\Empleado;
use App\Models\User;
use App\Models\Cargo;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        // 1. CREAR LA EMPRESA BASE
        $idNuevaEmpresa = DB::table('mi_empresas')->insertGetId([
            'nombre' => 'Restaurante Base (Configurar)',
            'ruc' => '00000000000',
            'correo' => 'marcosparitorres@gmail.com',
            'userAdmin' => 'marcosparitorres@gmail.com',
            'setup_steps' => '0',
            'estado' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. CREAR LA SEDE BASE ASOCIADA A LA NUEVA EMPRESA
        $idSedeBase = DB::table('sedes')->insertGetId([
            'idEmpresa' => $idNuevaEmpresa,
            'nombre' => 'Sede Principal',
            'direccion' => 'Dirección Principal / Por configurar',
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. REENLAZAR LOS CATÁLOGOS CONSERVADOS
        $tablasCatalogos = [
            'cargos',
            'categoria_platos',
            'categorias',
            'configuraciones',
            'cuentas_contables',
            'deducciones',
            'grupo_cuentas',
            'tipo_contratos',
            'unidad_medidas'
        ];

        foreach ($tablasCatalogos as $tabla) {
            DB::table($tabla)->update(['idEmpresa' => $idNuevaEmpresa]);
        }

        // 4. CREAR ÁREA BASE
        $areaBase = Area::firstOrCreate(
            ['idEmpresa' => $idNuevaEmpresa, 'nombre' => 'administracion'],
            [
                'estado' => 1
            ]
        );

        // 5. RECUPERAR O CREAR EL CARGO DE ADMINISTRADOR
        $cargoAdmin = Cargo::where('nombre', 'administrador')
            ->where('idEmpresa', $idNuevaEmpresa)
            ->first();

        if (!$cargoAdmin) {
            $cargoAdmin = Cargo::create([
                'idEmpresa' => $idNuevaEmpresa,
                'nombre' => 'administrador',
                'salario' => 3000,
                'pagoPorHoras' => 15.63,
                'estado' => 1
            ]);
        }

        // 6. CREAR PERSONA
        $persona = Persona::firstOrCreate(
            ['documento_identidad' => '00000000'],
            [
                'nombre' => 'Junior Leoncio',
                'apellidos' => 'Pari Torres',
                'estado' => 1
            ]
        );

        // 7. CREAR EMPLEADO ENLAZADO A LA NUEVA SEDE
        $empleado = Empleado::firstOrCreate(
            ['idPersona' => $persona->id],
            [
                'idEmpresa' => $idNuevaEmpresa,
                'idSede' => $idSedeBase,      // <-- Usamos el ID de la sede base recién creada
                'idArea' => $areaBase->id,
                'idCargo' => $cargoAdmin->id,
                'salario' => 3000.00,
                'estado' => 1
            ]
        );

        // 8. CREAR USUARIO
        User::firstOrCreate(
            ['email' => env('SUPERADMIN_EMAIL', 'marcosparitorres@gmail.com')],
            [
                'idEmpresa' => $idNuevaEmpresa,
                'idSede' => $idSedeBase,
                'idEmpleado' => $empleado->id,
                'password' => Hash::make('portuamor123'),
                'estado' => 1,
                'isAdmin' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}
