PLAN MAESTRO AJUSTADO
SINCRONIZACIÓN DE MOODLEWEBTOOLKIT CON RECOLECTOR 7.4.2 Y CONSOLIDADOR V8

## 1. Objetivo general

Adaptar MoodleWebToolkit a las distribuciones definitivas del Recolector 7.4.2 y del Consolidador V8 que ahora están incorporadas en `BaseLine/` y verificadas contra los árboles fuente del Anexo de Fase 0. `BaseLine/` seguirá montada como solo lectura durante la ejecución. Completar la integración real que quedó pendiente después de las iteraciones 1A–1G y alcanzar una versión estable para pruebas internas y controladas en AWS.

El orden de prioridad será:

1. Sincronizar contratos y catálogo con las herramientas actuales.
2. Implementar infraestructura de ejecución real y durable.
3. Integrar completamente el Recolector.
4. Integrar completamente el Consolidador V8.
5. Estabilizar el recorrido Recolector → Consolidador.
6. Ejecutar pruebas controladas en AWS.
7. Actualizar e integrar el Integrador Incremental al final.

El Integrador no bloqueará el desarrollo ni las pruebas del flujo principal.

---

## 2. Estado de partida

MoodleWebToolkit ya cuenta con:

- autenticación y roles;
- proyectos y asignaciones;
- wizard persistente;
- preflight;
- ejecuciones y comandos;
- máquina de estados;
- eventos persistentes;
- Redis Queue;
- workers acotados;
- scheduler;
- outbox;
- leases;
- idempotencia;
- checkpoints;
- reanudación;
- cancelación cooperativa;
- intervenciones;
- verificación;
- revisión;
- cierre;
- artefactos;
- Reverb y polling de respaldo;
- autorización temporal para modificar;
- seguimiento de ejecuciones superiores a 24 horas;
- PostgreSQL como fuente de verdad;
- pruebas PHPUnit, Vitest y Playwright;
- CI e instalación limpia;
- `BaseLine/` protegida como solo lectura.

Sin embargo, la plataforma sigue utilizando:

- `FakeToolAdapter`;
- `FakeExecutionProvider`;
- escenarios simulados;
- checkpoints simulados;
- eventos simulados;
- artefactos simulados.

La vertical 1A–1G debe conservarse como infraestructura, cobertura de regresión y modo demostrativo. No debe eliminarse de manera anticipada.

---

## 3. Herramientas prioritarias

### Recolector

Versión objetivo inicial:

`Recolector 7.4.2`

La distribución 7.4.2 está incorporada en `BaseLine/Recolector/Recolector-v7.4.2`. Su versión, manifiesto y árbol se registran en el Anexo de Fase 0. Para cerrar la revisión de Fase 0, confirmar en esta distribución:

- versión exacta;
- manifiestos;
- hashes;
- comandos;
- argumentos;
- dependencias;
- archivos de configuración;
- formatos de estado;
- checkpoints;
- capacidades;
- inventario visual;
- compatibilidad con paquetes 7.4.1;
- comportamiento de reanudación;
- validación de paquetes;
- salida y artefactos.

### Consolidador

Versión objetivo:

La versión identificada es `8.0.0-linux-rc12`, localizada en `BaseLine/Consolidador/Consolidador-v8.0.0` y cotejada con el árbol fuente documentado en el Anexo de Fase 0. Es una candidata de cierre técnico, no una etiqueta estable permanente.

No se debe asumir una RC específica tomando únicamente el nombre de una carpeta o documentación anterior.

Se debe identificar:

- versión exacta;
- commit o build;
- manifiesto;
- árbol canónico;
- capacidades;
- fases implementadas;
- contratos de configuración;
- archivos de estado;
- checkpoints;
- intervenciones;
- restauración por curso;
- planificación de workers;
- plugins;
- temas;
- identidades;
- verificación;
- cierre académico;
- backup;
- publicación;
- detención.

Mientras V8 continúe evolucionando, MoodleWebToolkit deberá identificarla mediante catálogo, hashes y capacidades. No se codificará una RC concreta como regla permanente del dominio.

### Integrador

Versión histórica actual:

`Integrador 1.1.5`

Su actualización queda aplazada hasta que el recorrido Recolector → Consolidador V8 esté estable.

El Integrador no se habilitará para operaciones reales mientras continúe exigiendo combinaciones históricas incompatibles.

---

## 4. Principios obligatorios

1. `BaseLine/` permanecerá inmutable y montada como solo lectura.

2. Ninguna herramienta se ejecutará directamente dentro de `BaseLine/`.

3. Cada ejecución recibirá una copia verificada de la distribución correspondiente dentro de su workspace.

4. La plataforma nunca reconstruirá un ZIP y lo presentará como archivo original.

5. Si solo existe un árbol extraído, se registrará como árbol verificado.

6. Las versiones generadas posteriormente se identificarán como distribuciones producidas por un pipeline interno.

7. El frontend no conocerá SSH, SSM, Bash, PowerShell, Docker, ZIP, rutas físicas ni credenciales.

8. La separación será:

Frontend
→ dominio
→ ToolAdapter
→ ExecutionProvider
→ runner o agente
→ herramienta

9. PostgreSQL continuará siendo la fuente autoritativa.

10. Redis y Reverb serán mecanismos de entrega, no fuentes de verdad.

11. Un job de Laravel no permanecerá ocupado durante varias horas.

12. Toda ejecución remota tendrá una identidad durable.

13. La pérdida de comunicación no significará automáticamente que la herramienta falló.

14. Primero se reconciliará el proceso remoto.

15. Los checkpoints deberán proceder de la herramienta o de un adaptador que pueda validarlos sin inventar semántica.

16. El progreso será real, derivado de unidades conocidas o `null`.

17. Los archivos grandes no pasarán completamente por memoria PHP.

18. Los secretos nunca aparecerán en eventos, logs, broadcasts, artefactos ni props de Inertia.

19. Una intervención continúa la misma ejecución.

20. Una reanudación posterior a un fallo crea una ejecución nueva con linaje.

21. `COMPLETED` continuará siendo inmutable.

