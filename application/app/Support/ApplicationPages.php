<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApplicationPages
{
    public static function layout(bool $admin, ?Group $group = null): array
    {
        return array_replace($admin ? PsychologistPages::layout('Заявки') : PsychologistCabinetPages::layout('Заявки группы'), [
            'admin' => $admin, 'realApplications' => true,
            'links' => [
                'admin-applications' => route('admin.applications.index'),
                'applications' => $group ? route('psychologist.groups.applications.index', $group) : null,
            ],
            'group' => $group ? ['title' => $group->title ?: 'Новая группа', 'new_count' => $group->new_count,
                'processed_count' => $group->processed_count, 'all_count' => $group->all_count] : null,
            'notice' => session()->has('success') ? ['tone' => 'success', 'text' => session('success')] : null,
        ]);
    }

    public static function data(GroupApplication $application, bool $admin): array
    {
        $group = $application->group;
        $owner = $admin ? $group->owner : null;
        $params = ['group' => $group->id, 'application' => $application->id];

        return $application->only(['id', 'phone', 'created_at', 'updated_at', 'processed_at']) + [
            'name' => trim($application->last_name.' '.$application->first_name),
            'group_title' => $group->title ?: 'Новая группа',
            'group_url' => route($admin ? 'admin.groups.show' : 'psychologist.groups.show', $group),
            'owner_name' => $owner ? PsychologistPages::profile($owner)['name'] : null,
            'owner_url' => $owner ? route('admin.psychologists.show', $owner) : null,
            'show_url' => $admin ? route('admin.applications.show', $application) : route('psychologist.groups.applications.show', $params),
            'action_url' => $admin ? null : route('psychologist.groups.applications.'.($application->processed_at ? 'unprocessed' : 'processed'), $params),
        ];
    }

    public static function listing(LengthAwarePaginator $paginator, bool $admin, array $filters, ?Group $group = null): array
    {
        return array_replace(self::layout($admin, $group), [
            'applications' => collect($paginator->items())->map(fn (GroupApplication $application) => self::data($application, $admin)),
            'filters' => $filters, 'empty' => $paginator->isEmpty(),
            'pages' => $paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)),
            'currentPage' => $paginator->currentPage(),
        ]);
    }
}
