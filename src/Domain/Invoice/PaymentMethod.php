<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

enum PaymentMethod: string
{
    case Wire = 'wire';
    case Check = 'check';
    case Cash = 'cash';
    case CreditCard = 'credit_card';
    case Other = 'other';
}
