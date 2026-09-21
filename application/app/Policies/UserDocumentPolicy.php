<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserDocument;

class UserDocumentPolicy
{
    public function manage(User $actor, UserDocument $document, User $psychologist): bool
    {
        return $actor->can('manage', $psychologist) && $document->user_id === $psychologist->id;
    }
}
