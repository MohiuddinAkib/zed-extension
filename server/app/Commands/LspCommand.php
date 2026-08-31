<?php

namespace App\Commands;

use App\Lsp\Server;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class LspCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'lsp';

    /**
     * The console command description.
     */
    protected $description = 'Start the LSP server';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->server()->start();
    }

    /**
     * Create a new server instance.
     */
    protected function server(): Server
    {
        return PHP_OS_FAMILY === 'Windows'
            ? Server::stdio()
            : Server::asyncStdio();
    }
}
