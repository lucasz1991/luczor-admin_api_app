@props(['label' => null, 'hint' => null])
@php($fieldId = $attributes->get('id') ?? 'field-'.Illuminate\Support\Str::uuid())
@if($label)<label class="ui-label" for="{{ $fieldId }}">{{ $label }}</label>@endif
<textarea id="{{ $fieldId }}" {{ $attributes->except('id')->class('ui-input ui-textarea') }}>{{ $slot }}</textarea>
@if($hint)<p class="ui-hint">{{ $hint }}</p>@endif
