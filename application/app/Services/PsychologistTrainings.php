<?php

namespace App\Services;

use App\Models\User;
use App\Support\TrainingData;
use Illuminate\Validation\ValidationException;

class PsychologistTrainings
{
    // Caller holds the user lock and owns the profile transaction.
    public function sync(User $user, array $submitted): void
    {
        $current = $user->trainings()->lockForUpdate()->get()->keyBy('id');
        $kept = [];
        foreach ($submitted as $index => $values) {
            $id = $values['id'] ?? null;
            if ($id !== null) {
                if (! $current->has($id) || in_array((int) $id, $kept, true)) {
                    throw ValidationException::withMessages(['trainings.'.$index.'.id' => 'Обучение не принадлежит этому психологу или повторяется.']);
                }
                $kept[] = (int) $id;
            }
        }
        // Free final positions before reordering; retained IDs keep certificate links.
        $temporaryPosition = (int) ($current->max('position') ?? -1) + 1;
        foreach ($current as $training) {
            if (in_array($training->id, $kept, true)) {
                $user->trainings()->whereKey($training->id)->update(['position' => $temporaryPosition]);
                $training->setAttribute('position', $temporaryPosition++);
                $training->syncOriginalAttribute('position');
            } else {
                $training->delete();
            }
        }
        foreach ($submitted as $position => $values) {
            $attributes = TrainingData::normalized($values) + ['position' => $position];
            if (isset($values['id'])) {
                $current[$values['id']]->update($attributes);
            } else {
                $user->trainings()->create($attributes);
            }
        }
    }
}
