<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CuentasContables extends Model
{
    use HasFactory;
    
    public function grupoCuenta()
    {
        return $this->belongsTo(GrupoCuenta::class, 'idGrupoCuenta');
    }
}
