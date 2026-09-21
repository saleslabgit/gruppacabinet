@props(['free', 'suffix' => ''])
<span class="status tone-neutral"><x-icon :name="$free ? 'gift' : 'credit-card'" /><span class="control-label">{{ $suffix === 'размещение' ? ($free ? 'Бесплатное' : 'Платное') : ($free ? 'Бесплатный' : 'Платный') }}{{ $suffix ? ' '.$suffix : '' }}</span></span>
