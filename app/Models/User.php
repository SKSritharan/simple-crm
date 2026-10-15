<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const MONTHLY_AI_CREDITS = 30;

    public const AI_CREDIT_TIMEZONE = 'Asia/Colombo';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public static function currentAiCreditMonth(): string
    {
        return CarbonImmutable::now(self::AI_CREDIT_TIMEZONE)->format('Y-m');
    }

    public function remainingAiCredits(?string $month = null): int
    {
        $used = $this->ai_credit_month === ($month ?? self::currentAiCreditMonth())
            ? (int) $this->ai_credits_used : 0;

        return max(0, self::MONTHLY_AI_CREDITS - $used);
    }

    public function ownedCompanies(): HasMany
    {
        return $this->hasMany(Company::class, 'owner_id');
    }

    public function ownedContacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'owner_id');
    }

    public function ownedDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'owner_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to_id');
    }

    public function authoredNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'author_id');
    }

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
            'ai_credits_used' => 'integer',
        ];
    }
}
