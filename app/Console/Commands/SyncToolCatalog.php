<?php

namespace App\Console\Commands;

use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Console\Command;

class SyncToolCatalog extends Command
{
    protected $signature = 'tools:catalog:sync';

    protected $description = 'Verifica distribuciones BaseLine y sincroniza el catálogo local de herramientas';

    public function handle(): int
    {
        app(ToolCatalogSeeder::class)->run();
        $this->info('Catálogo sincronizado; las herramientas reales siguen sujetas a compatibilidad y feature flags.');

        return self::SUCCESS;
    }
}
