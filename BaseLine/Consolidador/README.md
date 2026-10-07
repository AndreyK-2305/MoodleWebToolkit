# Consolidador Moodle UFPS — 8.0.0-linux-rc12

Herramienta CLI para consolidar entre **2 y 32 paquetes Moodle de origen** en un Moodle **5.2.1 nuevo**, con conciliación de identidades, validación OAuth2, compatibilidad de plugins, preservación de themes, restauración paralela, checkpoints y verificación académica.

## Estado de la versión

```text
Versión: 8.0.0-linux-rc12
Estado: candidata final de cierre técnico
Base: 8.0.0-linux-rc11
Destino: Moodle 5.2.1
```

RC12 deriva exclusivamente del artefacto RC11 congelado.

El cambio funcional de RC12 es deliberadamente pequeño: el wrapper de ejecución en segundo plano se invoca explícitamente mediante `/usr/bin/env bash` tanto en `systemd` como en el fallback `nohup`. De esta forma el handoff ya no depende del bit ejecutable de `scripts/run-background-with-progress.sh`.

RC12 **no modifica** la lógica académica de:

- restore;
- scheduler;
- workers;
- checkpoints;
- reconciliación de identidades;
- plugins;
- themes;
- heartbeat;
- verificación de Fase 13.

La prueba E2E final confirmó el handoff a segundo plano, el procesamiento masivo, la reanudación de cursos pendientes, la verificación global y el cierre funcional.

## Paquete actual del repositorio

```text
Consolidador/Consolidador-v8.0.0-linux.zip
Consolidador/Consolidador-v8.0.0/
```

El `VERSION.txt` interno identifica el runtime como `8.0.0-linux-rc12`.

## Qué hace

El Consolidador:

1. importa paquetes sellados de múltiples Moodles;
2. valida compatibilidad del destino;
3. prepara plugins y themes;
4. configura y valida Google OAuth2;
5. concilia identidades;
6. normaliza roles y matrículas;
7. crea usuarios canónicos;
8. restaura un piloto;
9. planifica el lote completo;
10. procesa cursos en paralelo;
11. verifica cada curso;
12. reanuda desde checkpoints;
13. consolida evidencias;
14. puede generar una copia integral final del sitio.

## Requisitos

- Linux/Ubuntu de 64 bits.
- Docker Engine.
- Docker Compose v2 (`docker compose`).
- `flock` de `util-linux`.
- Usuario autorizado para utilizar Docker.
- Acceso a Internet durante la construcción inicial de imágenes.
- Espacio suficiente para:
  - paquetes fuente;
  - Moodle destino;
  - MariaDB;
  - extracción temporal por worker;
  - crecimiento de `moodledata`;
  - copia integral de Fase 16 si se desea publicar mediante `PUBLICAR-SITIO.sh`.
- Dominio final y acceso al proyecto Google Cloud para OAuth2.
- SMTP accesible si se habilitan notificaciones.
- Directorio persistente en el host para `MOODLE_MANAGED_CONFIG_DIR`.

No existe un límite artificial de tamaño por curso o por ZIP. La restricción real es la capacidad del host, Docker, filesystem y Moodle.

## Paquetes de entrada

La versión recomendada del productor es:

```text
Recolector 7.4.2-linux
```

V8 utiliza su metadata de themes mediante:

```text
capabilities.theme_inventory=1.0
```

Paquetes válidos de Recolector `7.4.1-linux` todavía pueden importarse como legacy, pero no contienen toda la metadata visual requerida por V8. En ese caso el asistente exige una decisión explícita y no inventa themes ni perfiles.

Cada fuente debe incluir:

```text
nombre.zip
nombre.zip.sha256
```

Copie los pares en:

```text
copias/
```

El ZIP debe declarar el contrato `moodle-consolidation-source`, estar sellado y conservar sus manifiestos, inventarios, identidades, plugins, checkpoints y MBZ.

## Preparación

Desde:

```bash
cd Consolidador/Consolidador-v8.0.0
```

habilite los lanzadores:

```bash
chmod +x ./*.sh
```

Verifique la distribución:

```bash
./moodle-consolidation.sh verificar
```

