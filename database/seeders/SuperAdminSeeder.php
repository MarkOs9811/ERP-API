<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\Persona;
use App\Models\Empleado;
use App\Models\User;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        // 1. CREAR LA EMPRESA BASE
        $idNuevaEmpresa = DB::table('mi_empresas')->insertGetId([
            'nombre' => 'Restaurante Base (Configurar)',
            'ruc' => '00000000000',
            'correo' => env('SUPERADMIN_EMAIL', 'marcosparitorres@gmail.com'),
            'userAdmin' => env('SUPERADMIN_EMAIL', 'marcosparitorres@gmail.com'),
            'setup_steps' => '0',
            'estado' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. CREAR LA SEDE BASE
        $idSedeBase = DB::table('sedes')->insertGetId([
            'idEmpresa' => $idNuevaEmpresa,
            'nombre' => 'Sede Principal',
            'direccion' => 'Dirección Principal / Por configurar',
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. REENLAZAR CATÁLOGOS CONSERVADOS (No incluimos 'cargos' ni 'roles')
        $tablasCatalogos = [
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
            ['estado' => 1]
        );

        // 5. RECREAR ROLES DESDE CERO Y CAPTURAR SUS IDs DINÁMICOS
        $rolesBase = [
            'usuarios',
            'ventas',
            'incidencias',
            'almacen',
            'vender',
            'proveedores',
            'compras',
            'RRHH',
            'finanzas',
            'areas y cargos',
            'platos',
            'delivery',
            'mis entregas',
            'clientes',
            'cocina'
        ];

        $idsRoles = []; // Array en memoria: ['ventas' => 2, 'cocina' => 16, ...]

        foreach ($rolesBase as $nombreRol) {
            $nuevoId = DB::table('roles')->insertGetId([
                'nombre' => $nombreRol,
                'estado' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $idsRoles[$nombreRol] = $nuevoId;
        }

        // 6. CREAR CARGOS Y ENLAZAR CON LOS NUEVOS IDs DE ROLES
        $cargosDefault = [
            [
                'nombre' => 'administrador',
                'salario' => 3000.0,
                'pagoPorHoras' => 15.63,
                'roles' => ['usuarios', 'ventas', 'incidencias', 'almacen', 'vender', 'proveedores', 'compras', 'RRHH', 'finanzas', 'areas y cargos', 'platos', 'delivery', 'clientes', 'cocina']
            ],
            [
                'nombre' => 'delivery',
                'salario' => 2100.0,
                'pagoPorHoras' => 10.94,
                'roles' => ['incidencias', 'delivery']
            ],
            [
                'nombre' => 'atencion al cliente',
                'salario' => 1500.0,
                'pagoPorHoras' => 7.81,
                'roles' => ['incidencias', 'vender']
            ],
            [
                'nombre' => 'conductor',
                'salario' => 1550.0,
                'pagoPorHoras' => 8.07,
                'roles' => ['incidencias', 'mis entregas']
            ],
            [
                'nombre' => 'mozo',
                'salario' => 1500.0,
                'pagoPorHoras' => 7.81,
                'roles' => ['ventas', 'incidencias', 'vender']
            ],
            [
                'nombre' => 'almacen',
                'salario' => 2000.0,
                'pagoPorHoras' => 10.42,
                'roles' => ['incidencias', 'almacen', 'proveedores', 'compras']
            ],
            [
                'nombre' => 'cocinero',
                'salario' => 2000.0,
                'pagoPorHoras' => 10.42,
                'roles' => ['incidencias', 'cocina']
            ],
        ];

        $cargoAdminId = null;

        foreach ($cargosDefault as $config) {
            $cargoId = DB::table('cargos')->insertGetId([
                'idEmpresa' => $idNuevaEmpresa,
                'nombre' => $config['nombre'],
                'salario' => $config['salario'],
                'pagoPorHoras' => $config['pagoPorHoras'],
                'estado' => 1
            ]);

            if ($config['nombre'] === 'administrador') {
                $cargoAdminId = $cargoId;
            }

            // Armar las inserciones masivas para la tabla pivot usando el array de IDs en memoria
            $rolesData = array_map(function ($nombreRol) use ($cargoId, $idsRoles) {
                return [
                    'idCargo' => $cargoId,
                    'idRole' => $idsRoles[$nombreRol], // <- Obtenemos el ID exacto y real
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $config['roles']);

            DB::table('cargo_roles')->insert($rolesData);
        }

        // 7. CREAR PERSONA
        $persona = Persona::firstOrCreate(
            ['documento_identidad' => '00000000'],
            [
                'nombre' => 'Junior Leoncio',
                'apellidos' => 'Pari Torres',
                'estado' => 1
            ]
        );

        // 8. CREAR EMPLEADO ENLAZADO
        $empleado = Empleado::firstOrCreate(
            ['idPersona' => $persona->id],
            [
                'idEmpresa' => $idNuevaEmpresa,
                'idSede' => $idSedeBase,
                'idArea' => $areaBase->id,
                'idCargo' => $cargoAdminId,
                'salario' => 3000.00,
                'estado' => 1
            ]
        );

        // 9. CREAR USUARIO SUPERADMIN
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
