<?php

namespace App\Integration;

use App\Services\PsychologistDocuments;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;

class IntakeData
{
    public function __construct(public readonly array $fields, public readonly array $documents = [], public readonly array $files = []) {}

    private static function decode(string $json): array
    {
        try {
            $object = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                throw new IntegrationException('validation_failed', 422, ['payload' => ['Expected a JSON object.']]);
            }

            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IntegrationException('validation_failed', 422, ['payload' => ['Invalid JSON.']]);
        }
    }

    private static function validate(array $data, array $rules): array
    {
        $data = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $validator = Validator::make($data, $rules);
        $errors = [];
        foreach (array_diff(array_keys($data), array_keys($rules)) as $key) {
            $errors['request'] = ['Unexpected fields are not accepted.'];
        }
        if ($validator->fails()) {
            foreach ($validator->errors()->keys() as $key) {
                $errors[$key] = ['Invalid or missing value.'];
            }
        }
        if ($errors !== []) {
            throw new IntegrationException('validation_failed', 422, $errors);
        }

        return $validator->validated();
    }

    public static function application(Request $request): self
    {
        $data = self::validate(self::decode($request->getContent()), [
            'group_uuid' => ['required', 'uuid'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
        ]);
        $data['group_uuid'] = strtolower($data['group_uuid']);
        try {
            $data['phone_normalized'] = app(PhoneNormalizer::class)->normalizeForStorage($data['phone']);
        } catch (InvalidArgumentException) {
            throw new IntegrationException('validation_failed', 422, ['phone' => ['An explicit international phone is required.']]);
        }

        return new self($data);
    }

    public static function psychologist(Request $request): self
    {
        if (array_keys($request->request->all()) !== ['payload']) {
            throw new IntegrationException('validation_failed', 422, ['payload' => ['Only the signed payload field is accepted.']]);
        }
        $manifest = self::decode($request->request->all()['payload']);
        if (array_diff(array_keys($manifest), ['questionnaire', 'documents']) !== [] || ! isset($manifest['questionnaire'], $manifest['documents']) || ! is_array($manifest['questionnaire']) || ! is_array($manifest['documents']) || ! array_is_list($manifest['documents'])) {
            throw new IntegrationException('validation_failed', 422, ['payload' => ['Expected questionnaire and documents.']]);
        }
        $rules = [
            'email' => ['required', 'email', 'max:255'],
            'education_type_code' => ['nullable', 'string', 'max:255'],
            'graduation_year' => ['nullable', 'integer', 'between:0,65535'],
            'training_hours' => ['nullable', 'integer', 'between:0,4294967295'],
            'groups_conducted_count' => ['nullable', 'integer', 'between:0,4294967295'],
            'license_expires_at' => ['nullable', 'date_format:Y-m-d'],
            'group_leading_experience' => ['nullable', 'string', 'max:16000'],
            'personal_data_consent_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after_or_equal:1970-01-02', 'before:2038-01-19'],
            'personal_data_consent_version' => ['required', 'string', 'max:255'],
        ];
        foreach (['last_name', 'first_name', 'middle_name', 'phone', 'other_education', 'modality_program', 'training_center', 'license_number'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }
        foreach (['documents_confirmed', 'education_confirmed', 'live_session_ready'] as $field) {
            $rules[$field] = ['nullable', 'boolean'];
        }
        $data = self::validate($manifest['questionnaire'], $rules);
        $data = array_replace(array_fill_keys(array_keys($rules), null), $data);
        $data['email'] = mb_strtolower($data['email']);
        foreach (['graduation_year', 'training_hours', 'groups_conducted_count'] as $field) {
            $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }
        foreach (['documents_confirmed', 'education_confirmed', 'live_session_ready'] as $field) {
            $data[$field] = $data[$field] === null ? null : (bool) $data[$field];
        }
        $files = $request->allFiles();
        $documents = [];
        $seen = [];
        foreach ($manifest['documents'] as $descriptor) {
            if (! is_array($descriptor)) {
                throw new IntegrationException('invalid_signature', 401);
            }
            $descriptor = self::validate($descriptor, [
                'field' => ['required', 'string', 'regex:/\Adocument_[0-9]+\z/'],
                'type' => ['required', Rule::in(array_keys(config('psychologist_documents.types')))],
                'original_name' => ['required', 'string', 'max:255'],
                'size' => ['required', 'integer', 'min:0'],
                'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            ]);
            $field = $descriptor['field'];
            $file = $files[$field] ?? null;
            if (isset($seen[$field]) || ! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new IntegrationException('invalid_signature', 401);
            }
            $seen[$field] = true;
            if ($file->getSize() !== (int) $descriptor['size'] || ! hash_equals($descriptor['sha256'], hash_file('sha256', $file->getPathname()))) {
                throw new IntegrationException('invalid_signature', 401);
            }
            if ($file->getSize() > config('psychologist_documents.max_kb') * 1024 || ! in_array($file->getMimeType(), config('psychologist_documents.mime_types'), true)) {
                throw new IntegrationException('validation_failed', 422, ['documents' => ['Invalid document content or size.']]);
            }
            $descriptor['size'] = (int) $descriptor['size'];
            $descriptor['original_name'] = app(PsychologistDocuments::class)->safeName($descriptor['original_name']);
            $documents[] = $descriptor;
        }
        if (count($seen) !== count($files)) {
            throw new IntegrationException('invalid_signature', 401);
        }

        return new self($data, $documents, $files);
    }

    public function fingerprint(string $endpoint): string
    {
        $fields = $this->fields;
        // Display punctuation in a phone number is not a different application.
        if ($endpoint === 'group-applications') {
            $fields['phone'] = $fields['phone_normalized'];
        }
        ksort($fields);
        $documents = array_map(function (array $document): array {
            unset($document['field']);
            ksort($document);

            return $document;
        }, $this->documents);
        usort($documents, fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return hash('sha256', json_encode([$endpoint, $fields, $documents], JSON_THROW_ON_ERROR));
    }
}
