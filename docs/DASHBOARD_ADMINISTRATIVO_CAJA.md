# Dashboard Administrativo + Caja

## Separación administrativa y operativa — 2026-09-09

El bloque anterior se publicó en `feature/pos` como `f40efccf3a614eb88aa56d373ee46da3d5a1a856`. Esta corrección posterior permanece local, sin commit ni intervención de producción.

La consulta administrativa utilizaba `active_branch_id` y el selector compartido escribía esa misma sesión. Ahora `/dashboard` con permiso empresarial `dashboard.admin` consulta todas las sucursales por defecto, aunque exista una sucursal operativa previa. `branch_id` y `period` en la URL son filtros de consulta: se valida empresa y sucursal activa, sin modificar la sesión. Ventas y alertas usan el mismo filtro. La consulta administrativa puede incluir sucursales de la empresa no asignadas para operación; esto no concede su asignación ni permisos POS/Caja.

El encabezado administrativo muestra la empresa. El selector operativo aparece al entrar voluntariamente a POS/Caja; sin sucursal, sus pantallas de entrada solicitan selección explícita. El POST de selección solo acepta sucursales activas asignadas al usuario dentro de la empresa. El valor legado `all` regresa al Dashboard sin borrar la sucursal operativa. POS sigue exigiendo caja y Caja conserva sus permisos y validaciones. Los usuarios operativos mantienen la selección automática y la apertura obligatoria existente. Se corrige también la variable local que retenía un ID inválido en `EnsureActiveBranch`.

No se cambian permisos por nombre de rol ni datos de producción. El bypass de consulta existente depende de `dashboard.admin` para la empresa; no concede permisos operativos. No cambia `documentsBreakdown()`, correo, inventario ni el stash de Notificaciones.

Responsive revisado conceptualmente a 360/768/1280: filtro en columna móvil, controles de 44px, ancho acotado y tablas con scroll propio. Build local correcto. No se realizó validación visual en navegador.

Validación de esta corrección:

- `AdministrativeDashboardTest|P08CashOpeningGateTest|AccountsPayableDashboardAlertsTest|CompanyOnboardingTest`: 22/22, 143 aserciones.
- Focal final `AdministrativeDashboardTest|CashSessionOpeningTest|CashBlindClosingTest|CashSessionMailNotificationTest`: 56/56, 515 aserciones; incluye el caso adicional de consulta sin asignación operativa.
- Regresión `Cash|AdministrativeDashboardTest|ResponsiveNavigationTest|AccountsPayableDashboardAlertsTest|PosAccessAndSearchTest|CompanyOnboardingTest`: 213 pruebas, 206 aprobadas, 1274 aserciones. Persisten cinco fallos conocidos (Órdenes/POS 200/302, tres expectativas de payload/vista POS y logo del encabezado) y dos errores de compras por `InventoryPostingService::postPurchase()` ausente. Sin cambios a esos archivos ajenos.
- `npm.cmd run build`: correcto, 236 módulos. `git diff --check`: correcto. Evidencia JUnit local en `storage/logs/admin-context-{focused,regression,final}.xml`.

## Registro del cierre anterior

Estado previo al commit del 2026-09-09: implementación local terminada en `feature/pos`, preparada para un commit único pendiente de instrucción. Producción no intervenida. No se recuperó ni modificó el stash de notificaciones.

## Alcance conservado

- Administrador con `dashboard.admin` consulta sin caja; el POS conserva su requisito operativo. El contexto “Todas las sucursales” se valida en el controlador y persiste sin autoseleccionar otra sucursal ni modificar sesión desde la vista.
- Dashboard con Hoy, Semana calendario lunes–domingo y Mes, zona horaria empresarial, empresa/sucursal, ventas reales y alertas existentes. Importes anteriores a devoluciones, incluidos históricos; sin motores de recomendaciones.
- Apertura con denominaciones vacías, total recalculado en servidor y detalle `opening`. Cierre ciego con declaraciones vacías, cálculo decimal y permisos administrativos para revisar esperados, diferencias, denominaciones, eventos y totales.
- Migración aditiva `2026_09_08_000001_add_cash_effect_snapshot_to_reconciliations` conserva la afectación de efectivo de cada medio para no duplicarlo en el total general. Sin backfill ni cambios a cierres históricos. Fue aplicada a SQLite local durante la implementación anterior; no hubo migraciones en esta continuación.
- Se conserva el contador de artículos POS ya presente en el working tree. No se modifica la lógica contable de ventas.

## Correos configurables por empresa

