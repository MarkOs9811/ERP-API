<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MiEmpresa extends Model
{
    use HasFactory;
    protected $fillable = [
        'nombre',
        'ruc',
        'logo',
        'numero',
        'correo',
        'direccion',
    ];


    protected $appends = ['logo_url'];

    /**
     * Genera el campo virtual 'logo_url'
     */
    public function getLogoUrlAttribute()
    {

        $rutaMuta = $this->getRawOriginal('logo');

        if ($rutaMuta) {
            // Ahora sí, armamos la URL con total seguridad de que no se duplicará
            return Storage::disk('s3')->url($rutaMuta);
        }

        return null;
    }

    public function usuarios()
    {
        return $this->hasMany(User::class, 'idEmpresa', 'id');
    }

    public function sedes()
    {
        return $this->hasMany(Sede::class, 'idEmpresa', 'id');
    }
    public function configuraciones()
    {
        return $this->hasMany(Configuraciones::class, 'idEmpresa', 'id');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'empresa_roles', 'idEmpresa', 'idRole')
            ->withPivot('estado')
            ->withTimestamps();
    }
}
