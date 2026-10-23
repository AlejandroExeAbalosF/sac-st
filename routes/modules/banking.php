<?php

declare(strict_types=1);

use App\Modules\Banking\Http\Controllers\BankAccountController;
use App\Modules\Banking\Http\Controllers\BankStatementImportController;
use App\Modules\Banking\Http\Controllers\BankTransactionController;
use Illuminate\Support\Facades\Route;

/*
| Banco: cuentas del organismo, importación de extractos, movimientos
| canónicos y su vinculación con eventos financieros.
|
| Lo último todavía no existe: `bank_transaction_allocations` necesita
| `financial_events`, que es de Ledger y es de la etapa anterior. Hasta
| entonces el módulo llega hasta el movimiento y su revisión.
*/

Route::middleware(['auth', 'verified'])->prefix('banco')->name('banco.')->group(function (): void {
    Route::middleware('can:banco.cuentas.ver')->group(function (): void {
        Route::get('cuentas', [BankAccountController::class, 'index'])->name('cuentas.index');
    });

    Route::middleware('can:banco.cuentas.gestionar')->group(function (): void {
        Route::post('cuentas', [BankAccountController::class, 'store'])->name('cuentas.store');
        Route::patch('cuentas/{account}', [BankAccountController::class, 'update'])
            ->whereNumber('account')
            ->name('cuentas.update');
    });

    Route::middleware('can:banco.extractos.ver')->group(function (): void {
        Route::get('extractos', [BankStatementImportController::class, 'index'])->name('extractos.index');
        Route::get('movimientos', [BankTransactionController::class, 'index'])->name('movimientos.index');
    });

    Route::middleware('can:banco.extractos.importar')->group(function (): void {
        Route::get('extractos/nuevo', [BankStatementImportController::class, 'create'])->name('extractos.create');
        /*
         * La vista previa es un POST porque sube el archivo, pero no
         * escribe nada: parsea, compara contra lo que ya hay y devuelve
         * el resultado. Importar es la segunda decisión, no la misma.
         */
        Route::post('extractos/previsualizar', [BankStatementImportController::class, 'preview'])
            ->name('extractos.preview');

        /*
         * Y su GET, que solo devuelve al primer paso.
         *
         * El asistente mantiene la barra de direcciones en `nuevo`, así que
         * nadie debería llegar acá por GET. Pero una entrada vieja del
         * historial o un enlace copiado sí pueden, y un «405 Method Not
         * Allowed» no le dice nada a quien solo quería importar un
         * extracto. No hay vista previa que reconstruir —el archivo vive
         * en el navegador—, de modo que empezar de nuevo es la única
         * respuesta honesta.
         */
        Route::get('extractos/previsualizar', fn () => to_route('banco.extractos.create'));
        Route::post('extractos', [BankStatementImportController::class, 'store'])->name('extractos.store');
    });

    Route::middleware('can:banco.extractos.ver')->group(function (): void {
        Route::get('extractos/{import}', [BankStatementImportController::class, 'show'])
            ->whereNumber('import')
            ->name('extractos.show');
    });

    Route::middleware('can:banco.extractos.revertir')->group(function (): void {
        Route::delete('extractos/{import}', [BankStatementImportController::class, 'destroy'])
            ->whereNumber('import')
            ->name('extractos.destroy');
    });

    Route::middleware('can:banco.movimientos.ignorar')->group(function (): void {
        Route::patch('movimientos/{transaction}/fuera-del-circuito', [BankTransactionController::class, 'ignore'])
            ->whereNumber('transaction')
            ->name('movimientos.ignore');
    });
});
