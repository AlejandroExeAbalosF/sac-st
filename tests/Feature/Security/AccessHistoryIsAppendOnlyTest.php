<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * El historial de accesos es append-only y eso lo garantiza el motor, no
 * la buena conducta del código. Este test existe para que la garantía no
 * se pierda en silencio si alguien toca la migración.
 *
 * Es también el ensayo del mecanismo que la Fase 4 va a aplicar sobre
 * journal_lines, financial_events y receipts.
 */
class AccessHistoryIsAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_rejects_an_update_even_bypassing_eloquent()
    {
        $event = $this->givenAnEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('user_login_events')->where('id', $event->id)->update(['ip_address' => '10.0.0.1']);
    }

    public function test_the_database_rejects_a_delete_even_bypassing_eloquent()
    {
        $event = $this->givenAnEvent();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        DB::table('user_login_events')->where('id', $event->id)->delete();
    }

    public function test_eloquent_stops_the_write_before_it_reaches_the_database()
    {
        $event = $this->givenAnEvent();

        $this->expectException(RuntimeException::class);

        $event->update(['ip_address' => '10.0.0.1']);
    }

    public function test_an_unknown_event_type_is_rejected()
    {
        $this->expectException(QueryException::class);

        DB::table('user_login_events')->insert([
            'event_type' => 'se_fue_a_tomar_un_cafe',
            'created_at' => now(),
        ]);
    }

    /**
     * Un motivo de fallo solo tiene sentido cuando algo falló: un ingreso
     * exitoso con `failure_reason` sería un dato contradictorio.
     */
    public function test_a_failure_reason_can_not_accompany_a_successful_login()
    {
        $this->expectException(QueryException::class);

        DB::table('user_login_events')->insert([
            'event_type' => LoginEventType::LoginSuccess->value,
            'failure_reason' => 'invalid_credentials',
            'created_at' => now(),
        ]);
    }

    public function test_a_user_with_history_can_not_be_deleted()
    {
        $user = User::factory()->create();
        $this->givenAnEvent($user);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $user->id)->delete();
    }

    private function givenAnEvent(?User $user = null): UserLoginEvent
    {
        return UserLoginEvent::query()->create([
            'user_id' => $user?->id,
            'username_attempted' => $user?->username ?? 'alguien',
            'event_type' => LoginEventType::LoginSuccess,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }
}
