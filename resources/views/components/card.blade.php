<div {{ $attributes->merge([
    'class' => 'bg-white rounded-xl shadow-sm border border-slate-200'
]) }}>

    @isset($header)
        <div class="px-6 py-4 border-b border-slate-200">
            {{ $header }}
        </div>
    @endisset

    <div class="p-6">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 rounded-b-xl">
            {{ $footer }}
        </div>
    @endisset

</div>