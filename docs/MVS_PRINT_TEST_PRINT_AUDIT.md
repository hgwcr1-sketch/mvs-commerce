# MVS Print — AUTO_PRINT, Imprimir postventa y Reimprimir (2026-09-15)

Base: feature/pos, 21d5329. Corrección aprobada para un único commit y push; sin producción.

## Causa y recorrido

El usuario confirmó que el incidente era Ventas → Reimprimir. Los enlaces de
ventas/index y ventas/show abrían pos.receipt con target=_blank. PosController::receipt
prepara pos/receipt.blade.php, cuyo botón llama window.print() y cuyo listener load
lo llama cuando autoPrint está activo. No existía intento QZ en esos enlaces.
La auditoría anterior de Imprimir prueba no era el recorrido del incidente.

Ahora ambos usan components/mvs-print/reprint.blade.php → mvsReprint.reprint →
MvsPrint.printSale(reprint:true) → GET mvs/print/ticket/{sale}?reprint=1 →
MvsPrintTicketController → el mismo EscPosSaleTicket → RAW command/base64 →
qz.configs.create(printer) → qz.print. No hay segundo generador.

## Reglas preservadas

- UUID local tiene prioridad, siempre aislado por empresa/sucursal y enabled.
- Sin UUID solo se resuelve una única terminal con impresora configurada.
  Con varias terminales o UUID inválido no se elige otra silenciosamente.
- Reprint usa printer_name del servidor, ancho y auto_cut guardados.
- Cajón desactivado en backend y frontend para reprint. La configuración guardada
  permanece intacta. Venta nueva conserva open_drawer de su terminal.
- Autorización de reprint reutiliza SaleReceiptService::authorizedSale.
- Se conserva el requisito del ticket existente: venta completada de la sucursal
  activa. Otros estados conservan la alternativa manual del comprobante tradicional.
- Éxito: Factura enviada a [impresora]. Error: No fue posible imprimir directamente.
  Se muestra Usar impresión del navegador; nunca se abre automáticamente.
- El botón se bloquea mientras QZ resuelve, incluyendo su autorización. No se usa
  timeout artificial para declarar error mientras el trabajo todavía puede imprimir.
- Las posibles autorizaciones propias de QZ no equivalen al diálogo del navegador.
  No se provisionaron certificados ni se cambió el modo firmado.

## Venta nueva / auto_print

Se conserva el guard de checkout: éxito no duplicado, terminal y auto_print=true.
Config y ticket comparten el resolver. Se corrigen dos defectos de printSale:
connect() resuelve undefined (antes se interpretaba como fallo) y una conexión ya
activa no debe reconectarse. No hay window.print ni fallback automático en ese flujo.
No se modifica el procesamiento backend de checkout, venta, stock, pagos, puntos ni consecutivos.

## Ampliación confirmada: botón Imprimir después del cobro

El botón POS posterior al cobro también abría receipt_url con target=_blank.
Ahora llama printCompletedSale → MvsPrint.printSale(reprint:true), el mismo canal
QZ y generador de la reimpresión de Ventas. AUTO_PRINT delega en printCompletedSale
con reprint:false, por lo que conserva el cajón configurado para venta nueva.
El botón manual no abre el cajón, evitando otra apertura al repetir el ticket.

Los tres puntos terminan en MvsPrint.printSale → MvsPrintTicketController →
EscPosSaleTicket → qz.configs.create → qz.print. El fallback receipt_url solo queda
visible tras fallo y requiere clic explícito. Sin terminal resoluble se muestra
un mensaje de empresa/sucursal; no se sustituye por una terminal de otro tenant.
La consulta automática pendiente bloquea el clic manual para evitar doble envío.

Demo física: configuración NO consultada, por restricción de no acceder a producción.
No afirmar que tiene o carece de terminal. El caso Demo sin terminal, incluso con
UUID de MYM en localStorage, queda cubierto mediante fixtures aislados de pruebas.

Los tests PHP previos probaban endpoints/payloads, no ejecutaban el disparador JS
del navegador. Ahora los tests Node ejecutan confirmCheckout, attemptAutoPrint,
printCompletedSale y la expresión del botón extraídos del Blade real, con QZ y
HTTP simulados: éxito no duplicado, auto_print=false, ausencia de terminal, fallo,
conexión que resuelve undefined, conexión activa, fallback manual y cajón.
Esto sigue sin acreditar ejecución en el navegador de Demo ni salida física.

## Diagnóstico y UI

Retirada la instrumentación temporal del turno anterior. Tests JS reemplazados por
regresiones del flujo real. Controles de 44px, texto ajustable, tabla existente con
scroll horizontal contenido. Revisión conceptual 360/768/1280; navegador físico pendiente.

## Validación y entrega

Ver resultados finales en docs/ESTADO_ACTUAL.md. Pruebas HTTP comparan todas las
tablas antes/después y registran SQL para demostrar cero escrituras durante reprint
(tras preparar middleware/licencia). JS simula QZ; no acredita salida física.

Pendiente prueba física autorizada: POS-58-Series, 58 mm, corte según terminal,
sin cajón al reimprimir; error QZ muestra fallback manual. Si hay varias terminales,
se requiere vínculo UUID local existente para escoger la correcta. Sin deploy.
