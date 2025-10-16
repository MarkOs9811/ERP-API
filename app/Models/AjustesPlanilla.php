<?php

namespace App\Models;

use App\Models\Scopes\EmpresaScope;
use App\Models\Scopes\SedeScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AjustesPlanilla extends Model
{
    use HasFactory;
    protected static function booted()
    {
        static::addGlobalScope(new EmpresaScope);
    }
}
