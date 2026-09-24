<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property string $password
 */
#[Fillable(['name', 'email', 'role', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    /**
     * Check if user is an Administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is a standard User.
     */
    public function isUser(): bool
    {
        return in_array($this->role, ['user', 'trader', 'viewer'], true);
    }

    /**
     * Check if user is authorized to execute trades.
     */
    public function isTrader(): bool
    {
        return $this->isAdmin() || $this->hasPermission('manage_trading');
    }

    /**
     * Check if user is a read-only Viewer.
     */
    public function isViewer(): bool
    {
        return ! $this->isAdmin();
    }

    /**
     * Check if user has a specific permission slug.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return DB::table('role_permissions')
            ->where('role', $this->role)
            ->where('permission_slug', $permissionSlug)
            ->exists();
    }
}