Configure el entorno:

```bash
./CONFIGURAR.sh
```

`CONFIGURAR.sh` crea `.env`, solicita los parámetros del destino y puede configurar SMTP.

Prepare el Moodle destino:

```bash
./PREPARAR-DESTINO.sh
```

El proyecto Compose utilizado por el Consolidador es independiente de los despliegues origen.

## Ejecución interactiva

```bash
./INICIAR-CONSOLIDACION.sh
```

o limitando workers:

```bash
./INICIAR-CONSOLIDACION.sh --workers=2
```

Acciones interactivas disponibles según la etapa:

```text
continuar
reintentar
abrir
salir
```

`salir` conserva los checkpoints. Al ejecutar nuevamente el mismo lanzador, el asistente reanuda desde la primera etapa no aprobada.

## Ejecución en segundo plano

```bash
./INICIAR-SEGUNDO-PLANO.sh --workers=auto
```

También puede usarse:

```bash
./INICIAR-SEGUNDO-PLANO.sh --workers=1
./INICIAR-SEGUNDO-PLANO.sh --workers=2
./INICIAR-SEGUNDO-PLANO.sh --workers=3
./INICIAR-SEGUNDO-PLANO.sh --workers=4
```

`auto` selecciona la concurrencia de acuerdo con el runtime, con un máximo de cuatro workers para la ruta masiva actual.

RC12 usa:

- `systemd-run --user` cuando está disponible y el contrato de persistencia es válido;
- `nohup` como ruta alternativa;
- ejecución explícita mediante Bash para el wrapper de progreso.

Cuando se utiliza `systemd --user`, RC9 y posteriores comprueban que `Linger=yes`. Si no está habilitado, el asistente bloquea antes de iniciar una unidad que podría morir al cerrar SSH.

Supervisión:

```bash
./ESTADO.sh
./moodle-consolidation.sh logs
```

Detención controlada:

```bash
./DETENER.sh
```

## Notificaciones y heartbeat

Las notificaciones por correo son opcionales.

RC10 añadió heartbeat periódico para procesos largos y RC11 completó su ciclo de vida tanto en modo interactivo como durante el handoff a segundo plano.

Variables principales:

```text
CONSOLIDATION_EMAIL_ENABLED
CONSOLIDATION_EMAIL_TO
CONSOLIDATION_EMAIL_FROM
CONSOLIDATION_SMTP_HOST
CONSOLIDATION_SMTP_PORT
CONSOLIDATION_SMTP_USE_TLS
CONSOLIDATION_PROGRESS_EMAIL_ENABLED
CONSOLIDATION_PROGRESS_EMAIL_INTERVAL_MINUTES
```

El intervalo de progreso predeterminado de la línea actual es de 15 minutos.

El correo es **fail-open**: un fallo SMTP se registra, pero no cambia el resultado académico de la consolidación.

Snapshots observacionales:

```text
reports/fase-1-progress.json
reports/fase-6-workers-status.json
```

## Las 16 etapas

1. Importar paquetes y validar su contrato sellado.
2. Comprobar Moodle y compatibilidad de plugins.
3. Configurar y validar manualmente Google OAuth2.
4. Conciliar identidades, roles y matrículas.
5. Simular usuarios canónicos.
6. Aplicar usuarios y linked logins Google.
7. Verificar usuarios y linked logins.
8. Preparar y simular el curso piloto.
9. Restaurar el piloto.
10. Verificar el piloto.
11. Simular el lote consolidado.
12. Crear el manifiesto ligero del lote, validar referencias, permisos, políticas y checkpoints y publicar `BATCH_READY`.
13. Aplicar el lote mediante workers; cada worker prepara, inspecciona, restaura y verifica su curso.
14. Verificar la consolidación utilizando la evidencia incremental sellada.
15. Consolidar evidencias y cerrar.
16. Generar y sellar la copia integral del sitio consolidado.

## Identidades

V8 conserva conciliación determinística y una revisión fuzzy opcional.

Las fusiones automáticas solo se realizan cuando la evidencia lo permite. Dos `google_sub` diferentes no se fusionan por aproximación.

Las resoluciones explícitas se auditan en:

