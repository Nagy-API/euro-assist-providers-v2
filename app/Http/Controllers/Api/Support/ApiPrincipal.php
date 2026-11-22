<?php

namespace App\Http\Controllers\Api\Support;

use App\Models\User;
use Laravel\Sanctum\HasApiTokens;

/**
 * The account a personal access token authenticates as.
 *
 * Sanctum only accepts tokenables that use its HasApiTokens trait. App\Models\User is owned by
 * the user-management area, so API tokens are issued to this thin subclass of the same `users`
 * table instead. It adds nothing but token support: role, activity and permissions are inherited.
 * (If HasApiTokens is ever added to User directly, this class can be deleted.)
 */
class ApiPrincipal extends User
{
    use HasApiTokens;

    protected $table = 'users';
}
