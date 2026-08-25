# BeautyOS — planificación oficial

## Fuente de verdad

La fuente oficial de fases, tareas, IDs, dependencias, prioridades, entregables, criterios de aceptación y estado es [`BeautyOS_Cronograma_Maestro.xlsx`](BeautyOS_Cronograma_Maestro.xlsx).

Este documento resume decisiones transversales y facilita el relevo entre agentes. No sustituye ni duplica el cronograma detallado. Si existe una diferencia, se debe comprobar el Excel, Git, el código y las pruebas antes de corregir la documentación.

Reglas de planificación:

- preservar todos los IDs `Bxx`; nunca reutilizarlos ni renumerarlos;
- asignar tareas nuevas únicamente con IDs libres posteriores al máximo ocupado;
- ubicar cada tarea por fase y dependencias, aunque su ID sea posterior;
- no iniciar una fase solo por documentarla;
- distinguir decisiones aprobadas de hipótesis sujetas a validación.

## Estado confirmado

- **B05 — Diseñar modelo Professional/Profile/Specialty: COMPLETADO.** Evidencia: commit `d74aad6`, modelos `Professional` y `Specialty`, migración, fábricas y `BeautyProfessionalSpecialtyTest`.
- **B06 — Diseñar Service Catalog: COMPLETADO.** Base empresarial de servicios con duración, precio/costo decimal, preparación, buffers, estado y relación muchos-a-muchos con especialidades; aislamiento multiempresa protegido por modelo y base de datos. Evidencia: `Service`, migración, fábrica y `BeautyServiceCatalogTest`.
- **B07 — Diseñar Appointment/Booking: COMPLETADO.** Evidencia: commit `bfca7f6`.
- **B08 — Diseñar Portal Cliente y seguridad de tokens: COMPLETADO.** Evidencia: commit `8557fa8`.
- **B09 — Aprobar alcance MVP y lista NO-MVP: COMPLETADO.** Alcance aprobado el 25-08-2026 y registrado en el cronograma maestro.
- **Siguiente tarea: B10 — Crear módulo de profesionales. NO INICIADA.**
- Máximo original antes de esta ampliación: **B78**.
- IDs incorporados: **B79–B93**. Los detalles y el orden oficial están en la hoja `Cronograma` del Excel.

## Alcance B09 aprobado

MVP Professional:

- `B00–B17`;
- `B20–B28`;
- `B40`, `B41`, `B46`, `B47`;
- `B70–B74`;
- `B77–B81`.

Post-MVP Professional:

- `B30–B37`;
- `B42–B45`;
- `B50–B56`;
- `B60–B64`;
- `B75`, `B76`;
- `B82–B93`.

Reglas de alcance:

- `B41` es MVP; `B42–B45` son Post-MVP. `B43` solo puede adelantarse por una decisión posterior si el lanzamiento del plan Team exige comisiones.
- El piloto `B70` no depende de Academy y `B71` valida inicialmente dos rubros distintos.
- `B74` define planes Professional; Academy queda futura/hipótesis.
- `B75` y `B76` no son dependencias de `B77` ni del gate `B78`.
- `B79`, `B80` y `B81` son requisitos transversales obligatorios del MVP.

## Decisiones aprobadas

### Mobile-first

BeautyOS es mobile-first. Las operaciones diarias priorizan el celular. Toda UI futura debe validarse en móvil, tablet y escritorio; móvil puede disponer de flujos simplificados propios y no debe limitarse a ser un escritorio reducido.

### Identidad y arquitectura de producto

- `User` es la identidad del Core.
- `Student`, `Professional` e `Instructor` son perfiles o relaciones, no identidades duplicadas.
- La continuidad `Student → Graduate → Professional` preserva la misma identidad.
- El aislamiento multiempresa y multisucursal es estricto.
- BeautyOS es comercializable independientemente.
- Puede reutilizar un Core compartido, pero no depender de módulos exclusivos de MVS Commerce.
- El Core debe quedar preparado para RRHH, Contabilidad, TallerOS y futuros productos que puedan combinarse u operar independientemente.

### Portal, clientes y rubros del MVP

- El portal inicial no exige contraseña obligatoria; el acceso es seguro por token/QR.
- Cada cliente está aislado por empresa; no existe identidad global cross-business en el MVP.
- El núcleo común de servicios y citas está disponible para todos los rubros.
- Las fichas técnicas profundas de Nails, Lash, Hair, Barber y Estética quedan Post-MVP.
- Estética puede operar con servicios y citas base; las sesiones/paquetes especializados quedan Post-MVP.

### Privacidad Academy

Por su relación educativa, Academy puede conocer únicamente:

- matrícula;
- cursos;
- graduación;
- certificados;
- una señal autorizada de que el perfil profesional está activo.

Esa relación nunca concede acceso a ventas, clientes, agenda, inventario, ingresos, caja, facturación ni otros datos privados del negocio del egresado. Tampoco permite comercializar ni intercambiar datos privados.

### Contenido e IA

- Knowledge AI trabaja solo sobre contenido autorizado y conserva fuente, versión, permisos y trazabilidad.
- Brand Knowledge distingue marcas, líneas, productos, fichas, procedimientos y contenido oficial o verificado, con procedencia y vigencia.
- BeautyOS Templates es una biblioteca propia con procedencia y licencia; no puede reutilizar contenido privado de academias.
- La IA propone dentro de un flujo controlado: `importar → analizar → proponer → revisar → aprobar`.

## Fases Post-MVP de BeautyOS Academy

Academy completa queda Post-MVP Professional. El Excel conserva `B60–B64` y amplía la planificación, sin iniciar implementación, con estas dependencias lógicas:

1. privacidad e identidad;
2. Academy Core: programas, cursos, módulos, instructores, alumnos, matrículas, progreso y graduación;
3. importación de PDF, Word, Excel, imágenes y alumnos existentes, seguida del flujo de análisis, revisión y aprobación;
4. Student Hub: materiales, biblioteca, progreso y certificados;
5. Academy Lab aislado de los datos reales;
6. certificaciones verificables y Graduate Network;
7. Knowledge AI, Brand Knowledge y BeautyOS Templates.

## Hipótesis comerciales — no son precios ni condiciones definitivas

`Academy Founder/Partner` es una hipótesis que debe validarse antes de cualquier oferta. Puede explorar:

- un posible primer año gratuito o subsidiado;
- límites de almacenamiento y uso de IA;
- posibles créditos cuando graduados activen voluntariamente BeautyOS Professional;
- costos, elegibilidad, medición y controles antiabuso.

Ninguna variante puede basarse en comercializar o intercambiar datos privados. La activación profesional debe ser voluntaria. Los precios, beneficios, límites y créditos permanecen sin aprobar.
