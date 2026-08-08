@props([
    'label' => '',
    'name',
    'rows' => 4,
    'required' => false,
])

<div>

    @if($label)

        <label
            for="{{ $name }}"
            class="form-label">

            {{ $label }}

            @if($required)
                <span class="text-red-500">*</span>
            @endif

        </label>

    @endif

    <textarea

        id="{{ $name }}"

        name="{{ $name }}"

        rows="{{ $rows }}"

        {{ $required ? 'required' : '' }}

        {{ $attributes->merge([
            'class' => 'form-textarea'
        ]) }}>{{ old($name, $attributes->get('value')) }}</textarea>

    @error($name)

        <p class="mt-1 text-sm text-red-600">

            {{ $message }}

        </p>

    @enderror

</div>