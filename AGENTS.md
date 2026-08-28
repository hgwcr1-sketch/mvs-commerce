# MVS Commerce — Guía para agentes de IA

## 1. Propósito

MVS Commerce es una plataforma empresarial modular orientada a comercios.

Su objetivo no es ser únicamente un POS o sistema de facturación. Debe evolucionar como una plataforma capaz de centralizar operaciones comerciales, inventario, ventas, compras, clientes, proveedores, fidelización, finanzas, recursos humanos, comercio electrónico, automatización e inteligencia artificial.

Lema del producto:

> Profesional por dentro. Sencillo por fuera.

La aplicación está orientada inicialmente al mercado de Costa Rica, pero su arquitectura debe evitar dependencias innecesarias que impidan una futura expansión.

---

## 2. Stack actual

Antes de modificar código, verificar siempre el estado real del repositorio.

Stack base actual:

- Laravel
- PHP
- Blade
- Tailwind CSS
- Vite
- SQLite en desarrollo
- Git + GitHub
- interfaz principal en español

No asumir versiones específicas sin verificarlas en el proyecto.

---

## 3. Principio fundamental para agentes

El código y la documentación del repositorio son la fuente de verdad.

No depender de la memoria de una conversación anterior.

Antes de realizar una tarea:

1. Leer este `AGENTS.md`.
2. Revisar los documentos de `docs/` relacionados con la tarea — incluyendo obligatoriamente `docs/CRONOGRAMA_PRODUCCION.md` cuando la tarea toque Portal de Clientes o Puesta en Producción — y no saltar bloques sin autorización.
3. Revisar `docs/ESTADO_ACTUAL.md` para conocer el estado de relevo entre agentes, especialmente al iniciar una sesión o al tomar una tarea que otro agente dejó a medias.
4. Inspeccionar el código real involucrado.
5. Revisar `git status`.
6. Entender la implementación existente antes de modificarla.
7. Hacer cambios mínimos y compatibles con la arquitectura existente.
8. Ejecutar pruebas relevantes.
9. Informar claramente qué se modificó y qué quedó pendiente.

Nunca inventar arquitectura, tablas, servicios, permisos, rutas o reglas de negocio sin comprobar primero lo existente.

---

## 4. Protección del proyecto

MVS Commerce es un proyecto grande en desarrollo activo.

Reglas obligatorias:

- No borrar código funcional para reemplazarlo innecesariamente.
- No hacer refactors amplios si la tarea no los requiere.
- No modificar módulos ajenos a la tarea sin una razón comprobable.
- No instalar paquetes o dependencias sin autorización.
- No ejecutar migraciones destructivas sin autorización.
- No borrar datos de desarrollo sin autorización.
- No cambiar configuraciones globales del entorno sin autorización.
- No modificar secretos, credenciales o archivos `.env` salvo instrucción explícita.
- No hacer `git push --force`.
- No reescribir historial Git.
- No eliminar ramas.
- No hacer commit o push automáticamente salvo instrucción explícita.
- No asumir que un archivo sin seguimiento puede eliminarse.
- Si existen cambios locales previos que no pertenecen a la tarea, preservarlos.

Si una acción puede causar pérdida de trabajo, detenerse y explicarla antes de ejecutarla.

---

## 5. Forma de trabajar

Preferir:

- cambios pequeños;
- implementación incremental;
- reutilizar servicios existentes;
- reglas de negocio centralizadas;
- operaciones monetarias con precisión decimal;
- aislamiento correcto por empresa;
- aislamiento por sucursal cuando corresponda;
- autorización mediante permisos;
- pruebas automatizadas;
- idempotencia cuando una operación pueda repetirse;
- trazabilidad de operaciones importantes.

Evitar:

- duplicación de lógica;
- floats para dinero;
- lógica crítica dispersa en vistas;
- consultas sin ámbito empresarial cuando deberían tenerlo;
- soluciones temporales que rompan arquitectura existente;
- cambios especulativos.

---

## 6. Arquitectura empresarial

MVS Commerce debe mantenerse preparado para trabajar con:

- múltiples empresas;
- múltiples sucursales;
- múltiples usuarios;
- roles y permisos;
- inventario por sucursal;
- ventas y POS;
- caja;
- clientes;
- proveedores;
- compras;
- cuentas por cobrar;
- cotizaciones;
- fidelización;
- comercio electrónico;
- reportes;
- automatización;
- integraciones externas;
- inteligencia artificial.

Algunos módulos pueden estar terminados, otros en desarrollo y otros únicamente planificados.

Consultar `docs/PROGRESO.md` antes de asumir el estado de un módulo.