22. Las operaciones posteriores, como backup y publicación, no modificarán retroactivamente el cierre académico.

---

# FASE 0 — INVENTARIO, CONTRATOS Y CONGELACIÓN

## Objetivo

Determinar qué versiones históricas existen en `BaseLine/`, identificar y verificar las distribuciones fuente definitivas externas, y establecer qué contratos pueden consumir los adaptadores reales.

## 0A — Inventario de distribuciones

Durante el inventario inicial se inspeccionaron árboles fuente externos en modo
read-only. Las distribuciones verificadas están ahora incorporadas en el checkout:

- `BaseLine/Recolector/Recolector-v7.4.2`;
- `BaseLine/Consolidador/Consolidador-v8.0.0`;
- `BaseLine/Integrador/Integrador-Incremental-Moodle-v1.1.5-linux`.

Para esas distribuciones y para `BaseLine/`, se revisaron:

- Recolector;
- Consolidador V8;
- Integrador;
- archivos VERSION;
- manifiestos;
- `FILES.sha256`;
- README;
- scripts de entrada;
- configuración;
- fixtures;
- esquemas;
- archivos de estado;
- comandos de reanudación;
- comandos de detención;
- capacidades declaradas.

Crear un inventario con:

- herramienta;
- versión;
- build o commit;
- plataforma;
- ruta fuente y ubicación operativa futura;
- tipo de entrega;
- cantidad de archivos;
- hash canónico del árbol;
- ZIP original, si existe;
- SHA-256 del ZIP;
- capabilities;
- estado de habilitación.

## 0B — Proveniencia

Clasificar cada distribución como:

- archivo original entregado;
- árbol extraído de un archivo original;
- árbol de desarrollo;
- release candidate;
- distribución generada;
- referencia histórica.

No afirmar que un paquete fue entregado originalmente si no existe evidencia.

## 0C — Contratos observados

Para cada herramienta, registrar:

- comandos;
- argumentos;
- entradas;
- salidas;
- archivos generados;
- códigos de salida;
- estados;
- etapas;
- checkpoints;
- intervenciones;
- cancelación;
- detención;
- limpieza;
- artefactos;
- logs;
- mecanismos de reanudación.

Cada hallazgo deberá clasificarse como:

- confirmado por código;
- confirmado por prueba;
- documentado;
- inferido;
- no definido;
- bloqueante.

## 0D — Contrato machine-readable

Diseñar un contrato común versionado:

- `tool_contract_version`;
- `tool`;
- `tool_version`;
- `distribution_hash`;
- `operation_id`;
- `remote_execution_id`;
- `state`;
- `stage`;
- `substage`;
- `progress`;
- `progress_units`;
- `warnings`;
- `errors`;
- `interventions`;
- `checkpoints`;
- `artifacts`;
- `heartbeat`;
- `started_at`;
- `observed_at`;
- `finished_at`;
- `exit_code`;
- `terminal_result`;
- `allowed_actions`.

Si las herramientas no producen directamente este formato, diseñar un bridge versionado que traduzca sus archivos de estado.

El bridge no podrá inferir éxito, progreso ni checkpoints basándose únicamente en texto libre.

## Criterios de cierre de Fase 0

- Versiones exactas identificadas.
- Árboles y archivos verificados.
- Matriz de capacidades creada.
- Contratos documentados.
- Incompatibilidades registradas.
- Fixtures pequeños disponibles.
- BaseLine intacta.
- Ninguna herramienta ejecutada desde la plataforma.
- Ninguna integración real habilitada.

---

# ITERACIÓN 2 — INFRAESTRUCTURA DE EJECUCIÓN REAL

## 2A — Catálogo de herramientas

Implementar o ampliar:

- `Tool`;
- `ToolVersion`;
- `ToolDistribution`;
- `ToolCapability`;
- `ToolCompatibility`.

Cada ejecución debe fijar:

- herramienta;
- distribución;
- versión;
- hash;
- capabilities;
- adaptador;
- proveedor;
- configuración;
- paquetes de entrada.

La habilitación de una operación dependerá del catálogo, no de constantes dispersas.

## 2B — Feature flags y compatibilidad

Definir estados como:

- disponible;
- experimental;
- laboratorio;
- bloqueado;
- incompatible;
- retirado.

Inicialmente:

- Recolector 7.4.2: laboratorio.
- Consolidador V8: registrado, pero bloqueado hasta completar su integración.
- Integrador 1.1.5: histórico e incompatible con el flujo nuevo.

## 2C — Workspaces reales

Mantener:

```text
workspaces/
└── {project_uuid}/
    └── {execution_uuid}/
        ├── tools/
        ├── input/
        ├── output/
        ├── logs/
        ├── state/
        └── temporary/
```

Cada workspace deberá:

- pertenecer a una sola ejecución;
- tener permisos mínimos;
- verificar rutas;
- rechazar traversal;
- rechazar enlaces simbólicos peligrosos;
- registrar cuota;
- registrar uso;
- permitir limpieza conservadora;
- proteger archivos referenciados.

## 2D — Distribución de herramientas

Flujo:

```text
BaseLine read-only
→ verificar árbol o ZIP
→ copiar a staging
→ volver a verificar
→ extraer si corresponde
→ validar manifiesto
→ desplegar en workspace
→ registrar evidencia
```

Nunca se ejecutará el código directamente desde BaseLine.

## 2E — Almacenamiento

Conservar artefactos pequeños mediante la abstracción existente.

Agregar almacenamiento por referencia para:

- paquetes Moodle;
- backups;
- archivos de decenas o cientos de gigabytes;
- archivos producidos por servidores remotos.

Diferenciar:

- reporte;
- log;
- manifiesto;
- paquete fuente;
- paquete de curso;
- backup integral;
- evidencia técnica.

## 2F — RemoteOperation

Agregar persistencia durable para:

- proveedor;
- host;
- runtime;
- proceso;
- identificador remoto;
- último heartbeat;
- última observación;
- siguiente sondeo;
- estado de comunicación;
- estado funcional;
- terminación;
- evidencia.

Estados de comunicación:

