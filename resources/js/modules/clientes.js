document.addEventListener('DOMContentLoaded', () => {

    const country = document.getElementById('country_id');
    const province = document.getElementById('province_id');
    const canton = document.getElementById('canton_id');
    const district = document.getElementById('district_id');

    if (!country) return;

    function fillSelect(select, items, placeholder = 'Seleccione...') {

        select.innerHTML = `<option value="">${placeholder}</option>`;

        items.forEach(item => {

            select.innerHTML += `
                <option value="${item.id}">
                    ${item.name}
                </option>
            `;

        });

    }

    country.addEventListener('change', async function () {

        fillSelect(province, []);
        fillSelect(canton, []);
        fillSelect(district, []);

        if (!this.value) return;

        const response = await fetch(`/ubicaciones/provincias/${this.value}`);

        const data = await response.json();

        fillSelect(province, data);

    });

    province.addEventListener('change', async function () {

        fillSelect(canton, []);
        fillSelect(district, []);

        if (!this.value) return;

        const response = await fetch(`/ubicaciones/cantones/${this.value}`);

        const data = await response.json();

        fillSelect(canton, data);

    });

    canton.addEventListener('change', async function () {

        fillSelect(district, []);

        if (!this.value) return;

        const response = await fetch(`/ubicaciones/distritos/${this.value}`);

        const data = await response.json();

        fillSelect(district, data);

    });

});