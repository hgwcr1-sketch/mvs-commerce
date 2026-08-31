# MVS Commerce — Decisiones del proyecto

Este documento registra decisiones importantes que no deben ser revertidas por un agente sin revisar primero el contexto y obtener autorización cuando corresponda.

## D001 — Plataforma modular

MVS Commerce no se diseña únicamente como POS.

Debe evolucionar como plataforma empresarial modular capaz de integrar ventas, inventario, compras, clientes, proveedores, caja, fidelización, comercio electrónico, administración, IA y otros módulos.

---

## D002 — Multiempresa

Los datos empresariales deben mantenerse correctamente aislados por empresa.

No eliminar ni debilitar el uso de `company_id`, empresa activa o mecanismos equivalentes para simplificar implementaciones.

---

## D003 — Multisucursal

El sistema debe soportar múltiples sucursales.

El inventario pertenece a la sucursal cuando corresponda.

No convertir inventario por sucursal en inventario global para simplificar una funcionalidad.

---

## D004 — Fidelización empresarial

Los puntos de fidelización pertenecen al cliente dentro de una empresa.

Los puntos son globales entre sucursales de la misma empresa.

`branch_id` identifica el origen del movimiento, pero no crea saldos independientes por sucursal.

---

## D005 — Precisión monetaria

No utilizar `float` para cálculos monetarios o reglas sensibles de fidelización.

Utilizar mecanismos decimales y redondeo explícito según los servicios existentes.

Campos monetarios relevantes pueden utilizar `DECIMAL(19,4)`.

---

## D006 — Lógica centralizada

Las reglas de negocio importantes deben centralizarse en servicios reutilizables.

Antes de implementar una regla nueva:

1. buscar si ya existe un servicio;
2. reutilizarlo cuando sea apropiado;
3. evitar duplicar fórmulas en controladores, vistas o módulos distintos.

---

## D007 — Idempotencia

Operaciones que puedan ejecutarse más de una vez deben diseñarse para evitar duplicación cuando corresponda.

Especial atención a:

- ventas;
- inventario;
- fidelización;
- pagos;
- notificaciones;
- integraciones externas.

---

## D008 — Git como protección

Git y GitHub forman parte del sistema de protección del proyecto.

Antes de cambios importantes:

- revisar `git status`;
- preservar trabajo existente.

Después de puntos estables:

- pruebas;
- commit;
- push cuando sea autorizado.

Nunca utilizar `push --force` como solución rutinaria.

---

## D009 — Agentes intercambiables

MVS Commerce no debe depender de la memoria privada de una sola IA.

El proyecto podrá ser trabajado mediante:

- Codex / ChatGPT;
- OpenCode;
- Qwen;
- GLM;
- DeepSeek;
- otros modelos.

La memoria permanente debe residir en el repositorio.

Cada agente debe reconstruir contexto leyendo:

- `AGENTS.md`;
- documentación relevante;
- código;
- pruebas.

---

## D010 — Instalación controlada de dependencias

Ningún agente puede instalar paquetes, plugins, dependencias o herramientas dentro del proyecto sin autorización.

Antes de proponer una dependencia debe explicar:

- por qué hace falta;
- si ya existe una solución en el proyecto;
- impacto;
- mantenimiento;
- alternativa sin dependencia.

---

## D011 — Cambios mínimos

Una tarea no autoriza automáticamente un refactor general.

Preferir cambios pequeños, comprobables y reversibles.

No modificar código funcional ajeno a la tarea únicamente por preferencias de estilo.

---

## D012 — Pruebas antes de declarar terminado

Una implementación no se considera terminada solamente porque el código fue escrito.

Debe ejecutarse una combinación razonable de:

- pruebas específicas;
- regresión relacionada;
- validaciones de Blade cuando corresponda;
- frontend/Vite cuando corresponda;
- `git diff --check`.

---

## D013 — Integración de comercio electrónico

MVS Commerce deberá poder integrarse con tiendas en línea.

La arquitectura deberá contemplar sincronización de:

- productos;
- precios;
- inventario;
- imágenes;
- descripciones;
- ventas.

La fuente de inventario deberá ser explícita.

No implementar sincronización mediante actualizaciones duplicadas o sin trazabilidad.

---

## D014 — Módulos desarrollados externamente

Recursos Humanos/Planilla y Contabilidad pueden desarrollarse inicialmente fuera del repositorio principal.

Antes de crear funcionalidad equivalente dentro de MVS Commerce se debe revisar ese trabajo.

Objetivo:

> integrar, no duplicar.

---

## D015 — IA empresarial

La IA futura podrá analizar información de MVS Commerce y producir recomendaciones.

Ejemplos:

- cuánto comprar;
- productos con riesgo de agotarse;
- rotación;
- comportamiento de ventas;
- clientes que requieren seguimiento;
- oportunidades comerciales;
- preparación de mensajes;
- reportes gerenciales.

La IA debe trabajar sobre datos reales y respetar permisos.

---

## D016 — Acciones externas de IA

Analizar y recomendar no equivale automáticamente a ejecutar.

