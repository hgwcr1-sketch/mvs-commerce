# Pago monetario con puntos en POS — 2026-09-14

Base: `feature/pos`, `57dd07d`, árbol limpio al iniciar. Paso 32 pausado por instrucción del usuario.

EXISTING_REDEEM_ARCHITECTURE: se reutilizan LoyaltyAccount, LoyaltyMovement, LoyaltyPosSummaryService, LoyaltyPointValueService, elegibilidad, límites y LoyaltyRedemptionService; PaymentMethod de tipo loyalty_points y SalePayment ya existen.

IMPLEMENTATION: se permite `payments: []` con intención de canje; el backend exige cobertura exacta. El checkout permite confirmar solo con puntos y bloquea entradas inválidas/excesivas sin reducir silenciosamente la cantidad solicitada. Total aplicado incluye puntos.

POINT_BALANCE: consulta empresarial existente; el servicio de cuenta descuenta bajo bloqueo y rechaza saldo insuficiente.

POINT_VALUE: configuración empresarial real; normalización de puntos y comparación del restante mediante BCMath a cuatro decimales. Los cálculos generales históricos del POS no se refactorizan.

MINIMUM_REDEEM: se conservan redemption_minimum_enabled y redemption_minimum_amount, elegibilidad y excepciones existentes.

PARTIAL_REDEMPTION: reutiliza el canje y los pagos normales existentes.

FULL_REDEMPTION: probado con venta de 1000, valor de punto 2, canje de 500 y ningún pago normal. Sin vuelto ni efecto en efectivo.

MIXED_PAYMENT: conserva efectivo, tarjeta, SINPE y otros métodos activos. Crédito mantiene su restricción V1 existente, sin mezcla.

BACKEND_VALIDATION: cliente obligatorio, ámbito empresarial, configuración, saldo, mínimo, límite, puntos positivos y cobertura exacta. No acepta equivalencia enviada por cliente.

ATOMIC_TRANSACTION: misma transacción de PosSaleProcessor; desajustes de cobertura revierten venta, pagos y movimiento de puntos.

IDEMPOTENCY: checkout_token y fingerprint existentes; evento sale:{id}:loyalty:redemption. Prueba de canje total y retry equivalente 500/500.0000 sin duplicados. No se realizó prueba multiproceso.

LOYALTY_MOVEMENT: tipo redemption, puntos negativos, valor y referencia a Sale; pago de puntos sin efecto en efectivo.

EARN_POINTS_AFTER_REDEMPTION: acumulación existente posterior al canje conservada y probada en pago total.

RETURN_COMPATIBILITY: BLOQUEADA. SaleReturnService llama a InventoryPostingService::saleReturn() y SaleVoidService llama a ::voidSale(), ambos ausentes. El ajustador de fidelización reconoce redemption, pero las pruebas integrales fallan antes de completarse. No se amplió alcance a inventario/devoluciones.

MULTITENANT: servicios existentes por empresa; pruebas de aislamiento de saldo, cliente ajeno y canje multisucursal ejecutadas.

NORMAL_POS_UNCHANGED: se conservan pagos normales y USD; la regresión incluye un fallo de texto esperado en comprobante, fuera de este parche.

UI: panel existente dentro del checkout, saldo, valor, mínimo, máximo, puntos solicitados y restante. Validación explícita y confirmación sin pago normal para canje total.

RESPONSIVE: revisión conceptual 360/768/1280; se conserva grid responsive, input w-full y min-h-11. JavaScript renderizado ejecutado con Node. Sin validación visual en navegador.

TESTS: focal final POS/Loyalty/valor/mínimo/límites: 51/51, cero fallos, incluye JavaScript renderizado con Node. Ejecución ampliada Loyalty/POS/límites/multisucursal: 55/56; error en premio por InventoryPostingService::postRewardRedemption() ausente. Regresión POS/Loyalty/límites/USD: 75/76. Devoluciones/anulaciones: 13 errores por métodos ausentes. Build Vite, lint PHP y git diff --check correctos.

ASSERTIONS: 373 en la focal final; 406 en la ampliada; 590 en POS/Loyalty/USD. Las ejecuciones se solapan; no sumar como cobertura única.

FAILURES: comprobante espera texto de advertencia ausente; métodos saleReturn, voidSale y postRewardRedemption ausentes. La prueba nueva de canje total reprodujo primero 422 por payments requerido y después pasó con el cambio.

FILES_CHANGED: app/Http/Requests/StorePosSaleRequest.php; app/Services/Sales/PosSaleProcessor.php; resources/views/pos/index.blade.php; tests/Feature/PosCheckoutLoyaltyRedemptionTest.php; tests/Feature/PosLoyaltyInterfaceTest.php; tests/js/pos-loyalty.cjs; docs/POS_CANJE_PUNTOS.md; docs/ESTADO_ACTUAL.md.

MIGRATION_REQUIRED: NO.

PROBLEMS: pendiente resolver el bloqueo de devoluciones/anulaciones bajo alcance autorizado y validación visual local. Los pagos normales siguen requiriendo colones enteros; el aviso existente bloquea restos fraccionarios. No se inventó redondeo ni se ampliaron reglas monetarias generales.

CIERRE: auditoría final aprobada por el usuario. Canje parcial/total/mixto, autoridad backend, atomicidad, idempotencia y aislamiento empresarial confirmados. Los problemas de devoluciones, anulaciones, premios y texto del comprobante se aceptan como preexistentes y fuera de alcance; no bloquean el cierre de este cambio.

LISTO_PARA_AUDITORIA: SI, auditoría final aprobada. Commit y push a feature/pos autorizados con mensaje feat(pos): support loyalty points payments. Sin migración ni despliegue a producción.