- `CONNECTED`;
- `DEGRADED`;
- `UNREACHABLE`;
- `RECONCILING`;
- `TERMINATED`.

Estos estados no sustituirán los estados de Execution.

## 2G — Runner local restringido

Construir un runner para laboratorio que:

- ejecute únicamente comandos registrados;
- no permita comandos arbitrarios;
- no monte el socket Docker dentro de la aplicación;
- devuelva una identidad durable;
- escriba estado en archivos controlados;
- permita consultar;
- permita reconciliar;
- permita recuperar procesos después de reinicios;
- limite recursos;
- censure secretos.

## 2H — ExecutionProvider real

Ampliar el contrato con operaciones equivalentes a:

- `prepare`;
- `deploy`;
- `start`;
- `inspect`;
- `poll`;
- `readEvents`;
- `readLogs`;
- `heartbeat`;
- `reconcile`;
- `cancel`;
- `stopRuntime`;
- `collectArtifacts`;
- `verifyTermination`;
- `cleanup`.

No eliminar `FakeExecutionProvider`; conservarlo para pruebas y demostración.

## Cierre de Iteración 2

Este cierre describe el alcance esperado, no una aprobación automática. La corrección de infraestructura en `refactor-v8` añade catálogo y distribución verificados, workspaces por ejecución, storage por referencia, supervisor Linux local, reconciliación, paquetes fuente, configuración V8 separada, rollback y cuotas fijadas por ejecución. La vertical Fake permanece como predeterminada; las versiones y flags reales siguen cerrados y no hay comandos reales registrados.

El supervisor conserva UUID, PID, grupo, `/proc` start time, hash, host y runtime. La evidencia terminal y los logs permiten reconciliar después de que muera el worker Laravel, pero no se afirma supervivencia ante reinicio completo del contenedor. Si no aparece evidencia terminal íntegra ni se confirma el proceso, el resultado queda desconocido y requiere revisión; nunca se infiere éxito por ausencia del PID. La medición de cuota de archivos escritos directamente por herramientas es periódica, no un límite duro de filesystem.

Las regresiones cubren supervisor separado del worker, su muerte con SIGKILL, evidencia terminal íntegra, backoff, aislamiento de eventos/logs/artefactos y PostgreSQL multiproceso. El estado de cada puerta se conserva en `docs/ITERACION-2-IMPLEMENTACION.md`, en el PR #8 y en el artifact de CI `iteration2-validation-<SHA>`, con SHA y URL del último checkout validado. Si faltan dependencias o un servicio, la puerta queda sin validar y el PR se mantiene en borrador. `BaseLine/` conserva 423 archivos registrados y su huella canónica; se publicó el `config.yaml` originalmente ignorado con autorización explícita, sin editarlo. El runtime excluye ese archivo y exige configuración activa V8 aprobada; no transporta sus fuentes históricas.

---

# ITERACIÓN 3 — INTEGRACIÓN REAL DEL RECOLECTOR

## Estado de implementación — 2026-10-09

La rama `codex/3-recolector-real` implementa COLLECT real exclusivamente sobre el
perfil Moodle sintético de laboratorio, desde el main aprobado del PR #8.
Configuración/revisiones, preflight, CollectorAdapter, bridge, eventos durables,
auditoría de paquetes, SourcePackage, cierre y nuevo intento con linaje están
instalados. El recorrido real de navegador y los reinicios de Redis, queue-worker
y Reverb con la misma operación están acreditados en el laboratorio. El PR #9
continúa en borrador durante las correcciones de revisión. Se reprodujo y corrigió
la carrera de caducidad de sesión del arnés y se sustituyó source_write=false por
evidencia medida y semántica explícita. También se reprodujo y corrigió la pérdida
de una notificación Reverb durante una consulta HTTP pendiente. El cierre exige
las puertas completas y ambos jobs verdes del mismo SHA limpio; los cortes y
limitaciones se describen en el informe. No se habilita la iteración siguiente.

El estado y la evidencia se mantienen en
[`ITERACION-3-RECOLECTOR.md`](ITERACION-3-RECOLECTOR.md). Ambas flags reales siguen
cerradas por defecto. Recolector permanece `LABORATORY`, Consolidador `BLOCKED`
e Integrador `INCOMPATIBLE`. No se inició Iteración 4.

El contrato observado no acredita reanudación segura entre workspaces: la UI
ofrece una nueva exportación con otra Execution, workspace y RemoteOperation,
sin inventar checkpoints. El tiempo de pared y la CPU pueden ser null, con
heartbeat, inactividad, cancelación y límites de recursos independientes.

## 3A — Configuración real

Reemplazar casillas simuladas por datos verificables:

- servidor origen;
- conexión;
- Moodle;
- código;
- base de datos;
- moodledata;
- almacenamiento;
- workers;
- nombre de paquete;
- distribución autorizada.

La configuración será persistente, versionada y tendrá fingerprint.

## 3B — Configuración no interactiva

No automatizar prompts de terminal.

Implementar una estrategia basada en:

- archivo de configuración versionado;
- esquema;
- render;
- validación;
- aplicación.

Separar secretos y configuración no sensible.

## 3C — CollectorAdapter

Implementar:

- preflight;
- validación de origen;
- despliegue;
- configuración;
- inicio;
- consulta;
- heartbeat;
- eventos;
- progreso;
- warnings;
- errores;
- intervenciones;
- checkpoints;
- cancelación soportada;
- artefactos;
- inventario visual;
- validación del paquete;
- cierre.

## 3D — Eventos y progreso

Mapear únicamente información comprobable.

Ejemplos:

- cursos descubiertos;
- cursos procesados;
- archivos procesados;
- bytes copiados;
- inventario generado;
- paquete validado.

Cuando no exista una medida válida:

`progress = null`

## 3E — Compatibilidad 7.4.1 y 7.4.2

Permitir:

- nuevas recolecciones con 7.4.2;
- registrar paquetes 7.4.1;
- enriquecer metadata cuando sea seguro;
- conservar MBZ válidos;
- identificar metadata legacy;
- identificar metadata incompleta;
- bloquear hashes alterados;
- bloquear combinaciones desconocidas.

