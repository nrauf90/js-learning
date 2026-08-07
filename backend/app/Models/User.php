<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\CashEntry;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
     *
     * `role`, `shop_id` and `can_manage_products` are omitted for exactly the
     * same reason: they decide whether an account can reach the catalogue,
     * other people's takings, or another shop entirely. Use assignRole() and
     * setProductPermission() below.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
    ];

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SHOP_ADMIN = 'shop_admin';

    public const ROLE_STAFF = 'staff';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_SHOP_ADMIN, self::ROLE_STAFF];

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
            'can_manage_products' => 'boolean',
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

    /** The shop this account owns (shop admin) or works at (staff). */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** The shop this account owns, if it is a shop admin. */
    public function ownedShop(): HasOne
    {
        return $this->hasOne(Shop::class, 'owner_id');
    }

    public function staffAccounts(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id', 'shop_id');
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function isShopAdmin(): bool
    {
        return $this->role === self::ROLE_SHOP_ADMIN;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    /**
     * Staff are till-only unless their shop admin grants the catalogue. Shop
     * admins and platform admins always have it.
     */
    public function canManageCatalogue(): bool
    {
        return $this->isPlatformAdmin()
            || $this->isShopAdmin()
            || (bool) $this->can_manage_products;
    }

    /**
     * Which user's catalogue and takings this account operates on. Staff work
     * their shop owner's data, so the till shows the shop's products rather
     * than an empty catalogue of their own.
     */
    public function dataOwnerId(): int
    {
        if ($this->isStaff() && $this->shop_id) {
            return (int) ($this->shop?->owner_id ?: $this->id);
        }

        return (int) $this->id;
    }

    /** Privileged writes, kept out of mass assignment on purpose. */
    public function assignRole(string $role, ?int $shopId = null): void
    {
        $this->forceFill([
            'role' => $role,
            'shop_id' => $shopId ?? $this->shop_id,
        ])->save();
    }

    public function setProductPermission(bool $allowed): void
    {
        $this->forceFill(['can_manage_products' => $allowed])->save();
    }

    /**
     * The shape of a user returned from any auth endpoint (login/register/
     * /user/Google exchange). Deliberately omits `google_id` — it's an
     * internal linking id with no frontend use.
     *
     * @return array{id: int, name: string, email: string, avatar: ?string, is_admin: bool, role: string, shop_id: ?int, can_manage_products: bool}
     */
    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'is_admin' => (bool) $this->is_admin,
            'role' => $this->role ?? self::ROLE_SHOP_ADMIN,
            'shop_id' => $this->shop_id,
            'can_manage_products' => $this->canManageCatalogue(),
        ];
    }
}
