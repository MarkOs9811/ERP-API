<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrupoCuenta extends Model
{
    use HasFactory;
    public function cuentasContables()
    {
        return $this->hasMany(CuentasContables::class, 'idGrupoCuenta');
    }
}
