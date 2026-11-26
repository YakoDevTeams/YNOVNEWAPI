<?php

namespace App\Models\Api\Ynov\parameter;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Pays extends Model
{
    use HasFactory;

    protected $table = 'pays';

    protected $primaryKey = 'uuid_pays';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'uuid_pays', 'codePays', 'MonLibelle', 'Indicatif', 'Flag', 'FlagUrl', 'created_at', 'updated_at'
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $pays) => $pays->uuid_pays ??= (string) Str::uuid());
    }

    public function villes()
    {
        return $this->hasMany(Ville::class, 'MonPays', 'codePays');
    }
}