---

## 7. Integraciones futuras

La arquitectura debe permitir que MVS Commerce pueda conectarse con tiendas en línea.

Una meta importante es integrar inventario, productos, precios, imágenes y ventas entre MVS Commerce y canales de comercio electrónico.

Las ventas realizadas en diferentes canales deberán afectar correctamente el inventario correspondiente sin duplicar operaciones.

No implementar estas integraciones basándose únicamente en esta descripción. Consultar las decisiones y especificaciones correspondientes cuando se desarrollen.

---

## 8. Inteligencia artificial

La IA será una capa adicional de MVS Commerce, no la fuente de verdad del sistema.

A futuro podrán existir agentes especializados para:

- programación;
- análisis de ventas;
- inventario;
- recomendaciones de compra;
- detección de productos con bajo stock;
- análisis de rotación;
- clientes;
- fidelización;
- marketing;
- generación de reportes;
- preparación de comunicaciones;
- apoyo administrativo.

Los agentes deberán basar sus conclusiones en datos reales del sistema y respetar permisos.

Las acciones externas o sensibles deberán diseñarse con controles y autorización apropiados.

---

## 9. Desarrollo paralelo

Existen módulos empresariales que pueden desarrollarse en repositorios o líneas de trabajo independientes antes de integrarse a MVS Commerce.

En particular existen desarrollos paralelos relacionados con:

- Recursos Humanos / planilla.
- Contabilidad.

No crear desde cero estos módulos dentro de MVS Commerce sin revisar primero el estado de esos proyectos y el plan de integración.

La integración futura debe evitar duplicación de modelos, reglas y responsabilidades.

Consultar `docs/INTEGRACIONES.md`.

---

## 10. Documentación permanente

La memoria compartida del proyecto se divide en:

### `AGENTS.md`

Reglas generales que cualquier agente debe leer primero.

### `docs/ARQUITECTURA.md`

Arquitectura técnica, componentes principales y convenciones.

### `docs/PROGRESO.md`

Estado real de módulos, fases terminadas, trabajo actual y próximos pasos.

### `docs/DECISIONES.md`

Decisiones importantes y razones para evitar que otro agente las revierta sin contexto.

### `docs/NEGOCIO.md`

Visión del producto, procesos empresariales y reglas generales del negocio.

### `docs/INTEGRACIONES.md`

Sistemas externos, proyectos paralelos e integraciones actuales o futuras.

### `docs/ESTADO_ACTUAL.md`

Fotografía corta del estado del relevo entre agentes: rama actual, estado del repositorio, prioridades, último trabajo terminado, trabajo en curso y próximos pasos. Debe leerse al comenzar una sesión y mantenerse actualizada tras tareas importantes.

### `docs/CRONOGRAMA_FIDELIZACION.md`

Control permanente de fases del módulo de Fidelización: orden previsto, estado confirmado por evidencia y próxima fase candidata. Refleja el Cronograma Maestro (`docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`), que es la fuente oficial del orden y la numeración de fases.

Regla específica de Fidelización:

> Toda tarea de Fidelización debe comenzar leyendo `docs/CRONOGRAMA_FIDELIZACION.md`, `docs/ESTADO_ACTUAL.md` y la sección correspondiente de `docs/PROGRESO.md`. El cronograma determina el orden previsto, mientras Git, código y tests determinan qué está realmente implementado. Ningún agente puede saltar, retroceder, renumerar o inventar fases sin autorización. Si descubre una funcionalidad adelantada, la marca como completada adelantadamente con su evidencia, pero eso NO cambia cuál fase corresponde ejecutar a continuación según el cronograma.

### `docs/CRONOGRAMA_PRODUCCION.md`

Fuente oficial del orden P01–P50 de Portal de Clientes + Correcciones + Migración + Fidelización pendiente (refleja el Excel único `docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`). Reemplaza los cronogramas anteriores (`docs/produccion/Cronograma_Maestro_Puesta_Produccion_MVS_Commerce_v7.xlsx`, `docs/Cronograma_Correcciones_Produccion_MVS_Commerce_29-08-2026.xlsx` y la parte general de `docs/Cronograma_Maestro_Fidelizacion_MVS_Commerce_Actualizado_23-08-2026.xlsx`).

- P01–P04 COMPLETADOS, **P05 es el siguiente bloque**, no reutilizar numeraciones antiguas.
- P09A, P09B, P09C y P09D conservan esos IDs exactos.
- P22 = Separación Platform Admin / Tenant Admin, COMPLETADO; P23 = Onboarding empresa + sucursales + primer administrador, COMPLETADO.
- Migración completa P31–P40 queda después de los bloques existentes, sin reutilizar IDs.
- Fidelización pendiente solo P41–P48 si sigue pendiente según código/tests (no reconstruir F01–F18, F28–F29 ya completados).

