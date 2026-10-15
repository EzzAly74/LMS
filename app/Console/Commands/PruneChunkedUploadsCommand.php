<?php

namespace App\Console\Commands;

use App\Services\ChunkedUploadService;
use Illuminate\Console\Command;

/** Daily: remove module uploads started but not completed within a day (D-111). */
class PruneChunkedUploadsCommand extends Command
{
    protected $signature = 'uploads:prune-chunks';

    protected $description = 'Remove unfinished chunked uploads older than a day';

    public function handle(ChunkedUploadService $uploads): int
    {
        $this->info('Removed '.$uploads->prune().' unfinished upload(s).');

        return self::SUCCESS;
    }
}