## 3F — Reanudación real

Un checkpoint debe vincular:

- ejecución;
- versión;
- distribución;
- adaptador;
- proveedor;
- servidor;
- configuración;
- entrada;
- fingerprint;
- evidencia;
- token;
- etapa.

Revalidar todo antes de reanudar.

## 3G — Prueba local pequeña

Ejecutar sobre Moodle sintético:

- pocos usuarios;
- 1–3 cursos;
- archivos pequeños;
- inventario visual;
- OAuth de laboratorio;
- interrupción controlada;
- nuevo intento de exportación con linaje; reanudación entre workspaces no habilitada;
- validación del paquete.

## 3H — Estabilización

Probar:

- Redis reiniciado;
- worker reiniciado;
- reinicio completo del contenedor del runner pendiente de acreditación;
- navegador cerrado;
- sesión expirada;
- Reverb desconectado;
- proceso remoto temporalmente inaccesible;
- doble inicio;
- repetición de comando;
- paquete alterado;
- disco insuficiente;
- limpieza.

## Cierre de Iteración 3

- Recolección real desde la web.
- Paquete válido.
- Inventario visual.
- Eventos reales.
- Nuevo intento real con linaje; no se declara reanudación segura entre workspaces.
- Artefactos por referencia.
- Ejecución superior a la sesión del usuario.
- Modo Fake conservado.
- Regresiones 1A–1G aprobadas.

---

# ITERACIÓN 4 — INTEGRACIÓN DEL CONSOLIDADOR V8

## 4A — Congelación de versión candidata

Antes de integrar V8:

- identificar versión exacta;
- verificar manifiesto;
- verificar árbol;
- ejecutar pruebas propias de la herramienta;
- confirmar contrato de estados;
- confirmar checkpoints;
- confirmar reanudación;
- confirmar intervenciones;
- documentar limitaciones.

La web no se adaptará continuamente a archivos internos cambiantes sin versión de contrato.

## 4B — Dominio de consolidación

Agregar soporte para:

- entre 2 y 32 paquetes;
- destino exclusivo;
- paquetes sellados;
- runtime persistente;
- resultados por fuente;
- resultados por curso;
- operaciones posteriores al cierre.

## 4C — Preparación del destino

Implementar como operación durable independiente:

- servidor;
- DNS;
- versión Moodle;
- almacenamiento;
- base de datos;
- servicios;
- OAuth;
- plugins;
- temas;
- runtime.

No ejecutar preparación dentro de una petición HTTP.

## 4D — Configuración V8

Crear configuración por proyecto.

No reutilizar:

- fuentes del laboratorio;
- selección de piloto;
- degradaciones;
- excepciones;
- resoluciones;
- rutas de otra ejecución.

La configuración tendrá:

- esquema;
- versión;
- fingerprint;
- aprobación;
- actor;
- fecha;
- invalidación por cambios.

## 4E — Etapas V8

Representar las etapas reales:

1. Importar paquetes.
2. Preparar OAuth.
3. Analizar plugins.
4. Revalidar OAuth.
5. Conciliar identidades.
6. Planificar usuarios.
7. Readiness.
8. Aplicar y verificar usuarios.
9. Piloto.
10. Temas del piloto.
11. Manifiesto del lote.
12. Restauración por curso.
13. Temas del lote.
14. Verificación.
15. Cierre académico.

Conservar identificadores reales como:

- `05b-readiness`;
- `09b-themes-piloto`;
- `13b-themes-lote`.

No asumir que existen exactamente 15 o 16 pasos equivalentes.

## 4F — Plugins

La interfaz debe mostrar:

- componentes requeridos;
- origen;
- tipo;
- core;
- ausente;
- retirado;
- desconocido;
- versión;
- dependencia;
- pin;
- equivalencia;
- estado antes y después.

Las instalaciones o equivalencias deberán aprobarse y revalidarse.

## 4G — Temas e inventario visual

Permitir:

- escoger tema global;
- visualizar perfiles encontrados;
- asignar temas por curso;
- detectar inventario completo;
- vacío;
- legacy;
- inválido;
- no disponible;
- colisiones;
- fallback.

No prometer transportar automáticamente todos los logos, archivos o configuraciones si la herramienta no lo soporta.

## 4H — Identidades

Implementar resoluciones tipadas:

- `MERGE`;
- `KEEP_SEPARATE`;
- `IGNORE`.

Registrar:

- actor;
- motivo;
- evidencia;
- fingerprint;
- candidatos;
- decisión;
- estado de aplicación.

Bloquear cambios incompatibles después de escribir identidades en el destino.

## 4I — Readiness

Persistir resultados estructurados:

- `SUCCESS`;
- `WARNING`;
- `ERROR`;
- `WAITING_USER_ACTION`.

Las advertencias requieren aceptación auditada.

Los errores bloquean el avance.

## 4J — Restauración por curso

Registrar por curso:

- fuente;
- course key;
- tamaño;
- estado;
- worker;
- etapa;
- intento;
- intervención;
- checkpoint;
- resultado;
- verificación;
- target course ID.

Un curso en intervención puede coexistir con otros cursos activos.

La ejecución global solo entra en espera cuando el coordinador se haya detenido.

## 4K — Workers y progreso

Mostrar:

- solicitados;
- efectivos;
- pendientes;
- activos;
- completados;
- verificados;
- fallidos;
- intervenidos.

El progreso se basará en información real.

Cuando sea necesario, mostrar varias métricas:

- cursos;
- bytes;
- fases;
- verificaciones.

## 4L — Intervenciones

Traducir formularios web a resoluciones declarativas.

Nunca permitir comandos arbitrarios.

Toda resolución deberá:

- validar estado;
- validar evidencia;
- ser idempotente;
- registrar actor;
- invalidarse si cambia la evidencia;
- producir un nuevo job de continuación.

## 4M — Cancelación y detención

Diferenciar:

- cancelar una operación;
- detener el coordinador;
- detener un curso;
- detener el runtime completo.

