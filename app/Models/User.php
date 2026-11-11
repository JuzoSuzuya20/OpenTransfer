<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;


class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * admin, active, must_change_password and is_guest are deliberately not
     * fillable: they are set with forceFill() where an account is created or
     * edited by an admin, so no request payload can ever grant them.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verification_code',
        'email_verification_code_expires_at',
        'language',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    /**
     * The seed of the drawn avatar: derived from the identifier and the key of the application, so
     * that it reveals neither the account nor its address, and nobody chooses it.
     */
    protected $appends = ['avatar_seed'];

    public function getAvatarSeedAttribute(): ?string
    {
        if (!$this->getKey()) {
            return null;
        }

        return substr(hash_hmac('sha256', 'avatar:' . $this->getKey(), (string) config('app.key')), 0, 20);
    }

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'email_verification_code_expires_at',
        'totp_secret',
        'totp_last_timestep',
        'two_factor_recovery_codes',
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
            'email_verification_code_expires_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_enabled_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted',
        ];
    }

    public function passkeys()
    {
        return $this->hasMany(UserPasskey::class);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'admin' => $this->admin,
            'active' => $this->active,
            'must_change_password' => $this->must_change_password,
            'guest' => $this->is_guest == 1 ? true : false
        ];
    }

    public function invite()
    {
        return $this->hasOne(ReverseShareInvite::class, 'guest_user_id');
    }

    public function invites()
    {
        return $this->hasMany(ReverseShareInvite::class, 'user_id');
    }

    public function shares()
    {
        return $this->hasMany(Share::class);
    }

    public function getFirstNameAttribute()
    {
        return explode(' ', $this->name)[0];
    }

    /**
     * The authentication providers that this user has linked
     */
    public function authProviders()
    {
        return $this->belongsToMany(AuthProvider::class, 'user_auth_provider')
            ->withPivot(['provider_user_id', 'provider_email', 'access_token', 
                         'refresh_token', 'token_expires_at', 'provider_data'])
            ->withTimestamps();
    }
}
