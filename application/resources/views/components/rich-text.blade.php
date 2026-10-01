@props(['name', 'label', 'value' => '', 'required' => false, 'error' => null])
<div data-rich-editor>
<x-textarea :name="$name" :label="$label" :value="$value" :required="$required" :error="$error" help="Полный текст группы." />
</div>
