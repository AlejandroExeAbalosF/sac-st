<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditAccessLoggerTest extends TestCase
{
    use RefreshDatabase;

    private string $auditPath;

    private string $consolePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditPath = storage_path('logs/audit-test-'.Str::random(12).'.log');
        $this->consolePath = storage_path('logs/audit-console-test-'.Str::random(12).'.log');
        config()->set('logging.channels.audit_file.path', $this->auditPath);
        config()->set('logging.channels.audit.channels', ['audit_file', 'audit_stderr']);
        config()->set('logging.channels.audit_stderr.handler_with.stream', $this->consolePath);
        Log::forgetChannel('audit');
        Log::forgetChannel('audit_file');
        Log::forgetChannel('audit_stderr');
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('audit');
        Log::forgetChannel('audit_file');
        Log::forgetChannel('audit_stderr');
        foreach (glob(substr($this->auditPath, 0, -4).'-*.log') ?: [] as $file) {
            unlink($file);
        }
        if (is_file($this->consolePath)) {
            unlink($this->consolePath);
        }

        parent::tearDown();
    }

    public function test_it_records_a_request_once_without_query_or_url_token(): void
    {
        $response = $this->get('/reset-password/secret-token?email=private@example.org');

        $response->assertHeader('X-Request-Id');
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame($events, array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->consolePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        ));
        $this->assertSame($response->headers->get('X-Request-Id'), $events[0]['id']);
        $this->assertSame('/reset-password/{token}', $events[0]['uri']);
        $this->assertSame('password.reset', $events[0]['route']);
        $this->assertSame('auth', $events[0]['event']);
        $this->assertArrayNotHasKey('context', $events[0]);
        $this->assertStringNotContainsString('secret-token', json_encode($events));
        $this->assertStringNotContainsString('private@example.org', json_encode($events));
    }

    public function test_failed_login_is_detected_despite_a_redirect(): void
    {
        $response = $this->post(route('login.store'), [
            'username' => 'usuario-inexistente',
            'password' => 'clave-secreta',
        ]);

        $response->assertRedirect();
        $event = $this->events()[0];
        $this->assertSame('auth_failed', $event['outcome']);
        $this->assertSame('login_failed', $event['context']['auth_event']);
        $this->assertSame('unknown_username', $event['context']['reason']);
        $this->assertIsInt($event['context']['login_event_id']);
        $this->assertStringNotContainsString('clave-secreta', json_encode($event));
    }

    public function test_domain_changes_are_linked_by_audit_id_without_values(): void
    {
        Route::post('/audit-test-change', function (): Response {
            $user = User::factory()->create();
            app(RecordAuditEvent::class)->handle(
                'usuario.prueba',
                $user,
                after: ['is_active' => true],
            );

            return response()->noContent();
        })->name('audit.test.change');

        $this->post('/audit-test-change')->assertNoContent();

        $change = $this->events()[0]['context']['changes'][0];
        $this->assertSame('usuario.prueba', $change['action']);
        $this->assertSame('User', $change['subject_type']);
        $this->assertSame(['is_active'], $change['changed']);
        $this->assertIsInt($change['audit_id']);
        $this->assertArrayNotHasKey('new_values', $change);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        $file = glob(substr($this->auditPath, 0, -4).'-*.log')[0] ?? null;
        $this->assertNotNull($file, 'No se creó el archivo de auditoría.');

        return array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }
}
