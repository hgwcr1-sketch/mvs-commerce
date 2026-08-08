@props([
    'name',
    'label' => '',
    'checked' => false,
])

<div class="flex items-center gap-3">

    <input
        id="{{ $name }}"
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @checked(old($name, $checked))
        class="h-5 w-5 rounded border-gray-300 text-[#B1922D] focus:ring-[#D4AF37]">

    <label
        for="{{ $name }}"
        class="text-sm font-medium text-gray-700">

        {{ $label }}

    </label>

</div>