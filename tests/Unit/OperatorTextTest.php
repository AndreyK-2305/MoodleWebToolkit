<?php

namespace Tests\Unit;

use App\Domain\Remote\OperatorText;
use PHPUnit\Framework\TestCase;

class OperatorTextTest extends TestCase
{
    public function test_a_stop_message_drops_the_console_sentence_and_keeps_the_rest(): void
    {
        $message = 'V8_IDENTITY_REVIEW_REQUIRED: IDENTITY_REVIEW_PREPARED; WAITING_USER_ACTION. '
            .'Edite exports/identity_candidate_review.csv y luego ejecute ./INICIAR-CONSOLIDACION.sh.';

        $this->assertSame('El Consolidador encontró cuentas que podrían ser de la misma persona. Decida en esta página si se unen o se mantienen separadas.',
            OperatorText::forWebUser($message));
        $this->assertSame($message, OperatorText::technical($message));
    }

    public function test_a_known_code_reads_as_a_plain_sentence_and_its_identifiers_go_to_the_technical_details(): void
    {
        $message = 'V8_THEME_SELECTION_REQUIRED: defina global_theme_selection; selection_source se registrará como config.';

        $web = (string) OperatorText::forWebUser($message);
        $this->assertStringStartsWith('Falta elegir la apariencia (theme) general', $web);
        $this->assertStringNotContainsString('global_theme_selection', $web);
        $this->assertStringNotContainsString('V8_', $web);
        $this->assertSame($message, OperatorText::technical($message));
    }

    public function test_a_code_followed_by_a_sentence_keeps_the_sentence(): void
    {
        $this->assertSame('El Consolidador encontró cuentas que podrían ser de la misma persona. Decida en esta página si se unen o se mantienen separadas. '
            .'El Consolidador no aceptó la revisión (código 3); revise las decisiones.',
            OperatorText::forWebUser('V8_IDENTITY_REVIEW_REQUIRED: el Consolidador no aceptó la revisión (código 3); revise las decisiones.'));
        // An unknown code keeps its readable sentence; with nothing readable, its family speaks.
        $this->assertSame('La conciliación no puede cambiar después de escribir en destino.',
            OperatorText::forWebUser('V8_IDENTITY_REVIEW_POST_WRITE_BLOCKED: la conciliación no puede cambiar después de escribir en destino.'));
        $this->assertSame('El Consolidador se detuvo en los plugins del destino.', OperatorText::forWebUser('V8_PLUGIN_TREE_MISMATCH: mod_vpl'));
        $this->assertSame('El Consolidador se detuvo y necesita revisión.', OperatorText::forWebUser('V8_MODE_INVALID'));
        $this->assertSame('No se pudo continuar: el destino rechazó la respuesta.', OperatorText::forWebUser('No se pudo continuar: el destino rechazó la respuesta.'));
    }

    public function test_a_guide_replaces_console_steps_and_drops_server_configuration(): void
    {
        $guide = implode("\n", [
            '1. En Google Cloud registre exactamente esta URI de redirección:',
            '   https://campus.example/admin/oauth2callback.php',
            '',
            '5. Regrese al asistente y use reintentar/continuar. Si la ejecución',
            '   estaba en segundo plano, ejecute de nuevo',
            '   ./INICIAR-SEGUNDO-PLANO.sh.',
            '',
            'Si existen varios servicios Google, escriba el ID correcto en',
            'config/oauth2.json, campo issuer_id. No coloque secretos allí.',
        ]);

        $web = (string) OperatorText::forWebUser($guide);

        $this->assertStringContainsString('https://campus.example/admin/oauth2callback.php', $web);
        $this->assertStringContainsString('5. Vuelva a esta página y confirme la intervención.', $web);
        $this->assertStringNotContainsString('.sh', $web);
        $this->assertStringNotContainsString('config/oauth2.json', $web);
        $this->assertStringNotContainsString('No coloque secretos', $web);
    }

    public function test_absolute_server_paths_are_left_to_the_technical_details(): void
    {
        $this->assertNull(OperatorText::forWebUser('Revise /srv/mwt-destinations/campus/reports/estado.log.'));
        $this->assertSame('Plugins aprobados.', OperatorText::forWebUser('Plugins aprobados.'));
        $this->assertNull(OperatorText::technical('Plugins aprobados.'));
        $this->assertNull(OperatorText::forWebUser(null));
    }
}