```text
config/identity_resolutions.csv
config/fuzzy_identity_resolutions.csv
```

La revisión fuzzy puede producir:

```text
exports/identity_candidates.csv
exports/identity_candidate_review.csv
```

Un `MERGE`, `KEEP_SEPARATE` o `IGNORE` queda sujeto a sus contratos de auditoría.

Los roles objetivo se normalizan a las políticas del destino, incluyendo el rol seguro `personalizado` para perfiles no estándar cuando corresponda.

## Google OAuth2

La configuración del proveedor es una intervención manual deliberada.

El Consolidador:

- valida el proveedor;
- obtiene el `issuerid`;
- utiliza únicamente identificadores externos comprobados;
- diferencia `google_sub` real de un correo almacenado como linked username;
- bloquea duplicidades o enlaces incompatibles;
- revalida OAuth2 antes de fases sensibles.

No se inventa un `google_sub`.

## Plugins

La compatibilidad de plugins se valida antes de continuar con la consolidación.

V8 resuelve rutas de plugins según la instalación real y distingue:

- plugin instalado;
- plugin ausente;
- componente core;
- componente retirado;
- componente desconocido.

Los plugins requeridos y no resueltos bloquean el flujo.

Los pins y árboles instalados se verifican. Si se usa `docker/custom-plugins/`, `approved-plugins.json` conserva la evidencia aprobada.

Las equivalencias funcionales son explícitas; no se deducen solo por nombre.

## Themes

Recolector `7.4.2` incorpora la metadata visual que consume V8.

El Consolidador puede:

- seleccionar theme global;
- verificar `allowcoursethemes`;
- preservar o reaplicar themes por curso;
- resolver plugins `theme_*` mediante el mismo catálogo de compatibilidad;
- registrar fallback al theme global cuando un theme de origen no es transportable.

Un fallback visual no debe ocultar pérdida académica ni fabricar un theme inexistente.

## Restauración masiva y checkpoints

La Fase 13 utiliza una cola dinámica de workers.

Características principales:

- cursos pesados se priorizan para reducir la cola residual;
- cada worker carga solo el trabajo de su curso;
- los MBZ originales permanecen sellados;
- la extracción temporal se normaliza sin modificar el paquete fuente;
- `qtype=random` legacy se normaliza de forma conservadora y fail-closed;
- cada curso exitoso conserva un checkpoint independiente;
- un curso con fallo aislado puede quedar `WAITING_MANUAL`;
- los demás workers pueden continuar;
- un reintento reutiliza los cursos ya aprobados;
- la verificación académica ocurre inmediatamente después del restore.

No elimine checkpoints manualmente para “desbloquear” una corrida.

## Reanudación

Después de corregir una intervención:

```bash
./INICIAR-CONSOLIDACION.sh
```

o:

```bash
./INICIAR-SEGUNDO-PLANO.sh --workers=auto
```

El sistema reutiliza planes, paquetes, checkpoints y estados todavía válidos.

## Cierre funcional y Fase 16

La E2E definitiva confirmó que la consolidación queda aplicada y verificada antes de generar el paquete integral:

```text
13-aplicar-lote        completed
13b-themes-lote        completed
14-verificar-lote      completed
15-cierre              completed
```

La Fase 16:

```text
16-paquete-sitio
```

genera una copia integral del resultado:

```text
exports/phase8/paquete-sitio-consolidado.zip
```

Incluye base de datos, código/plugins, `moodledata` y evidencias, excluyendo credenciales sensibles declaradas por contrato.

### Importante

La Fase 16 **no modifica ni revierte** los cursos ya restaurados y verificados.

Sin embargo, el comportamiento actual de RC12 mantiene la copia integral sellada como requisito de:

```bash
./PUBLICAR-SITIO.sh
```

Por tanto, debe diferenciarse entre:

- **cierre funcional de la consolidación:** Fase 15;
- **backup integral posterior:** Fase 16;
- **publicación automatizada:** requiere Fase 16 completa en RC12.

## Benchmark E2E definitivo

Fuentes de aceptación:

```text
posgrados-2025-05-02-directo
pregrado-2026-03-04-directo
```

Resultados:

