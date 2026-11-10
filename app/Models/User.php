<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\TamanioTexto;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property UserRole $role
 * @property ApprovalStatus $approval_status
 * @property Carbon|null $approved_at
 * @property int|null $daily_quota
 * @property TamanioTexto $tamanio_texto
 * @property string|null $terminos_version
 * @property Carbon|null $terminos_aceptados_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    // Sin esto, un usuario recién creado tiene el tamaño en null hasta refrescarlo.
    protected $attributes = [
        'tamanio_texto' => TamanioTexto::PORDEFECTO,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => UserRole::class,
            'approval_status' => ApprovalStatus::class,
            'approved_at' => 'datetime',
            'daily_quota' => 'integer',
            'tamanio_texto' => TamanioTexto::class,
            'terminos_aceptados_en' => 'datetime',
        ];
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function admins(Builder $query): void
    {
        $query->where('role', UserRole::Admin->value);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * El admin siempre puede usar la app; el resto, solo si fue aprobado.
     */
    public function isApproved(): bool
    {
        return $this->isAdmin() || $this->approval_status === ApprovalStatus::Approved;
    }

    public function approve(): void
    {
        $this->forceFill([
            'approval_status' => ApprovalStatus::Approved,
            'approved_at' => now(),
        ])->save();
    }

    public function reject(): void
    {
        $this->forceFill([
            'approval_status' => ApprovalStatus::Rejected,
            'approved_at' => null,
        ])->save();
    }

    /**
     * Imágenes por día que puede generar. null = sin límite (admin).
     */
    /** @return HasMany<Generation, $this> */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /** @return HasMany<Image, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function effectiveDailyQuota(): ?int
    {
        if ($this->isAdmin()) {
            return null;
        }

        return $this->daily_quota ?? AppSetting::defaultDailyQuota();
    }

    public function aceptoTerminosVigentes(): bool
    {
        return $this->terminos_version !== null
            && $this->terminos_version === config('legales.version');
    }

    public function aceptarTerminos(): void
    {
        $this->forceFill([
            'terminos_version' => config('legales.version'),
            'terminos_aceptados_en' => now(),
        ])->save();
    }

    protected static function booted(): void
    {
        // Eliminar la cuenta borra de verdad: las filas caen en cascada, pero
        // los archivos y las sesiones abiertas hay que borrarlos a mano.
        static::deleting(function (User $user): void {
            Storage::disk('local')->deleteDirectory("images/{$user->id}");
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
