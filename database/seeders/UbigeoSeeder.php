<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class UbigeoSeeder extends Seeder
{
    public function run()
    {
        $path = database_path('seeders/ubigeo.sql');

        if (!File::exists($path)) {
            $this->command->error('No se encontró el archivo ubigeo.sql');
            return;
        }

        // 1. Ejecuta tu archivo SQL tal cual (crea y llena las tablas ubigeo_peru_...)
        DB::unprepared(File::get($path));

        // 2. Desactiva llaves foráneas y limpia tus tablas actuales para evitar choques
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('distritos')->truncate();
        DB::table('provincias')->truncate();
        DB::table('departamentos')->truncate();

        // 3. Migra los datos de las tablas temporales a TUS tablas (adaptando nombres y agregando estado/fechas)

        // Departamentos
        DB::statement("
            INSERT INTO departamentos (id, nombre, estado, created_at, updated_at)
            SELECT CAST(id AS UNSIGNED), name, 1, NOW(), NOW() 
            FROM ubigeo_peru_departments
        ");

        // Provincias
        DB::statement("
            INSERT INTO provincias (id, idDepartamento, nombre, estado, created_at, updated_at)
            SELECT CAST(id AS UNSIGNED), CAST(department_id AS UNSIGNED), name, 1, NOW(), NOW() 
            FROM ubigeo_peru_provinces
        ");

        // Distritos (Omitimos el department_id porque tu tabla solo usa idProvincia)
        DB::statement("
            INSERT INTO distritos (id, idProvincia, nombre, estado, created_at, updated_at)
            SELECT CAST(id AS UNSIGNED), CAST(province_id AS UNSIGNED), name, 1, NOW(), NOW() 
            FROM ubigeo_peru_districts
        ");

        // 4. Elimina las tablas temporales del INEI para dejar tu base de datos limpia
        DB::statement('DROP TABLE IF EXISTS ubigeo_peru_districts');
        DB::statement('DROP TABLE IF EXISTS ubigeo_peru_provinces');
        DB::statement('DROP TABLE IF EXISTS ubigeo_peru_departments');

        // Reactiva las llaves foráneas
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('¡Datos migrados correctamente a tus tablas de restaurante_db!');
    }
}
