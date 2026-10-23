<?php

namespace App\Domain\Remote;

/**
 * The Consolidator writes its stops for the administrator at the server's
 * console: run ./INICIAR-…sh, edit exports/…csv, set config/oauth2.json. A web
 * user answers the same stop in the platform, so those sentences are dropped
 * from what the web shows; the original text stays in the technical details.
 */
class OperatorText
{
    /** A console script of the tool, a relative file of its runtime, or an absolute server path. */
    private const OPERATOR_ONLY = '~\./[A-Za-z0-9_-]+\.(?:sh|ps1)\b'
        .'|\b(?:exports|reports|config|copias|cache|scripts)/[\w./-]+'
        .'|(?<![\w:/])/(?:srv|opt|var|home|etc|tmp|root)/[\w./-]+'
        .'|\b(?:Regrese|Vuelva) al asistente\b~iu';

    private const WEB_STEP = 'Vuelva a esta página y confirme la intervención.';

    public static function forWebUser(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

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

    /** The original text, only when the web version had to leave something out. */
    public static function technical(?string $text): ?string
    {
        return $text !== null && self::forWebUser($text) !== trim($text) ? $text : null;
    }
}