Acciones como:

- enviar mensajes;
- realizar compras;
- modificar precios;
- alterar inventario;
- generar movimientos financieros;
- comunicarse con clientes o proveedores

deberán disponer de controles y autorización apropiados.

---

## D017 — Automatización con supervisión

La plataforma podrá aumentar progresivamente el nivel de automatización.

La arquitectura debe permitir separar:

1. observar;
2. recomendar;
3. preparar una acción;
4. solicitar aprobación;
5. ejecutar;
6. registrar resultado.

Esto permitirá automatización futura sin perder control empresarial.

---

## D018 — Documentación selectiva

No registrar cada modificación pequeña en los documentos de memoria.

Actualizar documentación cuando cambie:

- arquitectura;
- estado importante de un módulo;
- decisión permanente;
- integración;
- regla empresarial relevante.

La documentación debe permanecer útil y manejable.

---

## D019 — Código sobre memoria conversacional

Si una conversación, un agente y el repositorio se contradicen:

1. detener la modificación;
2. inspeccionar código;
3. revisar pruebas;
4. revisar decisiones;
5. determinar el estado correcto.

Nunca modificar código únicamente porque una conversación antigua dice que debería funcionar de otra manera.

---

## D020 — Desarrollo paralelo seguro

Más de un desarrollador o agente puede trabajar simultáneamente.

Cada agente debe asumir que existen otros trabajos en curso.

No sobrescribir cambios ajenos.

Revisar siempre Git antes de modificar y antes de respaldar.

---

## D021 — Separación de Desarrollo y Producción

Desarrollo y Producción deben ser instalaciones independientes, con checkout, `.env`, base de datos y archivos operativos separados. Los datos productivos no se usan para desarrollo normal y una actualización nunca reemplaza la base productiva con `database.sqlite` de Desarrollo.

SQLite puede usarse provisionalmente en una sola PC y una sola instancia si la concurrencia real lo permite, con archivo local fuera del checkout y backup consistente. La elección de PostgreSQL/MySQL para VPS requiere decisión, drivers y ensayo de migración/conciliación; no se cambia automáticamente.

Los despliegues productivos requieren versión probada, backup verificado, fast-forward, migraciones no destructivas, build/caches, smoke tests y recuperación documentada. Dominio, TLS, red, secretos y servicios del sistema permanecen bajo activación humana.

---

## D022 — Producción cloud en PostgreSQL

La Producción principal de MVS Commerce se desplegará en cloud con PostgreSQL privado. SQLite permanece como motor normal de Desarrollo y solo como contingencia provisional de una única instancia; el código de negocio debe ser portable entre ambos motores.

Ninguna compatibilidad PostgreSQL se declara operativa solo por auditoría estática. Antes de datos reales se requieren migraciones/regresión, backup/restore aislado y carga controlada en staging PostgreSQL. Las herramientas de prueba rechazan bases no vacías o sin sufijos `_test`/`_restore_test` y nunca eliminan bases.

La seguridad HTTP incluye throttling por actor/IP, regeneración de sesión, cookies seguras en Producción y headers defensivos. Los límites de carga son métricas observadas, no promesas de capacidad máxima.

---

## D023 — Licencia SaaS empresarial separada de módulos y permisos

Existe una licencia única por empresa. Su estado y vigencia gobiernan el acceso operativo; `company_modules` gobierna funcionalidades contratadas y roles/permisos gobiernan acciones de usuarios. Suspender, vencer o cancelar nunca elimina datos. Solo Platform Admin cambia licencias, `branch_limit` y módulos; estas mutaciones se autorizan también en la capa de servicio y se aíslan por empresa. Ningún rol tenant puede conceder privilegios de plataforma. Toda transición de licencia queda auditada. El cobro recurrente queda fuera del MVP.

---

## D024 — Plan comercial como plantilla, licencia como contrato efectivo

`license_plans` define valores comerciales reutilizables. Aplicar una plantilla copia referencia, límites y módulos a la licencia de una empresa; desde ese momento el contrato puede tener overrides sin mutar la plantilla ni contratos de otros tenants. Solo Platform Admin administra ambos niveles. Los datos fiscales, sucursales y usuarios son operación del propietario tenant y permanecen visibles pero no editables desde el Panel Maestro.

## D025 — Ciclo contractual no destructivo y auditable

Trial, Active y Grace permiten operación; Expired, Suspended y Cancelled la bloquean sin eliminar empresas, usuarios, sucursales ni información operativa. Activación, renovación, suspensión, reactivación y cancelación pasan por `CompanyLicenseService` y registran estado anterior/nuevo, actor, fecha y cambios contractuales. La futura automatización por pago reutilizará el servicio después de verificar el cobro; M14 no acopla una pasarela.

---

# Regla para nuevas decisiones

Cuando aparezca una decisión arquitectónica importante, agregar una entrada:

`D0XX — Nombre`

Debe indicar:

- decisión;
- razón cuando no sea evidente;
- consecuencias relevantes.

No cambiar silenciosamente una decisión existente.
