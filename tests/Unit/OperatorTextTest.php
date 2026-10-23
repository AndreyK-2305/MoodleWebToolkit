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

        $this->assertSame('V8_IDENTITY_REVIEW_REQUIRED: IDENTITY_REVIEW_PREPARED; WAITING_USER_ACTION.', OperatorText::forWebUser($message));
        $this->assertSame($message, OperatorText::technical($message));
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
