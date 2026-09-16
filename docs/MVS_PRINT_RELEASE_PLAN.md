# MVS Print — Release Plan

> Fuente única de verdad para terminar MVS Print como distribución profesional.
> Última actualización: 2026-09-16

---

## Estado Actual

| Artefacto | Versión | SHA256 | Authenticode | SmartScreen |
|---|---|---|---|---|
| MVS-Print-Setup.exe | 1.0.2 | `A17FB8EA...b8f3` | **NotSigned** | Bloqueado |
| MVS Print.exe | 1.0.2 | `56CA7E4A...88b9c` | **NotSigned** | N/A (embebido) |

**Publicado en:** `https://app.mvscommerce.com/mvs-print/MVS-Print-Setup.exe?v=1.0.2`
**Producción HEAD:** `d03a0fb` (feature/pos)

---

## COMPLETADO

### Build & Sources (1.0.2)
- [x] Launcher.cs v1.0.2.0 — Mutex, CreateNoWindow, QZ detection, WebSocket probe
- [x] Trust.cs — Certificate management, private key rejection
- [x] LauncherTests.cs — 7 unit tests passing
- [x] mvs-print-installer.nsi — NSIS wrapper, Spanish, Program Files x64
- [x] build-launcher.ps1 — Compiles launcher + tests
- [x] build-installer.ps1 — Builds QZ repackaged + MVS wrapper
- [x] build.ps1 — Full pipeline entry point
- [x] branding.json — Dorado #D4AF37 / hover #B1922D
- [x] mvs-public-certificate.pem — QZ trust cert (NOT code signing)
- [x] license.txt — Spanish EULA
- [x] ATTRIBUTION.txt — QZ Tray LGPL-2.1 credits
- [x] launcher.manifest — 1.0.2.0, asInvoker
- [x] logo-mvs-corto.png — Brand logo
- [x] VERSION file — 1.0.2
- [x] All sources committed to feature/pos (d03a0fb)

### MVS Commerce Integration
- [x] config/mvsprint.php — env-based download_url, version, sha256
- [x] MvsPrintDownloadController — 302 redirect, 404 fallback
- [x] impresion.blade.php — bg-primary dorado button, version display
- [x] .env — MVS_PRINT_VERSION=1.0.2, SHA256, URL configurada
- [x] Rutas: 13 rutas MVS Print funcionando
- [x] Permisos: mvs.print.configurar, mvs.print.imprimir
- [x] PHP tests: 35/35 passing (MvsPrintDownloadTest + MvsPrintTerminalsTest)
- [x] JS tests: 25/25 passing (mvs-print-security-test)
- [x] npm build: PASS

### Production Deployment
- [x] Installer uploadado a producción (SHA256 verificado)
- [x] .env actualizado en producción
- [x] Fast-forward exitoso fbc1bb5 → d03a0fb
- [x] npm build + optimize:clear + optimize en producción
- [x] Download HTTP 200, Content-Length correcto
- [x] Download SHA256 == Build SHA256 verificado

### Code Fixes (Auditoría 2026-09-16)
- [x] Trust.cs — File write atómico (temp + move)
- [x] NSIS uninstaller — taskkill antes de borrar archivos

---

## PENDIENTE — Firma de Código (BLOQUEADOR #1)

### Problema
- MVS-Print-Setup.exe y MVS Print.exe están **NotSigned**
- Chrome muestra "Descarga sospechosa bloqueada"
- Windows SmartScreen bloquea la aplicación
- No se puede distribuir profesionalmente sin firma

### Solución Requerida: Certificado de Firma de Código

#### Opción A: OV Certificate (Organization Validation)
| Aspecto | Detalle |
|---|---|
| Costo | $80–300/año |
| Proceso | Validación de identidad empresarial (3-10 días hábiles) |
| SmartScreen | **NO resuelve inmediatamente** — requiere reputación acumulada |
| Chrome | Advertencias iniciales, mejora con descargas |
| Requisitos | Empresa registrada, teléfono verificable, dominio |
| Timeline | 1-2 semanas para obtener |
| Resultado | "Publisher verificado" en UAC, menor tasa de falso positivo antivirus |

#### Opción B: EV Certificate (Extended Validation) — RECOMENDADA
| Aspecto | Detalle |
|---|---|
| Costo | $250–500/año + token hardware |
| Proceso | Validación extendida (5-15 días hábiles, puede ser 2-4 semanas) |
| SmartScreen | **RESUELVE INMEDIATAMENTE** — confianza desde el día 1 |
| Chrome | Resuelve inmediatamente |
| Requisitos | Empresa legal verificable en bases de datos tier-1 (D&B, Registro Nacional), token FIPS 140-2 |
| Timeline | 2-4 semanas para obtener |
| Resultado | Sin advertencias, distribución profesional limpia |

**Para una empresa pequeña en Costa Rica con volumen inicial bajo: EV es la única opción real para confianza inmediata de SmartScreen.**

### Pipeline de Firma Requerido

```
source → build MVS Print.exe → tests → SIGN launcher → verify
→ incorporate signed launcher → build MVS-Print-Setup.exe
→ SIGN installer → timestamp (RFC 3161) → verify /pa
→ SHA256 FINAL → release metadata → upload → verify download
```

