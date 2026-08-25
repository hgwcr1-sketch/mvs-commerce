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
- **B06 — Diseñar Service Catalog: NO INICIADO.** Continúa como la siguiente tarea funcional prevista; esta sincronización es exclusivamente documental y no la inicia.
- Máximo original antes de esta ampliación: **B78**.
- IDs incorporados: **B79–B93**. Los detalles y el orden oficial están en la hoja `Cronograma` del Excel.

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

## Fases planificadas de BeautyOS Academy

El Excel conserva el Academy MVP existente (`B60–B64`) y amplía la planificación, sin iniciar implementación, con estas dependencias lógicas:

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

