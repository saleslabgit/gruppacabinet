<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Str;

class GroupModxPayloadBuilder
{
    private const DAYS = ['mon' => 'пн', 'tue' => 'вт', 'wed' => 'ср', 'thu' => 'чт', 'fri' => 'пт', 'sat' => 'сб', 'sun' => 'вс'];

    /** @return array{body: string, hash: string, cover_source: ?string} */
    public function build(#[\SensitiveParameter] Group $group): array
    {
        app(GroupModxReadiness::class)->validate($group);
        $resource = ['pagetitle' => $group->title];
        if ($group->public_site_resource_id === null) {
            $suffix = '-g'.$group->id;
            $resource['alias'] = substr(Str::slug($group->title) ?: 'group', 0, 191 - strlen($suffix)).$suffix;
        }
        $body = ['resource' => $resource, 'tvs' => [
            'groupid' => $group->public_uuid,
            'title' => $group->title,
            'shortDescription' => $group->description,
            'desc' => app(GroupHtmlSanitizer::class)->sanitize($group->full_description_html),
            'leader' => [['MIGX_id' => '1', 'text' => trim((string) preg_replace('/\s+/u', ' ', $group->owner->first_name.' '.$group->owner->last_name))]],
            'days' => implode(',', array_intersect_key(self::DAYS, array_flip($group->meeting_days))),
            'startAt' => $group->start_time,
            'duration' => (string) $group->meeting_duration_minutes,
            'frequency' => $group->frequency,
            'format' => $group->format->modx_value,
            'city' => $group->city,
            'groupType' => $group->groupType->modx_value,
            'approaches' => $group->approaches->pluck('modx_value')->implode('||'),
            'participantsCount' => (string) $group->participant_capacity,
            'gender' => $group->gender->modx_value,
            'price' => (string) intdiv($group->meeting_price, 100),
            'tags' => $group->tags->pluck('modx_value')->implode('||'),
        ]];
        if (trim($group->meeting_price_currency ?? '') !== '') {
            $body['tvs']['price_usd'] = trim($group->meeting_price_currency);
        }
        if ($group->public_site_resource_id !== null) {
            $body['resource_id'] = $group->public_site_resource_id;
        }
        $source = null;
        if (! $group->modx_remote_cover_path || $group->cover_path !== $group->modx_remote_cover_source_path) {
            $bytes = app(GroupCovers::class)->readVerified($group);
            $body['cover'] = ['filename' => app(GroupCovers::class)->safeName($group->cover_original_name),
                'mime_type' => $group->cover_mime_type, 'content_base64' => base64_encode($bytes)];
            $source = $group->cover_path;
        } else {
            $body['tvs']['image'] = $group->modx_remote_cover_path;
        }
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['body' => $raw, 'hash' => hash('sha256', $raw), 'cover_source' => $source];
    }
}
