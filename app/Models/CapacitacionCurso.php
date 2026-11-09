<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CapacitacionCurso extends Model
{
    protected $table = 'capacitacion_cursos';

    public const ESTADO_BORRADOR = 'borrador';
    public const ESTADO_PUBLICADO = 'publicado';
    public const ESTADO_ARCHIVADO = 'archivado';

    protected $fillable = [
        'workspace_id',
        'created_by',
        'updated_by',
        'titulo',
        'slug',
        'subtitulo',
        'descripcion',
        'objetivos_json',
        'categoria',
        'nivel',
        'duracion_minutos',
        'portada_disk',
        'portada_path',
        'logo_constancia_path',
        'responsable_nombre',
        'responsable_cargo',
        'estado',
        'requiere_examen_aprobado',
        'porcentaje_minimo_video',
        'publicado_at',
        'archivado_at',
    ];

    protected $hidden = [
        'portada_disk',
        'portada_path',
        'logo_constancia_path',
    ];

    protected $appends = [
        'has_portada',
        'has_logo_constancia',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $curso) {
            if (empty($curso->slug) && ! empty($curso->titulo)) {
                $curso->slug = Str::slug($curso->titulo) ?: 'curso';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'objetivos_json' => 'array',
            'duracion_minutos' => 'integer',
            'requiere_examen_aprobado' => 'boolean',
            'porcentaje_minimo_video' => 'integer',
            'publicado_at' => 'datetime',
            'archivado_at' => 'datetime',
        ];
    }

    public function getHasPortadaAttribute(): bool
    {
        return ! empty($this->portada_path);
    }

    public function getHasLogoConstanciaAttribute(): bool
    {
        return ! empty($this->logo_constancia_path);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(CapacitacionModulo::class, 'curso_id')->orderBy('orden')->orderBy('id');
    }

    public function lecciones(): HasMany
    {
        return $this->hasMany(CapacitacionLeccion::class, 'curso_id')->orderBy('orden')->orderBy('id');
    }

    public function recursos(): HasMany
    {
        return $this->hasMany(CapacitacionRecurso::class, 'curso_id')->orderBy('orden')->orderBy('id');
    }

    public function examenes(): HasMany
    {
        return $this->hasMany(CapacitacionExamen::class, 'curso_id')->orderBy('orden')->orderBy('id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(CapacitacionAsignacion::class, 'curso_id');
    }

    public function intentosExamen(): HasMany
    {
        return $this->hasMany(CapacitacionIntentoExamen::class, 'curso_id');
    }

    public function constancias(): HasMany
    {
        return $this->hasMany(CapacitacionConstancia::class, 'curso_id');
    }
}
