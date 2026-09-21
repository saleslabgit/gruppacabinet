<x-panel title="Анкета психолога">
@foreach([
'Контактные данные' => ['last_name'=>'Фамилия','first_name'=>'Имя','middle_name'=>'Отчество','phone'=>'Телефон','email'=>'Email'],
'Образование' => ['education_type'=>'Тип образования','other_education'=>'Другое образование','modality_program'=>'Модальность / программа','training_center'=>'Учебный центр','graduation_year'=>'Год окончания','training_hours'=>'Количество часов'],
'Опыт и лицензия' => ['license_number'=>'Номер лицензии','license_expires_at'=>'Лицензия действительна до','group_leading_experience'=>'Опыт ведения групп','groups_conducted_count'=>'Проведено групп']
] as $heading => $fields)
<section class="detail-section">
<h3>{{ $heading }}</h3>
<dl class="detail-grid">
@foreach($fields as $key => $label)
<div><dt>{{ $label }}</dt><dd>{{ $user[$key] ?? 'Не указано' }}</dd></div>
@endforeach
</dl>
</section>
@endforeach
</x-panel>
<x-panel class="panel-secondary" title="Подтверждения и согласие">
<dl class="detail-grid">
@foreach(['documents_confirmed'=>'Достоверность документов','education_confirmed'=>'Соответствие образования','live_session_ready'=>'Готовность провести вебинар или эфир'] as $key=>$label)
<div>
<dt>{{ $label }}</dt>
<dd>{{ $user[$key] === null ? 'Не указано' : ($user[$key] ? 'Да' : 'Нет') }}</dd>
</div>
@endforeach
<div>
<dt>Согласие на обработку персональных данных</dt>
<dd>
<x-date :value="$user['personal_data_consent_at']" />
</dd>
</div>
<div>
<dt>Версия согласия</dt>
<dd>{{ $user['personal_data_consent_version'] ?? 'Не указано' }}</dd>
</div>
</dl>
</x-panel>
