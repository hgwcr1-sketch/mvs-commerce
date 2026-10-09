<div>
    <x-select name="geo_country_iso" label="País (catálogo internacional)">
        <option value="">Seleccione...</option>
        @foreach($countries as $country)
            <option
                value="{{ $country['iso'] }}"
                @selected(old('geo_country_iso', $selected) === $country['iso'])>
                {{ $country['name'] }}
            </option>
        @endforeach
    </x-select>

    <p id="geo-localization-hint" class="mt-1 text-xs text-gray-500"></p>

    <script>
        (function () {
            var localization = @js((array) config('geography.localization', []));
            var select = document.getElementById('geo_country_iso');
            var hint = document.getElementById('geo-localization-hint');

            if (! select || ! hint) {
                return;
            }

            function render() {
                var data = localization[select.value];
                hint.textContent = data
                    ? 'Moneda: ' + data.currency_name + ' · Prefijo: ' + data.phone_prefix + ' · Identificación: ' + data.identification
                    : '';
            }

            select.addEventListener('change', render);
            render();
        })();
    </script>
</div>
