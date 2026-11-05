<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin'
    ];

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

    /**
     * Role names as reported to ediarum.portal (GET /api/auth/me, X-User-Roles). The only role
     * this app knows is "admin", from the is_admin flag.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->is_admin ? ['admin'] : [];
    }

    /**
     * Slugs of the projects the user is a member of, as reported to ediarum.portal and the apps
     * (GET /api/auth/me, X-User-Projects). Membership only -- admins get no extra slugs here.
     *
     * @return list<string>
     */
    public function projectSlugs(): array
    {
        return $this->projects()->orderBy('slug')->pluck('slug')->all();
    }

    public function projects():BelongsToMany{
        return $this->belongsToMany(Project::class)->withTimestamps();
    }
}
