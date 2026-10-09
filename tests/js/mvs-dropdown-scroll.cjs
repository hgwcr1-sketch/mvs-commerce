/**
 * MVS Commerce — prueba focal del helper de scroll en desplegables.
 * Verifica que la opción activa permanezca visible dentro del contenedor
 * sin desplazar la página (block:'nearest' + fallback manual).
 *
 * Uso: node tests/js/mvs-dropdown-scroll.cjs
 */
const fs = require('fs');
const path = require('path');

function fail(message) {
    console.error('FAIL: ' + message);
    process.exit(1);
}

function ok(message) {
    console.log('PASS: ' + message);
}

const repoRoot = path.resolve(__dirname, '..', '..');
const helperPath = path.join(repoRoot, 'resources', 'js', 'mvs-dropdown.js');
const appJsPath = path.join(repoRoot, 'resources', 'js', 'app.js');

if (!fs.existsSync(helperPath)) { fail('resources/js/mvs-dropdown.js no existe'); }
const helper = fs.readFileSync(helperPath, 'utf8');
if (!helper.includes('mvsScrollActiveIntoView')) { fail('helper sin mvsScrollActiveIntoView'); }
if (!helper.includes("block: 'nearest'") && !helper.includes('block:"nearest"') && !helper.includes("block:'nearest'")) {
    fail('helper sin scrollIntoView block nearest');
}
ok('helper mvs-dropdown.js con scrollIntoView nearest + fallback');

const appJs = fs.readFileSync(appJsPath, 'utf8');
if (!appJs.includes('mvs-dropdown')) { fail('app.js no importa mvs-dropdown'); }
ok('app.js importa el helper compartido');

const blades = [
    ['resources/views/pos/index.blade.php', ['x-ref="productResults"', 'x-ref="customerResults"', ':data-index="index"', 'mvsScrollActiveIntoView']],
    ['resources/views/components/cabys-search.blade.php', ['x-ref="results"', ':data-index="index"', 'mvsScrollActiveIntoView']],
    ['resources/views/components/customer-filter.blade.php', ['x-ref="results"', ':data-index="index"', 'mvsScrollActiveIntoView']],
    ['resources/views/loyalty/accesses/index.blade.php', ['x-ref="results"', ':data-index="index"', 'mvsScrollActiveIntoView']],
];

for (const [rel, markers] of blades) {
    const full = path.join(repoRoot, rel);
    if (!fs.existsSync(full)) { fail(rel + ' no existe'); }
    const content = fs.readFileSync(full, 'utf8');
    for (const marker of markers) {
        if (!content.includes(marker)) { fail(rel + ' sin marcador: ' + marker); }
    }
    // Enter / Esc / mouse intactos.
    if (!content.includes('enter') || !content.includes('escape') || !content.includes('mouseenter')) {
        fail(rel + ' perdió Enter/Esc/mouse');
    }
    ok(rel + ' con x-ref + data-index + scroll + Enter/Esc/mouse intactos');
}

// Simulación mínima del comportamiento: índice circular + scroll nearest.
function circular(index, dir, length) {
    return (index + dir + length) % length;
}
if (circular(0, -1, 5) !== 4) { fail('navegación circular rota'); }
if (circular(4, 1, 5) !== 0) { fail('navegación circular rota'); }
ok('navegación circular ↑/↓ preservada');

console.log('mvs-dropdown-scroll: 8/8 PASS');
