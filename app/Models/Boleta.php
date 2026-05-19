<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Boleta extends Model
{
    use HasFactory;


    // 1. Unimos ambos campos en un solo array
    protected $appends = ['cdr_url', 'xml_url'];

    /**
     * Genera el campo virtual 'cdr_url'
     */
    public function getCdrUrlAttribute()
    {
        if ($this->rutaCdr) {
            return Storage::disk('s3')->url($this->rutaCdr);
        }

        return null;
    }

    /**
     * Genera el campo virtual 'xml_url'
     */
    public function getXmlUrlAttribute()
    {
        if ($this->rutaXml) {
            return Storage::disk('s3')->url($this->rutaXml);
        }

        return null;
    }
    public function venta()
    {
        return $this->belongsTo(Venta::class, 'idVenta');
    }
}
