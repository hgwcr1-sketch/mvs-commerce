<?php

require dirname(__DIR__).'/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$base = dirname(__DIR__);
$path = $base.'/docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx';
$spreadsheet = IOFactory::load($path);

// Update P37.1 in Cronograma Maestro
$sheet = $spreadsheet->getSheetByName('Cronograma Maestro');
$newEvidencia = <<<'EOT'
FASE 1 — HACER AHORA ANTES DE VOLVER A P33: P37.1-A imágenes en todas las publicaciones; P37.1-B formulario con bordes/inputs/textarea/fechas/responsive/touch; P37.1-C galería/carrusel autoplay/swipe; P37.1-D promociones accionables con CTA Comprar/Ver producto/WhatsApp/Reservar/Ver más/URL externa y asociación opcional a producto MVS (solo info comercial necesaria, sin catálogo general ni cantidades exactas de inventario), arquitectura preparada para carrito ligero; P37.1-E redes sociales opcionales sin sincronización automática; P37.1-F puntos próximos a vencer con cantidad/fecha/días/alerta; P37.1-G progreso hacia premio con barra visual; P37.1-H personalización tenant (nombre, logo, icono, colores, bienvenida); P37.1-K Passkeys/WebAuthn (huella/Face ID/PIN dispositivo, MVS nunca almacena biometría). FASE 2 — DESPUÉS DE MIGRACIÓN DEFINITIVA: P37.1-I PWA; P37.1-J push con consentimiento; P37.1-L segmentación; P37.1-M campañas inteligentes; P37.1-N promociones personalizadas; P37.1-O recuperación de clientes; P37.1-P automatizaciones; P37.1-Q anti-spam/privacidad; P37.1-R métricas comerciales; P37.1-S dashboard comercial; P37.1-T calidad transversal; Portal Commerce con carrito ligero solo para promociones del Portal, retiro por sucursal, envío con costo configurable, disponibilidad simple por sucursal y reutilizando Core MVS sin duplicar clientes/productos/inventario/ventas/fidelización. Pago online en etapa posterior. Principio oficial: MVS Portal no es una tienda. Es un portal de fidelización capaz de convertir una oportunidad en una compra. Tras Fase 1: Productos → Inventario San Ramón → Inventario Liberia.
EOT;

$sheet->setCellValue('E46', 'FASE 1 — HACER AHORA');
$sheet->setCellValue('F46', $newEvidencia);

// Add D18 decision
$sheet2 = $spreadsheet->getSheetByName('Decisiones');
$sheet2->setCellValue('A21', 'D18');
$sheet2->setCellValue('B21', 'Portal Fidelización');
$sheet2->setCellValue('C21', 'MVS Portal no es una tienda. Es un portal de fidelización capaz de convertir una oportunidad en una compra.');
$sheet2->setCellValue('D21', 'Restringir alcance para evitar duplicar catálogo, inventario, clientes, ventas y fidelización; el Portal Commerce Fase 2 reutiliza Core MVS.');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save($path);
echo "Excel updated successfully.\n";
