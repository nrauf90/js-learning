<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\CashEntry;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * `is_admin` is deliberately NOT fillable — a privilege-escalation
     * footgun waiting to happen if a future controller ever mass-assigns
     * unfiltered request input. It's only ever set explicitly, e.g. via
     * `forceFill()` in AdminController::userUpdate() or the admin seeder.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'is_admin' => 'boolean',
        ];
    }

    public function cashEntries(): HasMany
    {
        return $this->hasMany(CashEntry::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subscriptionAddons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }

    /**
     * `paddle_customer_id` is deliberately NOT fillable, for the same reason as
     * `is_admin` — it is written only by the billing layer, never from request
     * input. Pointing a user row at someone else's Paddle customer would hand
     * them that customer's portal, invoices and payment methods.
     */
    public function setPaddleCustomerId(string $customerId): void
    {
        $this->forceFill(['paddle_customer_id' => $customerId])->save();
    }

    /**
     * The shape of a user returned from any auth endpoint (login/register/
     * /user/Google exchange). Deliberately omits `google_id` — it's an
     * internal linking id with no frontend use.
     *
     * @return array{id: int, name: string, email: string, avatar: ?string, is_admin: bool}
     */
    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'is_admin' => (bool) $this->is_admin,
        ];
    }
}
