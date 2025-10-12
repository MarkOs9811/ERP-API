<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasFactory;
    use HasFactory;
    protected $fillable = [
        'idPersona',
        'idArea',
        'idCargo',
        'idHorario',
        'idContrato',
        'fecha_contrato',
        'fecha_fin_contrato',
        'salario',
        'estado',
    ];
    public function cargo()
    {
        return $this->belongsTo(Cargo::class, 'idCargo', 'id');
    }
    public function contrato()
    {
        return $this->belongsTo(TipoContrato::class, 'idContrato', 'id');
    }


    public function horario()
    {
        return $this->belongsTo(Horario::class, 'idHorario', 'id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'idArea', 'id');
    }

    public function persona()
    {
        return $this->belongsTo(Persona::class, 'idPersona', 'id');
    }

    public function asistencias()
    {
        return $this->hasManyThrough(Asistencia::class, Persona::class, 'id', 'codigoUsuario', 'idPersona', 'documento_identidad');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'idEmpleado');
    }

    public function deducciones()
    {
        return $this->belongsToMany(Deduccione::class, 'empleado_deducciones', 'idEmpleado', 'idDeduccion');
    }

    public function bonificaciones()
    {
        return $this->belongsToMany(Bonificacione::class, 'empleado_bonificaciones', 'idEmpleado', 'idBonificaciones');
    }

    public function usuario()
    {
        return $this->hasOne(User::class, 'idEmpleado', 'id');
    }
}
