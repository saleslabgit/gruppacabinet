<?php

namespace App\Http\Requests;

class DictionaryActionRequest extends DictionaryRequest
{
    public function rules(): array
    {
        return $this->routeIs('*.activate') ? [] : ['confirmed' => ['accepted']];
    }
}
