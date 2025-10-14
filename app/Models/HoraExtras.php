<?php

namespace App\Models;

use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HoraExtras extends Model
{
    use HasFactory;

    protected $fillable = [
        'idUsuario',
        'fecha',
        'horas_trabajadas',
        'estado',
    ];
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }

    protected static function booted()
    {
        static::addGlobalScope(new SedeScope);
    }
}