**IMPORTANTE:** El SHA256 publicado debe calcularse DESPUÉS de la firma final.

### Proveedores de Certificados (Referencia)
- DigiCert: ~$350/año EV, token SafeNet eToken
- Sectigo: ~$300/año EV
- GlobalSign: ~$350/año EV

### Timestamp Servers (RFC 3161)
- `http://timestamp.digicert.com` (más confiable)
- `http://timestamp.sectigo.com`
- `http://timestamp.entrust.net/TSS/RFC3161sha2TS`

---

## PENDIENTE — Cambios de Código (Menores)

### S1. Launcher.cs — Async probe sincronizado
- `Probe(port).GetAwaiter().GetResult()` bloquea el thread
- Riesgo: thread pool exhaustion en máquinas lentas
- Severidad: Sugerencia (no bloqueador)
- Fix: Hacer `Ready()` completamente async o usar `Task.Run`

### S2. NSIS — Sleep 1500 hardcoded
- Después de `--steal`, sleep fijo de 1500ms
- Riesgo: insuficiente en máquinas lentas, desperdicio en rápidas
- Severidad: Sugerencia
- Fix: Polling loop con `--verify` N intentos

### S3. build.ps1 — Referencia a scripts\build-installer.ps1
- Línea 150 referencia `scripts\build-installer.ps1` que no existe
- El archivo está en `storage/app/mvs-print-release/build-installer.ps1`
- Severidad: Nit (funciona porque se invoca directamente)

---

## PENDIENTE — Prueba Física

### Requisitos
- PC en Liberia con Windows 10/11
- Impresora térmica conectada
- Sin QZ Tray previamente instalado (clean install)
- Acceso a internet para descarga

### Checklist de Prueba
- [ ] Descargar MVS-Print-Setup.exe desde producción
- [ ] Chrome/Safari: sin bloqueo de descarga
- [ ] SmartScreen: sin advertencia (requiere EV)
- [ ] Ejecutar installer como administrador
- [ ] Instalación en C:\Program Files\MVS Print\
- [ ] MVS Print.exe presente
- [ ] uninstall.exe presente
- [ ] licenses\ presente
- [ ] version = 1.0.2
- [ ] mvs-public-certificate.crt presente
- [ ] Start Menu shortcut creado
- [ ] Desktop shortcut creado
- [ ] Add/Remove Programs muestra "MVS Print 1.0.2"
- [ ] QZ Tray 2.2.6 instalado automáticamente
- [ ] MVS Print.exe no abre CMD
- [ ] Doble ejecución no duplica QZ
- [ ] QZ arranca en background
- [ ] Allowed list/certificado funcionando
- [ ] Impresión de prueba exitosa
- [ ] Reimpresión funciona
- [ ] No abre cajón al reimprimir
- [ ] Desinstalación limpia
- [ ] Reinstalación exitosa

---

## BLOQUEADO POR ACCIÓN HUMANA

1. **Certificado de firma de código** — Requiere decisión de compra (OV vs EV)
2. **Token hardware** — RequiereEV, envío físico
3. **Prueba física Liberia** — Requiere presencia física
4. **Decisión de distribución** — ¿Todos los clientes o pilot controlado?

---

## PIPELINE DEFINITIVO DE RELEASE

Para futuras versiones, el pipeline debe ser:

```
1. Código fuente versionado (git tag vX.Y.Z)
2. Build MVS Print.exe (build-launcher.ps1)
3. Tests launcher (LauncherTests.exe)
4. SIGN MVS Print.exe (signtool + EV cert + timestamp)
5. Verificar firma (signtool /pa /v)
6. Incorporar launcher firmado al installer
7. Build MVS-Print-Setup.exe (build-installer.ps1)
8. SIGN MVS-Print-Setup.exe (signtool + EV cert + timestamp)
9. Verificar firma installer (signtool /pa /v)
10. Calcular SHA256 FINAL (post-firma)
11. Generar release.json con metadata
12. Upload a producción
13. Verificar download HTTP 200 + SHA256 match
14. Actualizar .env en producción
15. Deploy código (fast-forward)
16. Verificar en producción
17. Prueba en máquina limpia
```

---

## MÉTRICAS DE CALIDAD

| Métrica | Actual | Meta |
|---|---|---|
| PHP tests | 35/35 PASS | 100% |
| JS tests | 25/25 PASS | 100% |
| npm build | PASS | PASS |
| Hash match | SI | SI |
| Authenticode | NotSigned | EV Signed |
| SmartScreen | Bloqueado | Trust inmediato |
| Chrome | Bloqueado | Sin advertencia |
| Instalación | Manual | Automática |
| Distribución | Servidor propio | Profesional |

---

## PRÓXIMOS PASOS INMEDIATOS

1. **Decidir OV vs EV** — Si EV, iniciar proceso de validación hoy
2. **Integrar signtool** en build-installer.ps1 y build-launcher.ps1
3. **Firmar binarios** con certificado obtenido
4. **Re-construir** con binarios firmados
5. **Calcular nuevo SHA256** post-firma
6. **Publicar** binario firmado
7. **Prueba física** en Liberia
