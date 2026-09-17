# Guía visual MVS Commerce

## Identidad oficial

Fuente de verdad: `resources/css/app.css`, bloque `@theme`.
MVS Commerce usa **dorado**, sin sustituirlo por índigo o azul de marca.
Codex, OpenCode, MiMo, Cline y cualquier otro agente deben reutilizar estos tokens.

| Uso | Token CSS | Tailwind | Color |
|---|---|---|---|
| Acento principal | `--color-primary` | `bg-primary`, `text-primary`, `border-primary` | `#D4AF37`, RGB 212, 175, 55 |
| Hover | `--color-primary-hover` | `hover:bg-primary-hover` | `#B1922D`, RGB 177, 146, 45 |
| Éxito/conectado | `--color-success` | `text-success` | Verde, según función |
| Error/peligro | `--color-danger` | `text-danger` | Rojo, según función |
| Advertencia | `--color-warning` | `text-warning` | Amarillo/naranja, según función |

El ámbar `#F59E0B` es un color semántico de advertencia, no el dorado de marca.
El azul de información puede conservarse cuando comunica ese estado; no se usa
para reemplazar la identidad de un módulo. No introducir índigos arbitrarios.
Las configuraciones de marca de empresas clientes mantienen su contrato propio.

## Botones y contraste

Botón principal: `bg-primary text-slate-950 hover:bg-primary-hover`.
Focus visible: `focus-visible:outline focus-visible:outline-2
focus-visible:outline-offset-2 focus-visible:outline-primary`.
Usar también texto/iconos para comunicar estados, sin depender solo del color.

Sobre el dorado, negro ofrece aproximadamente 9,99:1 de contraste; blanco,
2,10:1, insuficiente para texto normal. Por ello los controles nuevos usan texto
oscuro. Esta guía no autoriza cambiar masivamente estilos de módulos ajenos.

## MVS Print

Configuración y descarga reutilizan los tokens Tailwind existentes. Los binarios
Windows toman el mismo dorado de `assets/branding.json` del repositorio
`mvs-print-installer`, que registra la fuente CSS; el build verifica los valores.
Los recursos de instalación reutilizan el logo MVS existente, sin recolorear QZ.
El launcher tiene identidad y metadatos propios; QZ conserva su identidad original.

## Cursor global

Controles interactivos habilitados usan `cursor: pointer`; deshabilitados usan
`cursor: not-allowed`. La regla vive en `resources/css/app.css` (sección
GLOBAL CURSOR RULE) y cubre `button:not(:disabled)`, `a[href]`,
`[role="button"]:not([aria-disabled="true"])`, `summary`, `select`,
`input[type="checkbox"]` e `input[type="radio"]`.

NO agregar cursor de mano sobre texto, labels sueltos o contenedores
decorativos. NO modificar Blade por Blade salvo excepción justificada.

## Responsive

Diseñar primero a 360 px, ampliar a 768 y 1280 px. Controles táctiles de al menos
44 px, texto ajustable, formularios en una columna móvil y tablas dentro de
`overflow-x-auto`. No introducir scroll horizontal de página. Conservar verde
para conectado/éxito y rojo para error, también en móvil.
