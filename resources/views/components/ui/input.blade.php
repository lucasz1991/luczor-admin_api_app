{{-- RailTime forms/input attribute-bag pattern; wire/model/value remain on the native control. --}}
@props(['type' => 'text', 'label' => null, 'hint' => null])
@php($fieldId = $attributes->get('id') ?? 'field-'.Illuminate\Support\Str::uuid())
@if($label)<label class="ui-label" for="{{ $fieldId }}">{{ $label }}</label>@endif
<input type="{{ $type }}" id="{{ $fieldId }}" {{ $attributes->except('id')->class('ui-input') }}>
@if($hint)<p class="ui-hint">{{ $hint }}</p>@endif
