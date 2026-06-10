<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PromocionesApp extends Model
{
    use HasFactory;
    protected $primaryKey = 'id';
    protected $fillable = [
        'idPlato',
        'precio',
        'titulo',
        'porcentaje_descuento',
        'precio_promocional',
        'imagen_banner',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];

    protected $appends = ['foto_promocion'];

    public function getFotoUrlAttribute()
    {
        if ($this->imagen_banner) {
            // Esto genera: https://pub-11a4bc2...r2.dev/fotosPlatos/mi_foto.jpg
            return Storage::disk('s3')->url($this->imagen_banner);
        }

        return null;
    }
    public function plato()
    {
        return $this->belongsTo(Plato::class, 'idPlato', 'id');
    }

    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        static::addGlobalScope(new SedeScope);

        static::creating(function ($promociones) {
            $user = auth()->user();

            if ($user) {
                if (empty($promociones->idSede)) {
                    $promociones->idSede = $user->idSede;
                }

                if (empty($promociones->idEmpresa)) {
                    $promociones->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
