<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable
{
    use HasApiTokens,HasFactory, HasRoles, Notifiable, \OwenIt\Auditing\Auditable,SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /**
     * SEC-7: this list previously also included created_at, updated_at,
     * deleted_at, created_by, and updated_by — none of which should ever
     * be settable from request input (they'd let a client spoof audit
     * timestamps or soft-delete/restore a user via a plain update()
     * payload). Eloquent already manages created_at/updated_at itself.
     *
     * Unlike every other model in this app, `created_by`/`updated_by`/
     * `deleted_by` are deliberately NOT auto-stamped for User (see
     * booted() below) — the `users` table (see its migration) was never
     * given those columns the way every other table got them via
     * Utils::createDefaultTableColumns(). This app's audit trail for user
     * records comes from the `audits` table (OwenIt\Auditing) instead.
     *
     * database/migrations/*.php and app/Console/Commands/MigrationImport.php
     * write to this table via the query builder (DB::table(...)->insert()),
     * not through this model, so tightening $fillable here doesn't affect
     * migration import/export or the original seed migrations.
     */
    protected $fillable = [
        'timekeeper_class',   // FUN-13
        'name',
        'email',
        'password',
        'phone_number',
        'department',
        'hire_date',
        'office_id',
        'practice_group_id',
    ];

    protected static function booted(): void
    {
        // SEC-7 auto-stamps created_by on every model *except* this one:
        // the users table (see its migration) never got created_by/
        // updated_by/deleted_by columns the way every other table did via
        // Utils::createDefaultTableColumns() — those three columns simply
        // don't exist on `users`. Setting them here (or in
        // UserController::update()) throws a real SQL error the moment an
        // authenticated request creates or updates a user. Nothing to
        // auto-stamp here; audit history for user records comes from the
        // `audits` table (OwenIt\Auditing, already applied below) instead.
    }

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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Alias for Spatie\Permission\Traits\HasRoles::hasPermissionTo().
     *
     * Every controller in this app calls Auth::user()->checkPermissionTo(...),
     * but Spatie's package only ever defined hasPermissionTo() — there is
     * no checkPermissionTo() anywhere in this codebase or in the vendor
     * package. Every one of those ~90 call sites was throwing a fatal
     * "Call to undefined method" error on every single permission check,
     * for every request, which the global exception handler then surfaced
     * as an unhelpful 500 instead of the intended 401/403/200. Renaming
     * ~90 call sites across ~25 controllers risks a typo somewhere; this
     * single alias fixes every call site at once with no other files
     * touched.
     */
    public function checkPermissionTo(string $permission): bool
    {
        // ENG-11: Spatie's Gate::before hook asks this for every ability, including policy abilities ("viewAny",
        // "delete") that are not permission names. An unknown name is simply "not held" (deny), never an error.
        try {
            return $this->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * FUN-18
     *
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * FUN-18
     *
     * @return BelongsTo<PracticeGroup, $this>
     */
    public function practiceGroup(): BelongsTo
    {
        return $this->belongsTo(PracticeGroup::class);
    }
}
