<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Scopes\SedeScope;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * @method \Laravel\Sanctum\NewAccessToken createToken(string $name, array $abilities = ['*'])
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;


    protected $fillable = [
        'idSede',
        'idEmpleado',
        'email',
        'correo',
        'password',
        'estadoIncidencia',
        'fotoPerfil',
        'latitud',
        'longitud',
        'auth_type',
        'google_spreadsheet_id',
    ];
    protected $appends = ['foto_url'];

    public function getFotoUrlAttribute()
    {
        if ($this->fotoPerfil) {
            // Esto genera: https://pub-11a4bc2...r2.dev/fotosPlatos/mi_foto.jpg
            return Storage::disk('s3')->url($this->fotoPerfil);
        }

        return null;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function empleado()
    {
        return $this->belongsTo(empleado::class, 'idEmpleado');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_users', 'idUsuarios', 'idRole');
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'idSede');
    }


    // ESTE METODO PERMITE NAVEGAR DESDE EMPLEADO A SUS ROLES USER
    public function rolesUsuarios()
    {
        return $this->hasMany(RoleUser::class, 'idUsuarios');
    }

    public function cajaAbierta()
    {
        return RegistrosCajas::with('caja')->where('idUsuario', $this->id)
            ->whereNull('fechaCierre')
            ->first();
    }
    protected static function booted()
    {
        static::addGlobalScope(new SedeScope);
        static::addGlobalScope(new EmpresaScope);

        static::creating(function ($venta) {
            $user = auth()->user();

            if ($user) {
                if (empty($venta->idSede)) {
                    $venta->idSede = $user->idSede;
                }

                if (empty($venta->idEmpresa)) {
                    $venta->idEmpresa = $user->idEmpresa;
                }
            }
        });
    }
}
