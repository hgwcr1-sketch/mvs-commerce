@props([
    'label' => '',
    'name' => null,
    'type' => 'text',
    'required' => false,
])

<div>

    @if($label)

        <label
            @if($name)
                for="{{ $name }}"
            @endif
            class="form-label">

            {{ $label }}

            @if($required)
                <span class="text-red-500">*</span>
            @endif

        </label>

    @endif

    <input

        @if($name)
            id="{{ $name }}"
            name="{{ $name }}"
            value="{{ old($name, $attributes->get('value')) }}"
        @endif

        type="{{ $type }}"

        {{ $required ? 'required' : '' }}

        {{ $attributes->merge([
            'class' => 'form-input'
        ]) }}>

    @if($name)

        @error($name)

            <p class="mt-1 text-sm text-red-600">

                {{ $message }}

            </p>

        @enderror

    @endif

</div>