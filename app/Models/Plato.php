<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Plato extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio',
        'idCategoria', // Relación personalizada
        'foto',
        'estado',
    ];
    protected $appends = ['foto_url'];

    public function getFotoUrlAttribute()
    {
        if ($this->foto) {
            // Esto genera: https://pub-11a4bc2...r2.dev/fotosPlatos/mi_foto.jpg
            return Storage::disk('s3')->url($this->foto);
        }

        return null;
    }
    /**
     * Relación con la categoría de platos.
     */
    public function categoria()
    {
        return $this->belongsTo(CategoriaPlato::class, 'idCategoria');
    }
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }


    protected static function booted()
    {
        static::addGlobalScope(new SedeScope);
        static::addGlobalScope(new EmpresaScope);
        static::creating(function ($plato) {
            $user = auth()->user();

            if ($user) {
                if (empty($plato->idSede)) {
                    $plato->idSede = $user->idSede;
                }

                if (empty($plato->idEmpresa)) {
                    $plato->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
