<?php

namespace App\Models;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Configuraciones extends Model
{
    use HasFactory;

     protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
        
    }
}
