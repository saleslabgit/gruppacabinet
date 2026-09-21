<?php

namespace App\Support;

use App\Enums\GroupStatus;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupLifecycleService;
use Illuminate\Support\Collection;

class GroupPages
{
    public static function layout(string $title, bool $admin): array
    {
        return array_merge($admin ? PsychologistPages::layout($title) : PsychologistCabinetPages::layout($title), [
            'admin' => $admin, 'realGroups' => true,
            'links' => ['groups' => route('psychologist.home'), 'admin-groups' => route('admin.groups.index'), 'admin-group-form' => route('admin.groups.create')],
            'notice' => session()->has('success') ? ['tone' => 'success', 'text' => session('success')] : null,
            'errors' => session('errors') ? array_map(fn ($messages) => $messages[0], session('errors')->getBag('default')->messages()) : [],
        ]);
    }

    public static function listing(Collection $groups): Collection
    {
        $context = $groups->contains(fn (Group $group) => in_array($group->status, [GroupStatus::Active, GroupStatus::Expired], true))
            ? app(GroupLifecycleService::class)->presentationContext() : null;

        return $groups->map(fn (Group $group) => self::data($group, $context));
    }

    public static function data(Group $group, ?array $context = null): array
    {
        return $group->only(['id', 'public_uuid', 'owner_id', 'disabled', 'free', 'description', 'schedule', 'format_id', 'gender_id',
            'meeting_duration_minutes', 'participant_capacity', 'meeting_price', 'moderator_comment', 'rejection_reason', 'created_at', 'published_at', 'expires_at', 'placement_days']) + [
                'title' => $group->title ?: 'Новая группа', 'status' => $group->status->value,
                'format' => $group->format->name ?? 'Не указан', 'gender' => $group->gender->name ?? 'Не указан',
                'all_count' => (int) $group->all_count, 'new_count' => (int) $group->new_count, 'processed_count' => (int) $group->processed_count,
                'owner' => $group->relationLoaded('owner') && $group->owner ? PsychologistPages::profile($group->owner) : null,
            ] + app(GroupLifecycleService::class)->presentation($group, $context);
    }

    public static function detail(Group $group, bool $admin, bool $checkDeletion = true): array
    {
        $group->load(['format', 'gender', 'owner' => fn ($q) => $q->withTrashed(),
            'statusHistory' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'statusHistory.actor' => fn ($q) => $q->withTrashed()]);
        $data = self::layout('Группа', $admin);
        if ($group->exists) {
            $data['links']['admin-user'] = route('admin.psychologists.show', $group->owner_id);
        }

        $lastTransition = $group->statusHistory->last();

        return array_merge($data, ['group' => self::data($group), 'groupModel' => $group,
            'republication' => $group->status === GroupStatus::Approved && $lastTransition?->from_status === GroupStatus::Expired
                && $lastTransition->to_status === GroupStatus::Approved,
            'history' => $group->statusHistory, 'user' => $group->owner ? PsychologistPages::profile($group->owner) : null,
            'canDelete' => $checkDeletion && $group->exists && request()->user()->can('delete', $group)]);
    }

    public static function form(Group $group, bool $admin, bool $creating = false): array
    {
        $data = self::detail($group, $admin, false);
        $data['title'] = $creating ? 'Создать группу' : 'Редактировать группу';
        $data['creating'] = $creating;
        $data['variant'] = $group->status->value;
        $data['formAction'] = $admin
            ? ($creating ? route('admin.groups.store') : route('admin.groups.update', $group))
            : route('psychologist.groups.update', $group);
        $data['group']['title'] = $group->title;
        foreach (['format' => 'group_format', 'gender' => 'gender'] as $relation => $code) {
            $options = DictionaryItem::query()->whereHas('dictionary', fn ($q) => $q->where('code', $code))
                ->where('active', true)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all();
            /** @var DictionaryItem|null $current */
            $current = $group->$relation;
            if ($current && ! $current->active) {
                $options[$current->id] = $current->name.' (неактивен)';
            }
            $data[$relation.'Options'] = ['' => 'Выберите значение'] + $options;
        }
        $data['priceInput'] = $group->meeting_price === null ? '' : str_replace(' ', '', trim(MoneyFormatter::format($group->meeting_price, '')));
        $data['ownerOptions'] = $creating ? User::query()->where('admin', false)->where('disabled', false)->where('status', 'approved')
            ->orderBy('last_name')->orderBy('id')->get()->mapWithKeys(fn (User $user) => [$user->id => PsychologistPages::profile($user)['name'].' · '.$user->email])->all() : [];

        return $data;
    }
}
