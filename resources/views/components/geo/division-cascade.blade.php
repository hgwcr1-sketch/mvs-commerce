<div id="geo-cascade"
     data-endpoint="{{ route('geo.divisiones') }}"
     data-country="{{ $countryIso }}">

    <span class="form-label">División territorial (catálogo)</span>

    <p id="geo-catalog-notice"
       class="mt-1 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-2"
       style="{{ $hasCatalog ? 'display:none' : '' }}">
        {{ $notice }}
    </p>

    <div id="geo-cascade-selects" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
        @if($hasCatalog)
            @foreach($levels as $level)
                <div>
                    <label for="geo-div-{{ $level['level'] }}" class="form-label">{{ $level['name'] }}</label>
                    <select
                        id="geo-div-{{ $level['level'] }}"
                        data-level="{{ $level['level'] }}"
                        @if($loop->last) name="geo_division_id" @endif
                        class="form-select"
                        style="min-height:44px">
                        <option value="">Seleccione...</option>
                        @foreach($optionsByLevel[$level['level']] ?? [] as $option)
                            <option
                                value="{{ $option['id'] }}"
                                @selected(old('geo_division_id', $division ?? null) == $option['id'] || $option['selected'])>
                                {{ $option['name'] }}
                            </option>
                        @endforeach
                    </select>
                    @if($loop->last)
                        @isset($errors)
                            @error('geo_division_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        @endisset
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>

<script>
    (function () {
        var wrap = document.getElementById('geo-cascade');
        if (! wrap) return;

        var container = document.getElementById('geo-cascade-selects');
        var notice = document.getElementById('geo-catalog-notice');
        var endpoint = wrap.getAttribute('data-endpoint');
        var countrySelect = document.getElementById('geo_country_iso');
        var levels = @json($levels);
        var currentIso = wrap.getAttribute('data-country');

        function selects() {
            return Array.prototype.slice.call(container.querySelectorAll('select[data-level]'));
        }

        function fixNames() {
            var all = selects();
            all.forEach(function (sel, i) {
                if (i === all.length - 1) sel.setAttribute('name', 'geo_division_id');
                else sel.removeAttribute('name');
            });
        }

        function appendSelect(index, rows) {
            var level = levels[index];
            if (! level) return;

            var existing = container.querySelector('select[data-level="' + level.level + '"]');
            var holder = existing ? existing.parentNode : document.createElement('div');

            if (! existing) {
                holder = document.createElement('div');
                var label = document.createElement('label');
                label.className = 'form-label';
                label.setAttribute('for', 'geo-div-' + level.level);
                label.textContent = level.name;
                var sel = document.createElement('select');
                sel.className = 'form-select';
                sel.style.minHeight = '44px';
                sel.id = 'geo-div-' + level.level;
                sel.setAttribute('data-level', level.level);
                sel.innerHTML = '<option value="">Seleccione...</option>';
                holder.appendChild(label);
                holder.appendChild(sel);
                container.appendChild(holder);
                existing = sel;
            }

            existing.innerHTML = '<option value="">Seleccione...</option>';
            rows.forEach(function (row) {
                var opt = document.createElement('option');
                opt.value = row.id;
                opt.textContent = row.name;
                existing.appendChild(opt);
            });
            fixNames();
        }

        function truncate(keepCount) {
            var all = selects();
            all.slice(keepCount).forEach(function (sel) {
                sel.parentNode.parentNode.removeChild(sel.parentNode);
            });
            fixNames();
        }

        function showNotice(text) {
            notice.textContent = text;
            notice.style.display = '';
            container.innerHTML = '';
        }

        function hideNotice() {
            notice.style.display = 'none';
        }

        function fetchJson(iso, parentId) {
            var url = endpoint + '?pais=' + encodeURIComponent(iso) + (parentId ? '&padre=' + encodeURIComponent(parentId) : '');
            return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
        }

        container.addEventListener('change', function (event) {
            var sel = event.target;
            if (! sel.matches || ! sel.matches('select[data-level]')) return;

            var index = selects().indexOf(sel);
            truncate(index + 1);

            if (! sel.value) return;

            hideNotice();
            fetchJson(currentIso, sel.value).then(function (data) {
                if (! data.has_catalog || ! data.divisions.length) {
                    appendSelect(index + 1, []);
                } else {
                    appendSelect(index + 1, data.divisions);
                }
            });
        });

        if (countrySelect) {
            countrySelect.addEventListener('change', function () {
                var iso = countrySelect.value;
                currentIso = iso;
                container.innerHTML = '';

                if (! iso) {
                    showNotice('Seleccione un país para ver sus divisiones.');
                    return;
                }

                fetchJson(iso, null).then(function (data) {
                    if (! data.has_catalog || ! data.levels || ! data.levels.length) {
                        showNotice('El catálogo territorial de este país aún no está cargado. No hay divisiones reales disponibles para seleccionar.');
                        return;
                    }
                    levels = data.levels;
                    hideNotice();
                    appendSelect(0, data.divisions);
                });
            });
        }
    })();
</script>
