<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DocumentosFirmados extends Model
{
    use HasFactory;

    protected $fillable = [
        'idUsuario',
        'nombre_archivo',
        'ruta_archivo',
    ];

    protected $appends = ['documento_url'];

    /**
     * Accessor para obtener la URL completa desde Cloudflare R2 / S3
     */
    public function getDocumentoUrlAttribute()
    {
        if ($this->ruta_archivo) {
            // Esto generará la ruta directa de Cloudflare R2 usando el Driver S3
            // Ejemplo: https://pub-11a4bc2...r2.dev/pdfs/solicitud_123.pdf
            return Storage::disk('s3')->url($this->ruta_archivo);
        }

        return null;
    }
    public function usuario()
    {
        return $this->belongsTo(User::class, 'idUsuario');
    }
}
