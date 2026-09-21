<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Mantiene al día, en memoria, las columnas que calcula PostgreSQL.
 *
 * `people.name`, `people.search_name` y `users.name` son columnas generadas:
 * la base las arma a partir del apellido, el nombre y la razón social, y por
 * eso no pueden desincronizarse ni quedar vacías. Lo que sí pasa es que
 * **no vuelven en el `INSERT` ni en el `UPDATE`**: Eloquent no relee la fila
 * después de escribir, así que el objeto se queda con el valor viejo —o,
 * bajo `Model::shouldBeStrict`, sin el atributo, y leerlo explota.
 *
 * Resolverlo con un `refresh()` en cada lugar que escribe funcionaría hasta
 * el primer lugar nuevo que alguien se olvide. Este trait lo hace una vez,
 * pega una sola consulta y solo cuando hace falta: al crear, o cuando cambió
 * alguna de las columnas de las que el cálculo depende.
 */
trait HasGeneratedColumns
{
    public static function bootHasGeneratedColumns(): void
    {
        static::saved(static function (Model $model): void {
            /** @var static $model */
            if ($model->wasRecentlyCreated || $model->wasChanged($model->generatedFrom())) {
                $model->syncGeneratedColumns();
            }
        });
    }

    public function syncGeneratedColumns(): void
    {
        $columns = $this->generatedColumns();

        $fresh = $this->newQueryWithoutScopes()
            ->getQuery()
            ->where($this->getKeyName(), $this->getKey())
            ->first($columns);

        if ($fresh === null) {
            return;
        }

        foreach ($columns as $column) {
            $this->setAttribute($column, $fresh->{$column});
        }

        // Sin esto las columnas recién traídas quedarían marcadas como
        // sucias y el siguiente `save()` intentaría escribirlas, que es
        // justamente lo que PostgreSQL prohíbe sobre una columna generada.
        $this->syncOriginalAttributes($columns);
    }

    /**
     * Las columnas que calcula la base y nunca se escriben desde PHP.
     *
     * @return list<string>
     */
    abstract protected function generatedColumns(): array;

    /**
     * Las columnas de las que depende ese cálculo.
     *
     * @return list<string>
     */
    abstract protected function generatedFrom(): array;
}
