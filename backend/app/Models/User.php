<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['username', 'mobile', 'email', 'date_of_birth', 'state', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'email_verified_at' => 'datetime',
            'is_banned' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (User $user) => Wallet::create(['user_id' => $user->id]));

        // Every player gets a unique referral code, regardless of whether the referral
        // system is currently enabled — it's a harmless account attribute, not itself a
        // reward trigger, and having it ready avoids a backfill migration if the feature
        // is switched on later. Deliberately NOT in #[Fillable]: it is only ever set here.
        static::creating(function (User $user) {
            $user->referral_code ??= self::generateUniqueReferralCode();
        });
    }

    private static function generateUniqueReferralCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'MINI'.strtoupper(Str::random(5));
            if (! self::where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        // Astronomically unlikely to ever run out after 5 tries at this keyspace size, but
        // fail loudly rather than silently save a colliding code if it ever did.
        throw new \RuntimeException('Could not generate a unique referral code.');
    }

    /**
     * @return HasMany<Referral, $this>
     */
    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }

    /**
     * @return HasOne<Referral, $this>
     */
    public function referralReceived(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /**
     * @return HasMany<TournamentEntry, $this>
     */
    public function tournamentEntries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    /**
     * @return HasMany<DevicePushToken, $this>
     */
    public function devicePushTokens(): HasMany
    {
        return $this->hasMany(DevicePushToken::class);
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    /**
     * @return HasOne<Wallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * @return HasMany<WithdrawalRequest, $this>
     */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    /**
     * @return HasMany<DepositOrder, $this>
     */
    public function depositOrders(): HasMany
    {
        return $this->hasMany(DepositOrder::class);
    }
}