`DETENER.sh` no será presentado como cancelación granular si también detiene Moodle, base de datos o cron.

## 4N — Verificación y REVIEW

Después del proceso:

- recopilar evidencias;
- verificar usuarios;
- verificar cursos;
- verificar módulos;
- verificar archivos;
- verificar plugins;
- verificar temas;
- verificar identidades;
- verificar finalización académica.

Entrar en `REVIEW`.

El operador revisará y aprobará las evidencias.

## 4O — Cierre académico

`COMPLETED` representará:

- fase 15 terminada;
- verificaciones aprobadas;
- revisión aceptada;
- evidencias congeladas.

COMPLETED será inmutable.

## 4P — Backup y publicación

Modelar como operaciones independientes:

- crear backup;
- verificar backup;
- publicar;
- detener runtime.

Un backup fallido no cambia COMPLETED.

Publicar exige:

- ejecución completada;
- backup sellado;
- verificaciones vigentes;
- autorización;
- destino compatible.

## Cierre de Iteración 4

- Consolidación real desde la web.
- Estados reales.
- Plugins y temas gestionados.
- Identidades gestionadas.
- Restauración por curso.
- Intervenciones.
- Reanudación.
- Verificación.
- Cierre académico.
- Backup separado.
- Publicación separada.
- Ninguna dependencia del Integrador.

---

# ITERACIÓN 5 — ESTABILIZACIÓN LOCAL DEL FLUJO COMPLETO

## 5A — Recorrido mínimo

```text
Moodle origen sintético
→ Recolector
→ paquete verificado
→ Consolidador V8
→ destino preparado
→ piloto
→ lote
→ verificación
→ REVIEW
→ COMPLETED
→ backup
```

## 5B — Escenarios

Probar:

- éxito;
- warning;
- intervención;
- fallo;
- reanudación;
- cancelación;
- pérdida de conexión;
- Redis reiniciado;
- worker reiniciado;
- runner reiniciado;
- proceso duplicado;
- checkpoint alterado;
- paquete alterado;
- plugin ausente;
- tema no disponible;
- conflicto de identidad;
- curso fallido;
- disco insuficiente;
- backup fallido;
- publicación bloqueada.

## 5C — Compatibilidad

Demostrar:

- Recolector nuevo aceptado.
- Paquete compatible con V8.
- Combinaciones desconocidas bloqueadas.
- Distribución alterada bloqueada.
- Capabilities exigidas.
- Datos simulados antiguos preservados.
- Ejecuciones COMPLETED protegidas.

## 5D — Rendimiento controlado

Ejecutar pruebas progresivas:

1. 1–3 cursos.
2. 10–20 cursos.
3. dataset representativo.
4. archivos grandes sintéticos.
5. ejecución prolongada.
6. concurrencia de workers.

No avanzar automáticamente al dataset completo institucional.

## Cierre de Iteración 5

- Flujo local estable.
- Pruebas repetibles.
- Cero duplicados.
- Reanudación validada.
- Evidencias suficientes.
- Capacidad estimada.
- Runbook disponible.
- Lista de riesgos residuales.

---

# ITERACIÓN 6 — PRUEBAS INTERNAS Y CONTROLADAS EN AWS

## 6A — Arquitectura AWS

Diseñar:

- EC2 ejecutor;
- EBS separado;
- S3 para paquetes y backups;
- IAM roles;
- AWS SSM;
- cifrado;
- security groups;
- CloudWatch o logs equivalentes;
- lifecycle de S3;
- snapshots;
- cuotas;
- alertas de disco.

Evitar claves AWS estáticas dentro de la aplicación.

## 6B — AWS ExecutionProvider

Implementar SSM como proveedor principal.

Flujo:

```text
Laravel crea RemoteOperation
→ SSM inicia launcher
→ launcher crea proceso persistente
→ devuelve remote_execution_id
→ Laravel libera el worker
→ scheduler consulta estado
→ runner publica snapshots
→ eventos se normalizan
→ PostgreSQL persiste
→ Reverb notifica
```

No mantener SendCommand abierto durante toda la consolidación.

## 6C — Transferencias

Usar:

- S3;
- multipart upload;
- descargas reanudables;
- SHA-256;
- referencias;
- manifests;
- cuotas;
- retención.

No transferir paquetes grandes mediante PHP.

## 6D — Prueba AWS pequeña

Ejecutar:

- Moodle sintético;
- Recolector real;
- paquete pequeño;
- Consolidador V8;
- destino aislado;
- cierre;
- backup.

## 6E — Prueba AWS representativa

Usar un subconjunto controlado:

- usuarios representativos;
- cursos con módulos;
- archivos;
- cuestionarios;
- entregas;
- foros;
- notas;
- finalización;
- plugins;
- temas;
- identidades OAuth.

## 6F — Resiliencia AWS

Probar:

- reinicio de EC2;
- pérdida temporal de SSM;
- reinicio de la aplicación web;
- reinicio de Redis;
- worker eliminado;
- scheduler reiniciado;
- proceso remoto todavía activo;
- disco cerca del límite;
- interrupción de transferencia;
- recuperación de multipart;
- heartbeat perdido;
- reconciliación;
- cancelación autorizada;
- backup fallido.

## 6G — Ejecución prolongada

Validar que:

- una ejecución puede superar 24 horas;
- cerrar el navegador no afecta el proceso;
- expirar la sesión no afecta el proceso;
- otro usuario autorizado puede continuar observando;
- modificar requiere reconfirmar contraseña;
- polling funciona sin Reverb;
- la reconexión recupera eventos;
- no se duplican tareas;
- el proceso remoto se reconcilia.

## 6H — Prueba interna de aceptación

Criterios:

- recorrido completo repetible;
- cero pérdida de estado;
- cero secretos filtrados;
- cero ejecuciones duplicadas;
- reanudación verificada;
- paquetes y backups con hashes;
- intervenciones auditadas;
- resultados por curso;
- cierre inmutable;
- costos y capacidad documentados;
- rollback documentado;
- runbook aprobado.

---

# ITERACIÓN 7 — INTEGRADOR INCREMENTAL

Esta iteración queda al final.

