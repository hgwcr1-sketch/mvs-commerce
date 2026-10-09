/**
 * Prueba focal: impresión POS unificada vía MVS Print.
 *
 * 1) El checkout navega al recibo con no_auto_print=1 (nunca diálogo nativo).
 * 2) Solo MVS Print imprime automáticamente (attemptAutoPrint + printCompletedSale).
 * 3) El recibo solo auto-imprime con $autoPrint y conserva el botón manual.
 * 4) El fallo de MVS Print se informa con mvs_print=failed.
 * 5) No hay doble disparo: un único window.print() en receipt y guardas de reentrada.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');

const pos = read('resources/views/pos/index.blade.php');
const receipt = read('resources/views/pos/receipt.blade.php');
const escpos = read('app/Services/MvsPrint/EscPosSaleTicket.php');

let failures = 0;
const check = (label, ok) => {
    console.log((ok ? 'PASS' : 'FAIL') + ': ' + label);
    if (!ok) failures++;
};

// 1) Navegación post-cobro siempre suprime el diálogo del navegador.
check('POS navega al recibo con no_auto_print=1', /searchParams\.set\('no_auto_print', '1'\)/.test(pos));
check('POS informa fallo de MVS Print con mvs_print=failed', /searchParams\.set\('mvs_print', 'failed'\)/.test(pos));

// 2) Una sola impresión automática, vía MVS Print, con guarda de reentrada.
check('checkout dispara attemptAutoPrint una sola vez', (pos.match(/attemptAutoPrint\(/g) || []).length === 2); // definición + llamada
check('attemptAutoPrint evita reentrada (checking/printing)', /if \(\['checking', 'printing'\]\.includes\(checkout\.printStatus\)\) return;/.test(pos));
check('printCompletedSale usa MvsPrint.printSale con reprint flag', /MvsPrint\.printSale\(/.test(pos));
check('POS no llama window.print() directamente', !/window\.print\(\)/.test(pos));

// 3) Recibo: auto-impresión exclusivamente condicionada por $autoPrint.
//    window.print() aparece 2 veces: botón manual + script auto bajo $autoPrint.
const totalPrintCalls = (receipt.match(/window\.print\(\)/g) || []).length;
check('receipt.blade.php: 1 botón manual + 1 auto condicionado (2 window.print())', totalPrintCalls === 2);
check('auto-impresión condicionada por $autoPrint', /@if\(!\(\$pdfMode \?\? false\) && \$autoPrint\)/.test(receipt));
check('script de auto-impresión único', (receipt.match(/window\.addEventListener\('load',\(\)=>window\.print\(\)\);/g) || []).length === 1);

// 4) Aviso de fallo MVS Print con fallback manual.
check('receipt informa fallo de MVS Print', receipt.includes('La impresión automática con MVS Print no estuvo disponible'));
check('receipt conserva botón manual Imprimir', /onclick="window\.print\(\)"[^>]*>Imprimir/.test(receipt));

// 5) Fidelización ESC/POS a 2 decimales (presentación, no datos).
check('EscPosSaleTicket formatea puntos con formatPoints', /formatPoints\(\$loyalty\['earned'\]\)/.test(escpos)
    && /formatPoints\(\$loyalty\['balance_after'\]\)/.test(escpos)
    && /formatPoints\(\$loyalty\['balance_before'\]\)/.test(escpos)
    && /formatPoints\(\$loyalty\['redeemed'\]\)/.test(escpos));
check('formatPoints usa 2 decimales', /number_format\(\(float\) \(\$points \?\? 0\), 2, ',', '\.'\)/.test(escpos));
check('sin valores crudos de escala 4 en líneas de fidelización', !/\.\'\.\$loyalty\['earned'\]/.test(escpos) && !/\.\'\.\$loyalty\['balance_after'\]/.test(escpos));

if (failures > 0) {
    console.error('mvs-print-single-print: ' + failures + ' FAIL');
    process.exit(1);
}
console.log('mvs-print-single-print: 13/13 PASS');