Configuración → Caja reutiliza `CompanyCashSetting.closure_email_recipients`, hasta diez correos, con validación, normalización y deduplicación. Los destinatarios pertenecen a la empresa activa; un `company_id` enviado en el formulario no cambia la empresa editada. La UI permite agregar/quitar correos y conserva un array vacío después de errores de validación.

Sin destinatarios se completa el cierre normalmente y la notificación queda `skipped`; no se crea trabajo de envío. No se añaden correos de empleado ni direcciones fijas. El servicio rechaza una configuración de otra empresa y el job verifica que la notificación y su sesión pertenezcan a la misma empresa.

Se conserva la separación de remitentes que ya estaba implementada en el working tree:

- Auth/sistema hereda `mail.from` (`MAIL_FROM_ADDRESS`, no-reply configurado en el entorno).
- Caja usa `mail.notifications.from` (`MAIL_NOTIFICATION_FROM_ADDRESS/NAME`) sin modificar globalmente auth.
- Soporte usa `mail.reply_to` (`MAIL_REPLY_TO_ADDRESS/NAME`).

Sin cambiar `.env`, credenciales SMTP ni enviar correos reales. El transporte, los destinatarios reales y la ejecución de cola/scheduler se validan operativamente fuera de estas pruebas locales.

## Idempotencia

Se conserva la infraestructura existente: índice único sesión/tipo, `firstOrCreate`, dispatch después de commit, cierre con token idempotente, bloqueo y estado `processing` del job, y progreso `delivered_recipients`. Repetir el cierre, la autorización o un job terminado no vuelve a enviar. Un reintento tras fallo parcial omite destinatarios entregados. No se creó un sistema paralelo.

SMTP no ofrece una transacción compartida con la base de datos: una caída entre aceptación SMTP y persistencia de entrega mantiene la limitación previa de entrega exactamente una vez. La protección probada cubre reintentos normales, duplicación de jobs y progreso parcial persistido.

## Fuente única de documentos

`CashSession::documentsBreakdown()` conserva el contrato:

| Fuente | Conteo |
|---|---|
| Venta completada | 1 por venta |
| Abono CxC | 1 por abono |
| Abono de apartado | 1 por abono |
| Pago CxP | 1 por pago |
| SalePayment | No cuenta |
| CashMovement | No cuenta |

El accesor `documents_count`, `CashClosingSummaryService`, las vistas y el correo reutilizan este desglose. Se eliminó el conteo SQL duplicado de ventas del correo; la consulta restante solo obtiene su importe. Prueba con cuatro fuentes: dos ventas + un abono CxC + dos abonos de apartado + un pago CxP = seis documentos; agregar SalePayment y CashMovement no cambia el resultado. Otra sesión/empresa conserva su conteo independiente.

## Revisión del working tree

Revertida **solo** la ampliación de claves esperadas de búsqueda de clientes en `tests/Feature/PosAccessAndSearchTest.php`; ese archivo queda sin diferencias respecto de HEAD. No corresponde a Dashboard/Caja. `InventoryPostingService.php` permanece intacto.

El cronograma `Cronograma_Maestro_MVS_Commerce.xlsx` actualiza exclusivamente bloques 11–20: terminados localmente y validados, sin producción ni commit. No cambia IDs, orden ni tareas ajenas, incluido Notificaciones.

## Validación

- `php artisan test --filter="AdministrativeDashboardTest|CashSessionOpeningTest|CashBlindClosingTest|CashSessionMailNotificationTest"`: **52 aprobadas, 467 aserciones**.
- `php artisan test --filter="Cash"`: **154 pruebas, 151 aprobadas, 974 aserciones; 1 fallo y 2 errores preexistentes**.
- `npm.cmd run build`: correcto, **236 módulos**. Se usa el ejecutable de Windows equivalente a `npm run build`, sin cambiar la política de PowerShell.
- Pint focalizado y `git diff --check`: correctos.
- Evidencia local: `storage/logs/admin-cash-resume-focused.xml` y `storage/logs/admin-cash-resume-cash.xml`.

Fallos ajenos reproducidos en el filtro amplio y presentes en la referencia anterior:

1. `OrderPosCreationTest::test_pos_button_and_cashier_product_payload_are_permission_safe`: espera 200, recibe 302 por requisito de caja.
2. `PurchaseAccountPayableIntegrationTest::test_cash_purchase_does_not_create_account_payable`: método `InventoryPostingService::postPurchase()` ausente.
3. `PurchaseOrderConversionTest::test_cash_conversion_uses_purchase_processor_for_purchase_items_inventory_and_cost`: mismo método ausente.

No se corrigieron ni se ocultan; la suite global no se declara en verde. Pendiente validación operativa del usuario. No hubo commit, push, despliegue ni acceso a producción.
