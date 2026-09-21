<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Los mensajes del framework salen en castellano.
 *
 * `APP_LOCALE` es `es` y Laravel solo trae `en`: sin los archivos de
 * `lang/es`, cada mensaje se muestra como su propia clave. El operador que
 * erraba la contraseña veía «auth.failed», y el que dejaba un campo vacío,
 * «validation.required».
 *
 * Este test existe para que la próxima clave que agregue el framework —o la
 * que se olvide al actualizar— no vuelva a salir cruda a la pantalla.
 */
class MensajesEnCastellanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_rechazo_del_ingreso_se_explica_en_castellano(): void
    {
        $user = User::factory()->create();

        $this
            ->from(route('login'))
            ->post(route('login.store'), [
                'username' => $user->username,
                'password' => 'no-es-la-contrasena',
            ])
            ->assertSessionHasErrors([
                'username' => 'El usuario o la contraseña no coinciden.',
            ]);
    }

    public function test_un_campo_obligatorio_dice_cual_falta(): void
    {
        $this
            ->from(route('login'))
            ->post(route('login.store'), ['username' => '', 'password' => ''])
            ->assertSessionHasErrors([
                'username' => 'Falta el nombre de usuario.',
            ]);
    }

    /**
     * El caso que motivó el archivo: un mensaje sin traducir se muestra como
     * su clave, y una clave es exactamente lo que un contador no puede leer.
     */
    public function test_ninguna_clave_del_framework_queda_sin_traducir(): void
    {
        $origen = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        foreach (['auth', 'passwords', 'pagination', 'validation'] as $archivo) {
            $en = require "{$origen}/{$archivo}.php";
            $es = require lang_path("es/{$archivo}.php");

            $faltantes = array_diff_key(
                $this->flatten($en),
                $this->flatten($es),
            );

            $this->assertSame(
                [],
                array_keys($faltantes),
                "Faltan claves en lang/es/{$archivo}.php: se mostrarían crudas.",
            );
        }
    }

    public function test_existe_el_directorio_de_traducciones_del_locale_configurado(): void
    {
        $this->assertSame('es', config('app.locale'));
        $this->assertTrue(File::isDirectory(lang_path(config('app.locale'))));

        // El fallback también en castellano: si fuera `en`, cualquier clave
        // que falte se escaparía al inglés en vez de fallar acá.
        $this->assertSame('es', config('app.fallback_locale'));
    }

    /**
     * Las claves de tipo texto no viven en `lang/es/*.php` sino en
     * `lang/es.json`, y sin ese archivo `__('Not Found')` devuelve su propia
     * clave —que es texto en inglés—. Así estaban saliendo las pantallas de
     * error: «Page Expired» en la cara de quien se le venció la sesión.
     */
    public function test_las_claves_de_texto_del_framework_estan_traducidas(): void
    {
        $this->assertFileExists(lang_path('es.json'));

        $vistas = [
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views'),
            base_path('vendor/laravel/framework/src/Illuminate/Pagination/resources/views'),
        ];

        $traducidas = json_decode((string) file_get_contents(lang_path('es.json')), true);

        $faltantes = [];

        foreach ($vistas as $directorio) {
            foreach (File::files($directorio) as $archivo) {
                preg_match_all(
                    "/__\('([^']+)'/",
                    (string) file_get_contents($archivo->getPathname()),
                    $coincidencias,
                );

                foreach ($coincidencias[1] as $clave) {
                    // Las claves con punto se resuelven en `lang/es/*.php`,
                    // que ya cubre el test anterior.
                    if (str_contains($clave, '.') && ! str_ends_with($clave, '.')) {
                        continue;
                    }

                    if (! array_key_exists($clave, $traducidas)) {
                        $faltantes[] = $clave;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($faltantes)),
            'Faltan claves en lang/es.json: se mostrarían en inglés.',
        );
    }

    /**
     * Las pantallas de error son propias y están en castellano.
     *
     * Se renderiza la vista directamente y no por HTTP: con `APP_DEBUG`
     * encendido Laravel muestra la pantalla de depuración y el test no
     * probaría nada. De paso, esto revienta si una vista tiene un error de
     * Blade, que es justo lo que nadie descubre hasta la primera 500 real.
     *
     * @return array<string, array{int, string}>
     */
    public static function pantallasDeError(): array
    {
        return [
            '401' => [401, 'Necesitás iniciar sesión'],
            '403' => [403, 'No tenés permiso'],
            '404' => [404, 'No encontramos esa página'],
            '419' => [419, 'La sesión venció'],
            '429' => [429, 'Demasiados intentos'],
            '500' => [500, 'Algo falló de nuestro lado'],
            '503' => [503, 'mantenimiento'],
        ];
    }

    #[DataProvider('pantallasDeError')]
    public function test_la_pantalla_de_error_se_explica_en_castellano(int $codigo, string $esperado): void
    {
        $html = view("errors.{$codigo}", [
            'exception' => new HttpException($codigo),
        ])->render();

        $this->assertStringContainsString($esperado, $html);
        $this->assertStringContainsString('lang="es"', $html);

        foreach (['Not Found', 'Forbidden', 'Page Expired', 'Server Error', 'Whoops'] as $ingles) {
            $this->assertStringNotContainsString($ingles, $html);
        }
    }

    /**
     * El motivo que escribe quien pone la guarda gana sobre el texto genérico:
     * `abort(403, 'La caja del día ya está cerrada')` explica mucho mejor.
     */
    public function test_la_pantalla_de_403_muestra_el_motivo_cuando_viene(): void
    {
        $html = view('errors.403', [
            'exception' => new HttpException(403, 'La caja ya fue cerrada por otro operador.'),
        ])->render();

        $this->assertStringContainsString('La caja ya fue cerrada por otro operador.', $html);
    }

    /**
     * Ningún campo validado sale con el nombre de su columna.
     *
     * Un FormRequest puede nombrar sus campos en `attributes()`; el que no lo
     * hace queda a merced de la lista global de `validation.php`. Sin una de
     * las dos, el operador lee «El campo cbuFolio no debe ser mayor que 40
     * caracteres», y `cbuFolio` no es una palabra del área contable.
     *
     * La lectura es estática a propósito: instanciar cada request para
     * llamar a `rules()` necesita ruta y modelo resueltos, y un test que
     * necesita media aplicación levantada deja de correr al primer cambio.
     */
    public function test_todo_campo_validado_tiene_nombre_en_castellano(): void
    {
        $validacion = require lang_path('es/validation.php');
        $globales = array_keys($validacion['attributes']);

        $sinNombre = [];

        foreach (File::allFiles(app_path()) as $archivo) {
            $codigo = (string) file_get_contents($archivo->getPathname());

            if (! str_contains($codigo, 'extends FormRequest')) {
                continue;
            }

            // El que nombra sus propios campos se arregla solo.
            if (str_contains($codigo, 'public function attributes')) {
                continue;
            }

            if (preg_match('/function rules\(\).*?\n    }/s', $codigo, $bloque) !== 1) {
                continue;
            }

            preg_match_all("/^\s+'([a-zA-Z_][a-zA-Z_.*]*)'\s*=>/m", $bloque[0], $campos);

            foreach ($campos[1] as $campo) {
                if (! in_array($campo, $globales, true)) {
                    $sinNombre[] = $archivo->getFilename().': '.$campo;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($sinNombre)),
            'Estos campos saldrían con el nombre de la columna. Agregalos a '
            .'`attributes` en lang/es/validation.php, o dale al FormRequest '
            .'su propio `attributes()`.',
        );
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, string>
     */
    private function flatten(array $items, string $prefijo = ''): array
    {
        $planas = [];

        foreach ($items as $clave => $valor) {
            $ruta = $prefijo === '' ? (string) $clave : "{$prefijo}.{$clave}";

            if (is_array($valor)) {
                // `custom` y `attributes` son plantillas vacías en el
                // framework: no hay nada abajo que traducir.
                if ($valor === [] || in_array($ruta, ['custom', 'attributes'], true)) {
                    continue;
                }

                $planas += $this->flatten($valor, $ruta);

                continue;
            }

            $planas[$ruta] = (string) $valor;
        }

        return $planas;
    }
}
