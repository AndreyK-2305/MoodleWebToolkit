# Contrato operativo V8

La preparación termina exclusivamente con `READY_TO_RUN`. Antes de ese estado
no se crean usuarios ni cursos en el Moodle destino.

1. Importar y verificar paquetes sellados.
2. Validar OAuth2 y proxy.
3. Resolver plugins desde catálogo o intervención explícita.
4. Ejecutar upgrade de staging y sellar `plugin-lock.json`.
5. Revalidar OAuth2/proxy y conciliar identidades determinísticas.
6. Construir el plan de usuarios sin escribir.
7. Generar candidatos fuzzy entre canonicales distintos.
8. Revisarlos (`R`) o ignorarlos (`S`). Si existe un MERGE aprobado, volver a
   ejecutar Fase 3 y regenerar el plan de Fase 4 por sus hashes.
9. Inventariar los themes reales del destino, exigir una selección global
   explícita y construir el plan de transporte por curso.
10. Sellar `readiness.json` como `READY_TO_RUN`.
11. Ejecutar linealmente Fases 4–7 con sus checkpoints existentes.
12. Verificar las asignaciones de themes después del piloto y del lote.

Los tres contratos son distintos:

- catálogo: conocimiento histórico pinneado;
- plugin lock: selección exacta de una ejecución;
- readiness: confirmación de que esa ejecución puede comenzar.

Omitir candidatos fuzzy no bloquea. Una revisión solicitada queda pendiente
hasta importar decisiones auditadas y regenerar los contratos dependientes.
Las advertencias visuales no bloquean. Los conflictos
determinísticos, un plugin sin pin, un fallo de staging, OAuth/proxy incoherente
o cualquier pérdida académica efectiva sí bloquean.
