@props(['value' => null, 'currency' => 'BYN'])
{{ $value === null ? 'Не настроена' : \App\Support\MoneyFormatter::format($value, $currency) }}
