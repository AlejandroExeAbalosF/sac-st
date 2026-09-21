<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/** Si el haber se salda de una vez o en cuotas. */
enum PaymentTerms: string
{
    case Single = 'single';
    case Installments = 'installments';
}
