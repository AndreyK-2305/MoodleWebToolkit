<?php

namespace App\Domain\Remote;

/**
 * The Consolidator writes its stops for the administrator at the server's
 * console: run ./INICIAR-…sh, edit exports/…csv, set config/oauth2.json. A web
 * user answers the same stop in the platform, so those sentences are dropped
 * from what the web shows; the original text stays in the technical details.
 * Its stops also start with a code (V8_THEME_SELECTION_REQUIRED: defina
 * global_theme_selection…): a known code becomes a plain sentence and an
 * unknown one is dropped with the identifiers that follow it.
 */
class OperatorText
{
    /** A console script of the tool, a relative file of its runtime, or an absolute server path. */
    private const OPERATOR_ONLY = '~\./[A-Za-z0-9_-]+\.(?:sh|ps1)\b'
        .'|\b(?:exports|reports|config|copias|cache|scripts)/[\w./-]+'
        .'|(?<![\w:/])/(?:srv|opt|var|home|etc|tmp|root)/[\w./-]+'
        .'|\b(?:Regrese|Vuelva) al asistente\b~iu';

    private const WEB_STEP = 'Vuelva a esta página y confirme la intervención.';

    /** A leading code, with or without a colon: V8_NOT_READY, PLUGIN_INTERVENTION_PENDING: … */
    private const CODE = '/\A\s*([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\b:?\s*/u';

    /** Machine identifiers in the rest of the sentence: codes, snake_case keys and file names. */
    private const IDENTIFIER = '/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b|\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b|\b[\w-]+\.(?:json|csv|ps1|php|sh)\b/u';

    /** What the codes the web user meets most often mean, in plain words. */
    private const PLAIN = [
        'V8_IDENTITY_REVIEW_REQUIRED' => 'El Consolidador encontró cuentas que podrían ser de la misma persona. Decida en esta página si se unen o se mantienen separadas.',
        'V8_IDENTITY_REVIEW_IMPORTED' => 'La revisión de identidades se aplicó.',
        'V8_THEME_SELECTION_REQUIRED' => 'Falta elegir la apariencia (theme) general del Moodle consolidado. Elíjala en esta página.',
        'V8_LEGACY_THEME_DECISION_REQUIRED' => 'Algunas fuentes se recolectaron sin la información de apariencia (themes). Decida en esta página si continúa en modo compatible.',
        'V8_THEME_GLOBAL_NOT_AVAILABLE' => 'La apariencia (theme) elegida no está instalada en el destino. Elija otra.',
        'V8_NOT_READY' => 'El destino todavía no está listo para recibir los datos.',
        'V8_READINESS_REQUIRED_BEFORE_DESTINATION_WRITE' => 'El destino todavía no está listo para recibir los datos.',
        'PLUGIN_INTERVENTION_PENDING' => 'Hay que revisar los plugins que necesitan los orígenes antes de continuar.',
        'PLUGIN_INTERVENTION_APPROVED' => 'Los plugins quedaron aprobados.',
        'PLUGIN_TECHNICAL_VALIDATION_FAILED' => 'La validación técnica de los plugins falló.',
        'PLUGIN_UPGRADE_FAILED' => 'La instalación o actualización de plugins en el destino falló.',
        'V8_PLUGIN_STAGING_FAILED' => 'La prueba de los plugins antes de instalarlos en el destino falló.',
        'V8_CATEGORY_MAP_REQUIRED' => 'Revise en esta página cómo quedarán las categorías del Moodle consolidado: puede unir, renombrar y mover categorías antes de crearlas.',
        'V8_CATEGORY_MAP_INVALID' => 'El Consolidador no aceptó el mapa de categorías. Corrija en esta página los problemas señalados.',
    ];

    /** The family of an unknown code, when nothing readable is left after it. */
    private const FAMILIES = [
        'V8_IDENTITY_' => 'El Consolidador se detuvo en la revisión de identidades.',
        'V8_THEME_' => 'El Consolidador se detuvo en la apariencia (themes) del destino.',
        'V8_PLUGIN_' => 'El Consolidador se detuvo en los plugins del destino.',
        'V8_CATEGORY_MAP_' => 'El Consolidador se detuvo en el mapa de categorías del destino.',
        'PLUGIN_' => 'El Consolidador se detuvo en los plugins del destino.',
    ];

    private const UNKNOWN = 'El Consolidador se detuvo y necesita revisión.';

    public static function forWebUser(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $text = self::withoutCode($text);

        $blocks = preg_split('/\R\s*\R/u', trim($text)) ?: [];
        $kept = [];
        foreach ($blocks as $block) {
            if (preg_match(self::OPERATOR_ONLY, $block) !== 1) {
                $kept[] = $block;

                continue;
            }
            // A numbered step keeps its place in the list with the web equivalent.
            if (preg_match('/\A(\s*\d+[.)])\s/u', $block, $step) === 1) {
                $kept[] = $step[1].' '.self::WEB_STEP;

                continue;
            }
            // In a guide a paragraph is one idea: what is left of it would be out of context.
            if (count($blocks) > 1) {
                continue;
            }
            $sentences = array_filter(
                preg_split('/(?<=[.!?])\s+/u', $block) ?: [],
                fn (string $sentence): bool => preg_match(self::OPERATOR_ONLY, $sentence) !== 1,
            );
            if ($sentences !== []) {
                $kept[] = implode(' ', $sentences);
            }
        }
        $result = trim(implode("\n\n", $kept));

        return $result === '' ? null : $result;
    }

    /**
     * A leading code in plain words. The rest of the sentence stays when it reads as
     * a sentence; identifiers and stray codes after it go to the technical details.
     */
    private static function withoutCode(string $text): string
    {
        if (preg_match(self::CODE, $text, $match) !== 1) {
            return $text;
        }
        $code = $match[1];
        $rest = trim(substr($text, strlen($match[0])));
        // The rest of the first paragraph, unless it is made of identifiers.
        $parts = preg_split('/\R\s*\R/u', $rest, 2) ?: [''];
        $first = trim($parts[0]);
        $readable = $first !== '' && preg_match(self::IDENTIFIER, $first) !== 1 && preg_match('/\p{Ll}{3,}/u', $first) === 1;
        $plain = self::PLAIN[$code] ?? null;
        if ($plain === null && ! $readable) {
            $plain = collect(self::FAMILIES)->first(fn (string $sentence, string $prefix): bool => str_starts_with($code, $prefix)) ?? self::UNKNOWN;
        }
        $sentence = $readable ? mb_strtoupper(mb_substr($first, 0, 1)).mb_substr($first, 1) : '';
        $lead = trim(($plain ?? '').' '.$sentence);

        return trim($lead.(isset($parts[1]) ? "\n\n".$parts[1] : ''));
    }

    /** The original text, only when the web version had to leave something out. */
    public static function technical(?string $text): ?string
    {
        return $text !== null && self::forWebUser($text) !== trim($text) ? $text : null;
    }
}
