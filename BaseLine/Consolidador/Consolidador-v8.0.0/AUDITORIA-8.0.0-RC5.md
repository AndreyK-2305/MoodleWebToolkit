# Evidencia de entrega — Consolidador 8.0.0-linux-rc5

## Trazabilidad

- Base: `Consolidador-v8.0.0-linux-rc4 (2).zip`.
- SHA-256 base:
  `3cc84642f34a83c2771f8b6046878add9e804d79a2800255016623d4b86574ce`.
- La base no contiene `.git`; la entrega se identifica por SHA-256 del ZIP y
  su sidecar, sin inventar un commit.
- Versión final: `8.0.0-linux-rc5`.

## Causa raíz y corrección

1. `quiz_attempts`: inventarios legacy incluían previews no transportados. La
   vista efectiva ahora se proyecta desde el MBZ y solo acepta que el
   inventario sea superconjunto semántico.
2. Históricos: Moodle podía destruir `backup_ids_temp` tras un precheck con
   warnings o después del plan. Se rehidrata el precheck cuando corresponde y
   toda resolución posterior usa evidencia persistente estricta.
   La rehidratación preserva además `role_mappings` como el `stdClass` mutable
   esperado por Moodle; un contrato distinto bloquea antes de invocar el core.
3. `qtype=random`: la jerarquía se validaba antes de retirar residuos seguros
   de top. La misma validación sigue siendo obligatoria, pero se ejecuta tras
   la normalización fail-closed cuando hay candidatos.

## Alcance preservado

No se modifican scheduler, workers, plugins, Docker, OAuth general,
degradaciones, `user/icon`, Fases 1–12 ni el verificador final. No se han
modificado MBZ, exports, inventarios, manifests, planes, jobs, resoluciones,
checkpoints, estados ni datos del Moodle destino durante la construcción.

## Evidencia automatizada esperada

```text
V8_RC5_QUIZ_ATTEMPTS_OK matched=1 projected=legacy fail_closed=backup_preview count=effective
V8_RC5_PRECHECK_OK ready=1 warnings=rehydrated errors=blocked cleanup=1 post_execute=persistent
V8_RC5_HISTORICAL_USERS_OK persistent=1 adoption=strict oauth2=manual recovery=validated ambiguity=blocked
V8_RC5_RANDOM_ORDER_OK top_empty=valid top_random=normalized academic=blocked invariant=sealed
DISTRIBUTION_OK
```

La integración live se mantiene opt-in y la ejecución masiva no forma parte de
la construcción del paquete. El SHA-256 del ZIP final se registra en el
sidecar `Consolidador-v8.0.0-linux-rc5.zip.sha256`.