| Métrica | Resultado |
|---|---:|
| Fuentes | 2 |
| Cursos | **366** |
| Posgrados | 255 |
| Pregrado | 111 |
| Entrada Posgrados | 160.50 GiB |
| Entrada Pregrado | 5.75 GiB |
| Entrada total | **166.25 GiB** |
| CPU | 4 vCPU |
| RAM | 15 GiB |
| Tiempo de pared hasta Fase 15 | **3 h 2 min 47 s** |
| Tiempo activo acumulado de etapas | **2 h 13 min 41 s** |
| Throughput activo observado | **≈164 cursos/h** |
| Throughput de entrada observado | **≈74.6 GiB/h** |
| Fase 13 intento 1 | 1 h 46 min 50 s → `waiting_manual` |
| Fase 13 reanudación | 9 min 36 s → `completed` |
| Fase 14 | 16 s |
| Fase 15 | 1 s |

El tiempo de pared incluye tiempo del operador entre estados y antes del reintento. Para rendimiento técnico debe diferenciarse del tiempo activo acumulado.

### Resultado de Fase 16 en la prueba

La exportación integral se ejecutó durante 39 minutos y terminó con:

```text
No space left on device
```

El filesystem raíz disponible para la prueba era de aproximadamente 969 GiB y alcanzó 100 %.

La prueba demostró que el dimensionamiento requerido para **consolidar** y el requerido para **crear posteriormente una copia integral del destino** deben considerarse por separado.

## Evidencias

Durante una ejecución se generan evidencias bajo:

```text
exports/
reports/
```

Entre los artefactos de cierre se encuentran:

```text
exports/phase7/informe-final-migracion.md
exports/phase7/closure_summary.json
```

Los estados y logs no deben publicarse si contienen información institucional sensible.

## Gestor de configuración

V8 incluye:

```bash
./GESTIONAR-CONFIG.sh ver
./GESTIONAR-CONFIG.sh editar
./GESTIONAR-CONFIG.sh aplicar --motivo "Ajuste aprobado"
./GESTIONAR-CONFIG.sh historial
./GESTIONAR-CONFIG.sh restaurar VERSION
./GESTIONAR-CONFIG.sh verificar
```

La configuración administrada vive fuera del volumen Docker, mantiene historial y puede revertirse si impide arrancar Moodle.

## Integridad

Verifique la distribución con:

```bash
sha256sum -c FILES.sha256
```

No modifique artefactos protegidos por `FILES.sha256`.

Los archivos operativos deliberadamente mutables se validan mediante sus contratos propios.

## Seguridad

No distribuya:

```text
.env
config.php real
copias/*.zip institucionales
exports/ reales
reports/ reales
credenciales SMTP
tokens OAuth
moodledata
dump de base de datos sin protección
```

Los paquetes completos de sitio y los paquetes producidos por el Recolector contienen información institucional sensible.

## Historial reciente de cierre

### RC9

- valida `Linger=yes` antes de utilizar `systemd-run --user`;
- conserva fallback `nohup`.

### RC10

- agrega heartbeat periódico por correo;
- Fase 1 publica snapshot observacional;
- Fase 13 reutiliza el estado de workers existente.

### RC11

- inicia notifier también en modo interactivo;
- apaga el notifier interactivo antes del handoff;
- evita notifiers duplicados durante la transición.

### RC12

- invoca `run-background-with-progress.sh` explícitamente mediante Bash;
- elimina la dependencia del bit ejecutable del wrapper;
- no cambia Fase 13 ni la lógica académica.

## Diagnóstico rápido

Estado:

```bash
./ESTADO.sh
```

Logs:

```bash
./moodle-consolidation.sh logs
```

Espacio:

```bash
df -h
```

Contenedores:

```bash
docker compose ps
```

No borre manualmente cursos, planes, checkpoints o volúmenes antes de revisar la causa registrada por el asistente.

## Documentación de trazabilidad

La distribución conserva archivos `CAMBIOS-*`, pruebas de regresión y evidencias técnicas dentro de `Consolidador-v8.0.0/`.

Para conocer el identificador exacto de la build ejecutada:

```bash
cat VERSION.txt
```
