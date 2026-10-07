# Consolidador 8.0.0-linux-rc8

Release de cierre técnico construida desde el paquete sellado
8.0.0-linux-rc7 y los resultados de su validación E2E.

## Correcciones incorporadas

### 1. Comparación semántica de foros de anuncios

Moodle 5.x puede materializar en el destino un `mod_forum` técnico con
`forum.type=news`, sección 0 y sin `idnumber`, aunque no exista como
actividad académica en el inventario origen.

El comparador descuenta únicamente un candidato target-only que cumpla
todo el contrato estructural. No depende del nombre localizado del foro.
Múltiples candidatos permanecen bloqueantes.

La normalización es independiente de la compatibilidad existente de
`mod_qbank`.

### 2. Contrato del bind mount `/exports`

Durante la preparación del destino, `/exports` queda con:

- propietario: UID del operador;
- grupo: `www-data`;
- modo: `2770`;
- setgid;
- validación real de traversal y escritura como `www-data`.

Los helpers existentes `Grant-ContainerExportWrite` y
`Restore-AssistantExportOwnership` continúan gestionando los
subdirectorios de cada fase.

### 3. Integridad de configuraciones operativas

`config/phase5-pilot-package.json` y `config/phase6-batch.json`
permanecen obligatorios y deben contener JSON válido, pero quedan fuera
del manifiesto criptográfico inmutable porque el propio Consolidador
materializa datos operativos en ellos durante la importación.

Todos los demás archivos de distribución continúan protegidos por
`FILES.sha256`.

## Validación E2E heredada

La ejecución RC7 sobre las fuentes de aceptación completó la aplicación
del lote de 365 cursos después del ajuste semántico del foro técnico.

También quedaron comprobados:

- continuidad automática después de revisión fuzzy;
- resolución fuzzy validada con 80 MERGE y 4 IGNORE;
- conservación de identificadores técnicos internos;
- identificadores operativos humanos;
- restauración de los antiguos bloqueos `module.xml`;
- reanudación y reutilización de checkpoints;
- compatibilidad de `qbank`;
- exportación final del sitio consolidado.

RC8 no incorpora datos, checkpoints ni progreso de esa ejecución.
