<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use Illuminate\Mail\MailManager;
use Tests\TestCase;

/**
 * El mailer de producción elige solo entre el SMTP del organismo y Gmail.
 *
 * Se prueba el archivo de configuración y no un envío: la decisión vive
 * ahí, en función de `MAIL_HOST`, y es lo que tiene que seguir valiendo el
 * día que el organismo asigne su SMTP y alguien cargue la variable.
 */
class CorreoTest extends TestCase
{
    public function test_sin_smtp_propio_va_directo_a_gmail(): void
    {
        $this->assertSame(['gmail'], $this->failoverCon(''));
    }

    public function test_con_smtp_propio_gmail_queda_de_respaldo(): void
    {
        $this->assertSame(['smtp', 'gmail'], $this->failoverCon('smtp.organismo.gob.ar'));
    }

    public function test_el_failover_se_arma_con_gmail_en_el_587(): void
    {
        config([
            'mail.mailers.failover.mailers' => ['smtp', 'gmail'],
            'mail.mailers.smtp.host' => 'smtp.organismo.gob.ar',
        ]);

        $transporte = (string) app(MailManager::class)->mailer('failover')->getSymfonyTransport();

        $this->assertStringContainsString('smtp.organismo.gob.ar', $transporte);
        $this->assertStringContainsString('smtp.gmail.com:587', $transporte);
    }

    private function failoverCon(string $host): mixed
    {
        $antes = $_SERVER['MAIL_HOST'] ?? null;
        $_SERVER['MAIL_HOST'] = $host;

        try {
            $config = require config_path('mail.php');

            return $config['mailers']['failover']['mailers'];
        } finally {
            if ($antes === null) {
                unset($_SERVER['MAIL_HOST']);
            } else {
                $_SERVER['MAIL_HOST'] = $antes;
            }
        }
    }
}
