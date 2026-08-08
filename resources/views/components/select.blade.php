@props([
    'label' => '',
    'name' => null,
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

    <select

        @if($name)
            id="{{ $name }}"
            name="{{ $name }}"
        @endif

        {{ $required ? 'required' : '' }}

        {{ $attributes->merge([
            'class' => 'form-select'
        ]) }}>

        {{ $slot }}

    </select>

    @if($name)

        @error($name)

            <p class="mt-1 text-sm text-red-600">

                {{ $message }}

            </p>

        @enderror

    @endif

</div>