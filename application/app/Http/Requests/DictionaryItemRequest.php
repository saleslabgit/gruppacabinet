<?php

namespace App\Http\Requests;

use App\Models\Dictionary;
use Illuminate\Validation\Rule;

class DictionaryItemRequest extends DictionaryRequest
{
    public function rules(): array
    {
        /** @var Dictionary $dictionary */
        $dictionary = $this->route('dictionary');

        return [
            'code' => $this->isMethod('PUT') ? ['prohibited'] : ['required', 'string', 'max:64', 'regex:/\A[a-z0-9_]+\z/', Rule::unique('gp_dictionary_items', 'code')->where('dictionary_id', $dictionary->id)],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'between:0,4294967295'],
            'active' => ['sometimes', 'boolean'],
            'confirmed' => ['sometimes', 'accepted'],
        ];
    }
}