## 7A — Actualización CLI

Crear una versión nueva del Integrador compatible con:

- Recolector 7.4.2;
- paquetes con inventario visual;
- destinos creados por V8;
- capabilities;
- plugins;
- temas;
- checkpoints nuevos;
- correcciones de restauración;
- verificación académica actual.

## 7B — Principios

El destino existente será autoridad.

El Integrador no debe:

- cambiar automáticamente el tema global;
- modificar cursos anteriores;
- modificar perfiles reutilizados sin aprobación;
- cambiar identidades ya consolidadas;
- invalidar evidencias históricas.

Debe:

- crear copia previa;
- mantener cursos nuevos ocultos;
- verificar compatibilidad;
- permitir reanudación;
- bloquear combinaciones desconocidas;
- preservar cursos anteriores;
- verificar resultados.

## 7C — Integración web

Implementar `IncrementalIntegratorAdapter` reutilizando:

- catálogo;
- ExecutionProvider;
- storage;
- workspaces;
- conexiones;
- eventos;
- checkpoints;
- intervenciones;
- artefactos;
- seguridad.

## 7D — E2E incremental

Probar:

```text
Destino producido por V8
→ backup previo
→ paquete nuevo
→ integración
→ verificación
→ cursos anteriores intactos
→ cursos nuevos ocultos
→ aceptación
```

El Integrador no bloqueará la aceptación de Recolector y Consolidador.

---

# PUERTAS DE CALIDAD TRANSVERSALES

En cada corte ejecutar:

- migraciones desde cero;
- upgrade;
- rollback controlado;
- reaplicación;
- datos existentes;
- PostgreSQL;
- Redis;
- concurrencia;
- PHPUnit;
- pruebas contractuales;
- Vitest;
- Playwright;
- Pint;
- PHPStan/Larastan;
- TypeScript;
- ESLint;
- build;
- Docker Compose;
- instalación limpia;
- healthchecks;
- BaseLine íntegra;
- BaseLine read-only;
- `git diff --check`;
- CI.

Las pruebas pesadas de Moodle y AWS se ejecutarán fuera del CI general y producirán evidencias referenciadas.

---

# SEGURIDAD

El plan debe cubrir:

- command injection;
- path traversal;
- symlinks;
- ZIP bombs;
- archivos especiales;
- SSRF;
- credenciales;
- IAM;
- known_hosts;
- sudo;
- replay;
- ejecución duplicada;
- distribución alterada;
- paquete alterado;
- secretos en logs;
- secretos en artefactos;
- permisos del workspace;
- acceso a Docker;
- acceso a S3;
- autorización por proyecto;
- reconfirmación de contraseña;
- auditoría.

---

# ORDEN DE IMPLEMENTACIÓN

```text
Fase 0
Contratos e inventario
        ↓
Iteración 2
Infraestructura real
        ↓
Iteración 3
Recolector real
        ↓
Iteración 4
Consolidador V8
        ↓
Iteración 5
Estabilización local completa
        ↓
Iteración 6
Pruebas internas en AWS
        ↓
Iteración 7
Integrador incremental
```

---

# DEFINICIÓN DE ÉXITO

El proyecto estará listo para pruebas internas controladas cuando:

1. Recolector y Consolidador se ejecuten mediante adaptadores reales.

2. No existan comandos arbitrarios generados desde el frontend.

3. Los procesos puedan durar más de 24 horas.

4. Reiniciar la aplicación no duplique ni pierda procesos remotos.

5. Los paquetes grandes se gestionen por referencia.

6. Los checkpoints sean reales y verificables.

7. Las intervenciones puedan resolverse desde la web.

8. Los resultados por curso sean visibles.

9. La consolidación llegue a REVIEW y COMPLETED.

10. Backup y publicación estén separados del cierre académico.

11. Las credenciales permanezcan protegidas.

12. BaseLine continúe intacta.

13. El flujo simulado siga pasando sus regresiones.

14. Las combinaciones incompatibles permanezcan bloqueadas.

15. El recorrido local completo sea repetible.

16. Exista un provider AWS basado en SSM.

17. La prueba pequeña en AWS sea exitosa.

18. La prueba representativa en AWS sea exitosa.

19. La recuperación ante interrupciones haya sido demostrada.

20. Existan documentación, evidencias y runbooks.

---

# FORMA DE EJECUTAR EL PLAN

No implementar todas las iteraciones en un solo bloque.

Cada corte deberá ejecutarse individualmente.

Antes de comenzar cada corte:

- revisar el resultado anterior;
- confirmar que el CI está verde;
- confirmar BaseLine íntegra;
- revisar migraciones;
- verificar que no se adelantó alcance;
- crear una rama nueva desde main actualizado.

Al terminar cada corte:

1. ejecutar pruebas;
2. ejecutar análisis estático;
3. ejecutar frontend;
4. ejecutar build;
5. validar Docker;
6. validar BaseLine;
7. documentar decisiones;
8. publicar una rama;
9. abrir PR en borrador;
10. esperar revisión antes de avanzar.

El primer trabajo a ejecutar será exclusivamente:

FASE 0 — INVENTARIO, CONTRATOS Y CONGELACIÓN.

No iniciar todavía la Iteración 2A hasta que la Fase 0 haya sido revisada y aprobada.

---

# ANEXO — RESULTADO Y ADAPTACIÓN DE FASE 0

Fecha del inventario: 2026-10-07. Este anexo concreta las referencias de este plan con las distribuciones disponibles y los contratos observados. La inspección fue estática: no se ejecutaron comandos de Recolector, Consolidador o Integrador, no se conectó con Moodle ni AWS y no se cambió `BaseLine/`.

## Estado y procedencia

La rama de trabajo es `refactor-v8`, creada desde `origin/main` en `bf9bdb7960544a2ada2caf538bea353298767297`. Ese commit contiene la vertical 1G aprobada. La rama local `main` estaba desactualizada; por eso el punto de partida verificado fue `origin/main`.

En la inspección inicial, las distribuciones definitivas eran árboles fuente externos al repositorio web. No se encontró un ZIP original. Se registraron como árboles verificados, sin atribuirles un archivo original no disponible.

