<?php

return [
    // Administrative cleanup threshold, independent of placement duration.
    'abandoned_draft_days' => 30,
    'html_max_characters' => 100000,
    'cover_disk' => 'local',
    'cover_max_kb' => (int) env('GROUP_COVER_MAX_KB', 5120),
    'cover_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
];