Regla específica de Producción/Portal:

> Toda tarea de Portal/Puesta en Producción debe comenzar leyendo `docs/CRONOGRAMA_PRODUCCION.md` (fuente oficial) y el Excel único como referencia visual (`docs/Cronograma_Unico_Portal_Correcciones_MVS_Commerce_28-08-2026.xlsx`), además de `docs/ESTADO_ACTUAL.md` y la sección correspondiente de `docs/PROGRESO.md`. El cronograma determina el orden previsto, mientras Git, código y tests determinan qué está realmente implementado. Ningún agente puede usar cronogramas Excel anteriores ni renumerar tareas; si descubre funcionalidad adelantada, la marca como completada adelantadamente con su evidencia, pero eso NO cambia cuál bloque corresponde ejecutar a continuación.

No llenar `AGENTS.md` con detalles que correspondan a esos documentos.

---

## 11. Mantenimiento de la memoria

Cuando una tarea produzca un cambio importante:

- evaluar si cambia arquitectura;
- evaluar si cambia progreso;
- evaluar si establece una decisión permanente;
- evaluar si afecta una integración;
- actualizar únicamente la documentación correspondiente.

No registrar cada pequeño cambio de código.

La documentación debe mantenerse útil, concisa y actualizada.

---

## 12. Trabajo entre diferentes modelos

Este proyecto puede ser trabajado por distintos agentes y modelos de IA.

Ejemplos:

- Codex / ChatGPT;
- OpenCode;
- Qwen;
- GLM;
- DeepSeek;
- otros modelos compatibles.

Ningún modelo debe asumir que es el único agente trabajando en el proyecto.

Al comenzar una nueva sesión o tomar una tarea a medias, reconstruir el contexto desde el repositorio y su documentación, comenzando por `AGENTS.md` y `docs/ESTADO_ACTUAL.md`.

Al finalizar una tarea importante, dejar el repositorio y la documentación en un estado que otro agente pueda comprender y continuar.

---

## 13. Interfaz responsive (reglas permanentes)

Todo desarrollo nuevo debe ser responsive desde el inicio, priorizando el celular en las operaciones de uso diario.

Reglas mínimas obligatorias:

- **Mobile-first para pantallas P0** (POS, caja, clientes, consulta de producto, portal): se diseña primero a 360px y luego se expande con breakpoints (`sm/md/lg/xl`), nunca al revés.
- **Targets táctiles**: todo control interactivo idealmente ≥44px (mínimo absoluto 40px), con separación suficiente entre acciones adyacentes.
- **Tablas**: siempre dentro de `overflow-x-auto`; columnas secundarias con `hidden md:table-cell`; cuando la fila exige acciones repetitivas (POS, oportunidades), versión tarjeta en móvil.
- **Formularios**: una columna en móvil → grids superiores (`md:grid-cols-*`); inputs `w-full`; montos con `inputmode` apropiado y alineación a la derecha.
- **Modales**: patrón mobile-safe ya establecido en POS — `fixed inset-0`, `max-h-[90vh]` con scroll interno, cierre ≥44px, padding `p-3 sm:p-5`.
- **Navegación responsive según dispositivo**: barra inferior <768px, rail de iconos 768–1023px, sidebar compacto expandible ≥1024px (ver `docs/ARQUITECTURA.md`, sección Navegación responsive). Ninguna vista nueva puede asumir sidebar ancho permanente.
- **Sin overflow horizontal a nivel página**: el scroll horizontal solo vive dentro de contenedores de tabla declarados.
- **CTA persistente**: en flujos largos (carrito, multi-paso), la acción primaria debe permanecer visible sin depender del scroll.
- **Verificación conceptual 360 / 768 / 1280** antes de cerrar cualquier tarea que toque UI; desktop y responsive se desarrollan juntos, no como corrección posterior.
- **Fuente única de navegación**: el menú vive en `components/navigation/sidebar.blade.php` (reutilizado por la barra "Más" móvil/tablet). Al agregar módulos o permisos, actualizar solo esa fuente.

---

## 14. Regla final

Antes de escribir código:

> Leer → comprobar → entender → modificar → probar → documentar.

Cuando exista contradicción entre una conversación anterior y el estado actual del repositorio, detenerse, comprobar la evidencia y privilegiar el código, las pruebas y las decisiones documentadas.