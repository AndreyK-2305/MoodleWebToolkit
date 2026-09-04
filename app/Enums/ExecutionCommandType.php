<?php

namespace App\Enums;

enum ExecutionCommandType: string
{
    case START = 'START';
    case CONTINUE = 'CONTINUE';
    case RESOLVE_CONFLICT = 'RESOLVE_CONFLICT';
    case RESUME = 'RESUME';
    case CANCEL = 'CANCEL';
    case PROPOSE = 'PROPOSE';
    case VALIDATE = 'VALIDATE';
    case FINALIZE = 'FINALIZE';
}
