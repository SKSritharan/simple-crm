<?php

namespace App\Filament\Pages;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function getSubheading(): ?string
    {
        return 'Your customers, sales pipeline, and upcoming work at a glance.';
    }

    /** @return array<string, int> */
    public function getColumns(): array
    {
        return ['default' => 1, 'xl' => 2];
    }
}
