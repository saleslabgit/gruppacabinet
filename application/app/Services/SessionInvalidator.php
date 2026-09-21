<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionInvalidator
{
    public function invalidate(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
