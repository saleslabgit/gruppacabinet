<fieldset class="detail-section" id="trainings[{{ $index }}]" tabindex="-1" data-training>
<legend class="h3" data-training-heading>Обучение {{ is_numeric($index) ? $index + 1 : '' }}</legend>
@if(isset($training['id']) && is_scalar($training['id']))
<input type="hidden" id="trainings[{{ $index }}][id]" name="trainings[{{ $index }}][id]" value="{{ $training['id'] }}">
@endif
@if($trainingError = $errors['trainings.'.$index] ?? $errors['trainings.'.$index.'.id'] ?? null)
<p class="text-danger" role="alert">{{ $trainingError }}</p>
@endif
<div class="row">
@foreach(['modality_program' => 'Модальность / программа', 'training_center' => 'Учебный центр', 'graduation_year' => 'Год окончания', 'training_hours' => 'Количество часов'] as $field => $label)
<div class="col-md-6">
<x-input :name="'trainings['.$index.']['.$field.']'" :label="$label" :type="in_array($field, ['graduation_year', 'training_hours']) ? 'number' : 'text'" :value="is_scalar($training[$field] ?? null) ? $training[$field] : ''" :error="$errors['trainings.'.$index.'.'.$field] ?? null" />
</div>
@endforeach
<div class="actions">
<button type="button" class="btn btn-ghost" data-training-up>Выше</button>
<button type="button" class="btn btn-ghost" data-training-down>Ниже</button>
<button type="button" class="btn btn-ghost" data-training-remove>Удалить обучение</button>
</div>
</div>
</fieldset>
