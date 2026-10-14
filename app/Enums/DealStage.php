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

    /** @return array<self> */
    public static function openStages(): array
    {
        return [self::New, self::Qualified, self::Proposal, self::Negotiation];
    }
}
