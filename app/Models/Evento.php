<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evento extends Model
{
    use HasFactory;
    protected $fillable = [
        'idUsuario',
        'google_event_id',
        'summary',
        'description',
        'start',
        'end',
        'attendees',
        'status',
        'html_link',
    ];
    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'attendees' => 'array'
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class);
    }
}
