<?php

return [
    'disk' => 'local',
    'max_kb' => (int) env('PSYCHOLOGIST_DOCUMENT_MAX_KB', 10240),
    'types' => [
        'diploma' => 'Диплом',
        'certificate' => 'Сертификат',
        'license' => 'Лицензия / членство',
        'registration' => 'Свидетельство о государственной регистрации',
    ],
    'mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
];
