// Text inputs accept either decimal separator independently of browser locale.
export function normalizeMoney(value) {
    return String(value ?? '').trim().replace(',', '.');
}

export function displayMoney(value) {
    const normalized = normalizeMoney(value);
    if (!/^\d+(?:\.\d+)?$/.test(normalized)) return value;
    const [whole, fraction = ''] = normalized.split('.');
    // Preserve existing sub-cent costs; never round stored precision on form load.
    return `${whole}.${fraction.replace(/0+$/, '').padEnd(2, '0')}`;
}

if (typeof document !== 'undefined') {
    document.addEventListener('input', (event) => {
        if (event.target.matches('input[data-money]')) {
            event.target.value = normalizeMoney(event.target.value);
        }
    }, true);

    document.addEventListener('blur', (event) => {
        if (event.target.matches('input[data-money]')) {
            event.target.value = displayMoney(event.target.value);
        }
    }, true);

    document.querySelectorAll('input[data-money]').forEach((input) => {
        input.value = displayMoney(input.value);
    });
}
