<x-panel title="Анкета психолога">
<dl class="detail-grid">
@foreach(['last_name'=>'Фамилия','first_name'=>'Имя','middle_name'=>'Отчество','phone'=>'Телефон','email'=>'Email','education_type'=>'Тип образования','other_education'=>'Другое образование','modality_program'=>'Модальность / программа','training_center'=>'Учебный центр','graduation_year'=>'Год окончания','training_hours'=>'Количество часов','license_number'=>'Номер лицензии','license_expires_at'=>'Лицензия действительна до','group_leading_experience'=>'Опыт ведения групп','groups_conducted_count'=>'Проведено групп'] as $key=>$label)
<div>
<dt>{{ $label }}</dt>
<dd>{{ $user[$key] ?? 'Не указано' }}</dd>
</div>
@endforeach
</dl>
</x-panel>
<x-panel title="Подтверждения и согласие">
<dl class="detail-grid">
@foreach(['documents_confirmed'=>'Достоверность документов','education_confirmed'=>'Соответствие образования','live_session_ready'=>'Готовность провести вебинар или эфир'] as $key=>$label)
<div>
<dt>{{ $label }}</dt>
<dd>{{ $user[$key] ? 'Да' : 'Нет' }}</dd>
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
<dd>{{ $user['personal_data_consent_version'] }}</dd>
</div>
</dl>
</x-panel>
