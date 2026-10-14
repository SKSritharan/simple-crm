<?php

namespace App\Enums;

enum DealStage: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
}