| Distribución                                            | Versión declarada  | Archivos / entradas del manifiesto | SHA-256 canónico del árbol                                         | Resultado                                                                                                                                                                  |
| ------------------------------------------------------- | ------------------ | ---------------------------------: | ------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Recolector/Recolector-v7.4.2`                          | `7.4.2-linux`      |                            29 / 28 | `4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e` | Todas las entradas declaradas coinciden; no se encontró discrepancia.                                                                                                      |
| `Consolidador/Consolidador-v8.0.0`                      | `8.0.0-linux-rc12` |                          263 / 260 | `74d976c5290724ff52452d6abe117aa34876b1daab36d624c5e7adc353ab3ab7` | Todas las entradas declaradas coinciden. Dos archivos operativos no aparecen en `FILES.sha256`; Iteración 2 los excluye del runtime y exige configuración activa separada. |
| `Integrador/Integrador-Incremental-Moodle-v1.1.5-linux` | `1.1.5-linux`      |                            22 / 21 | `0e1f3c40167a66c272774ccb366438593f492f47e96c80e93e72c5555ae7f6b3` | Todas las entradas declaradas coinciden; no se encontró discrepancia.                                                                                                      |

El hash canónico del árbol se calculó sobre todos los archivos presentes, con rutas relativas normalizadas y ordenadas, incorporando el SHA-256 de cada contenido. Los manifiestos `FILES.sha256` se verificaron por separado. El Consolidador no declara en el manifiesto los dos archivos operativos citados; esto no se clasifica como corrupción, pero sí exige distinguir distribución inmutable de configuración mutable. La solución de Iteración 2 los excluye del runtime junto con las resoluciones del benchmark; no se copian como overlays activos.

Al comenzar Iteración 2, el usuario incorporó a `BaseLine/` los árboles finales del Recolector 7.4.2 y Consolidador V8 RC12. Sus hashes canónicos coinciden con los verificados en la inspección inicial: `4daaa16d278991f098b7f9c85361f7800193c4825e1f8cb6cb2c4b2870103c2e` y `74d976c5290724ff52452d6abe117aa34876b1daab36d624c5e7adc353ab3ab7`. La BaseLine actual conserva también versiones históricas e Integrador 1.1.5: 423 archivos, SHA-256 canónico `d2c80f1aa5157320ac7208f9506fcba5dcc7d4d8830fa872658df6e99486c221`. La prueba de integridad se actualizó a esa línea base; no se modificó ningún archivo de herramienta.

El árbol del Consolidador se identifica por su versión y hash; no se confirmó un commit Git de esa distribución. `rc12` debe seguir siendo dato de distribución y compatibilidad, no una constante permanente del dominio.

## Contratos comprobados y efecto en el flujo

### Recolector 7.4.2

El punto de entrada es `EXPORTAR-ORIGEN.sh`. Acepta `--background`, `--workers=auto|1|2|3|4`, `--notify-every=MINUTOS`, `--output-dir`, `--temp-dir`, `--reuse-backups`, `--reuse-only` y `--restart`, seguidos del nombre del ZIP y, opcionalmente, `Moodle-config.php`. `--background` delega a `systemd-run`; que el lanzador devuelva éxito solo confirma el inicio, no que la exportación haya terminado.

Produce el paquete ZIP, su checksum lateral, un estado JSON por origen, progreso JSON, logs y el inventario visual. El estado JSON incluye versión, origen, estado y etapa, modo, código de salida, duración, workers, rutas y tiempos; el progreso aporta contadores de cursos, fallos y porcentaje cuando están disponibles. El bridge debe consumir estos datos estructurados y reconciliar el proceso de `systemd`; no debe inventar un identificador de ejecución remota a partir del texto de consola. La distribución requiere Linux/Bash, PHP CLI y extensiones Zip/DOM, acceso al código/configuración Moodle y espacio suficiente. La modalidad de segundo plano además depende de `systemd` y permisos adecuados.

Los cambios 7.4.2 agregan/fortalecen metadatos de temas por curso, perfiles con redacción segura, inventario visual versionado, capacidad declarada y validación del sellado. Puede completar metadatos de reanudación en un paquete 7.4.1 sin repetir el MBZ ni la huella académica; los metadatos incompletos pueden requerir reintento. Los argumentos principales se mantienen respecto de 7.4.1, pero ahora la web debe mostrar y persistir los nuevos resultados visuales y de temas. La creación y limpieza de temporales debe verificarse como efecto operativo; el propósito de solo lectura académica no equivale a ausencia de toda escritura temporal.

### Consolidador V8 `8.0.0-linux-rc12`

El contrato es un conjunto de scripts de entrada con responsabilidades diferentes, no un único comando lineal. `moodle-consolidation.sh` enruta acciones como `verificar`, `preflight`, `configurar`, `preparar`, `iniciar`/`reanudar`, `aplicar-revision-identidades`, `iniciar-segundo-plano`, `ejecutar-automatico`, `estado`, `logs`, `publicar` y `detener`; también existen wrappers dedicados como `PREPARAR-DESTINO.sh`, `ESTADO.sh`, `PUBLICAR-SITIO.sh` y `DETENER.sh`. `configurar` usa preguntas interactivas y no es apto para una petición web sin TTY; la integración deberá renderizar y validar archivos de configuración versionados. `preparar` crea el destino y recursos Docker, con un nombre de proyecto Docker actualmente fijo, por lo que debe verificarse el aislamiento de destino antes de habilitar ejecuciones concurrentes.

El `VERSION.txt` de RC12 indica que el cambio de esa RC se concentra en ejecutar explícitamente el wrapper con Bash bajo `systemd` y `nohup`, conservando la lógica funcional de RC11. También identifica como referencias de aceptación las fuentes `posgrados-2025-05-02-directo` y `pregrado-2026-03-04-directo`. Por tanto, el proveedor remoto debe respetar el wrapper y validar su resultado con el estado persistente; no basta con comprobar permisos ejecutables ni la respuesta inicial de lanzamiento.

El estado estructurado vive en `reports/assistant-state.json`; el log humano es complementario y no sirve como fuente de verdad. La restauración tiene estado por curso y un resumen de workers independiente, con estados como `SUCCESS`, `WARNING`, `WAITING_MANUAL` y `FATAL_SYSTEMIC`. Una intervención `WAITING_MANUAL` puede afectar a un curso mientras otros avanzan. En ejecución automática, el código 22 señala intervención; el wrapper de segundo plano puede devolver 1 para cualquier salida no cero. Por ello, la web debe reconciliar estado y proceso consultando los JSON, no tratar el código del wrapper como resultado terminal.

El flujo real incluye importación de paquetes, OAuth, inspección de plugins, resolución de identidades, readiness, usuarios, piloto, restauración por curso, temas, verificación y cierre académico. Los IDs de subetapa, como `05b-readiness`, `09b-themes-piloto` y `13b-themes-lote`, deben mapearse sin reducirlos a un contador genérico de fases. Google OAuth, identidades ambiguas (`MERGE`, `KEEP_SEPARATE`, `IGNORE`), instalación/pin de plugins, elección de tema y ciertas degradaciones requieren decisiones humanas tipadas, auditables y revalidadas.

Plugins: se debe exponer componente requerido, tipo/origen, versión, dependencias, compatibilidad, pin y estado antes/después. Una instalación o equivalencia exige aprobación y nueva comprobación del inventario. Temas: permitir elección global explícita y conservar/asignar temas de curso con avisos o fallback. Los temas asignados a usuarios/categorías se observan, pero no se aplican. El inventario de temas no demuestra transporte completo de logos, archivos o configuración.

El cierre académico corresponde a completar la fase 15, pasar verificación y revisión, y congelar evidencia. El backup sellado de fase 16 y la publicación son operaciones posteriores independientes; un backup fallido no debe revertir el cierre académico. `DETENER.sh` puede detener el runner, Moodle, base de datos y cron: la UI debe llamarlo “detener runtime” y no presentarlo como cancelación granular de una operación o curso. La distribución documenta una prueba de referencia de 366 cursos y 166.25 GiB en la que el backup integral falló por falta de espacio tras consumir aproximadamente 969 GiB; es evidencia documental para dimensionar y aislar backup, no una prueba repetida en este proyecto.

### Integrador 1.1.5

La distribución exige combinaciones anteriores: `INTEGRAR.sh` acepta Consolidador 7.3.0/7.3.0-rc4 y el validador del paquete exige `collector_version=7.4.1-linux`. Es incompatible con el flujo objetivo Recolector 7.4.2 → Consolidador V8. Se conserva como histórica y queda fuera de los caminos habilitados hasta la Iteración 7; no debe bloquear las pruebas 3–6.

## Cambios que esto exige en MoodleWebToolkit

Al pasar a Iteración 2A, el catálogo debe registrar por separado versión, árbol/hash, manifiesto, capacidades observadas y ubicación de la distribución. Debe distinguir fuente verificada, copia de ejecución y configuración mutable. Cada ejecución fijará la distribución exacta en un workspace propio; `BaseLine/` queda solo para regresión y modo demostrativo.

Los adaptadores necesitan invocaciones acotadas con argumentos conocidos, configuración no interactiva, identidad persistente del proceso remoto y un bridge versionado que traduzca estado JSON, progreso, resultado por curso e intervenciones. `stdout`/logs sirven para diagnóstico. El código de salida del lanzador no reemplaza el estado final. La web debe separar inicio de segundo plano, observación/reconciliación, reanudación soportada, intervención y detención de runtime; una capacidad no observada no se expone como acción.

El modelo visual deberá reflejar las subetapas reales, métricas de workers y outcomes por curso. Debe incluir la revisión del inventario y pin de plugins, selección de tema global, asignaciones de tema por curso, faltantes de inventario y fallback. El cierre `COMPLETED` representa solo cierre académico y deja backup/publicación en operaciones separadas. Antes de permitir `preparar`, `publicar` o detener runtime, la UI requiere permisos, confirmación contextual, registro de actor/evidencia y comprobación del estado actual.

Estas observaciones fundamentan las iteraciones 2–4. La infraestructura de Iteración 2 añade migraciones, jobs y comandos locales registrados, mantiene la vertical Fake 1A–1G como predeterminada y no integra comandos reales de las distribuciones. Los adaptadores de Recolector, Consolidador e Integrador, las conexiones Moodle/AWS y los flujos de plugins, temas, identidades e intervenciones permanecen para las iteraciones funcionales posteriores.

## Cierre de este corte

Se verificaron versiones, árboles y entradas de manifiesto; se registraron comandos, estados, capacidades, temas, plugins, intervenciones, detención e incompatibilidad del Integrador. Hay fixtures pequeños en las distribuciones, incluidos `Recolector/.../tests/fixtures/theme-inventory.json` y `Consolidador/.../tests/fixtures/v8-themes.json`, `v8-identities.json` y `rc12-shared-emails.json`. Solo se inspeccionó su disponibilidad; no se ejecutaron pruebas de las herramientas. La integridad de `BaseLine/` sí se comprobó con el test propio del repositorio.

La Fase 0 queda como registro histórico del inventario y sus hallazgos; [`FASE-0-MATRIZ-CAPACIDADES.md`](FASE-0-MATRIZ-CAPACIDADES.md) añade el estado corregido de Iteración 2; [`contracts/tool-operation.v1.schema.json`](contracts/tool-operation.v1.schema.json) define el contrato común propuesto y sus ejemplos sintéticos. Los defectos, regresiones y limitaciones de ejecución se describen en [`ITERACION-2-IMPLEMENTACION.md`](ITERACION-2-IMPLEMENTACION.md).

El inventario y el contrato no habilitan por sí solos las herramientas reales. La infraestructura corregida debe pasar las puertas de calidad sobre el SHA final publicado antes de salir de borrador. La integración funcional del Recolector corresponde a la Iteración 3; Consolidador V8 e Integrador siguen en sus iteraciones posteriores, y no se hace merge como parte de este cierre.
