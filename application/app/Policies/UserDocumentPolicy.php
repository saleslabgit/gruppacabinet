<?php

namespace App\Policies;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserDocument;

class UserDocumentPolicy
{
    public function viewOwn(User $actor, UserDocument $document): bool
    {
        return ! $actor->admin && ! $actor->disabled && ! $actor->trashed()
            && $actor->status === UserStatus::Approved && $document->user_id === $actor->id;
    }

    public function manage(User $actor, UserDocument $document, User $psychologist): bool
    {
        return $actor->can('manage', $psychologist) && $document->user_id === $psychologist->id;
    }
}
