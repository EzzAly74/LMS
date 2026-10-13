<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\ReflectionAnalyser;

/**
 * Regenerate storage/api-docs (Swagger) from the OpenAPI docblocks.
 *
 * L5-Swagger reads PHP attributes only unless an analyser is configured,
 * and this API is documented with docblocks, so `l5-swagger:generate` alone
 * finds nothing (it reports the root Info as missing). The analyser is an object,
 * which `config:cache` cannot store, so it is set here, for this run only.
 */
class GenerateApiDocs extends Command
{
    protected $signature = 'docs:generate';

    protected $description = 'Regenerate the Swagger docs (storage/api-docs) from the OpenAPI docblocks.';

    public function handle(): int
    {
        config()->set('l5-swagger.defaults.scanOptions.analyser', new ReflectionAnalyser([
            new AttributeAnnotationFactory(),
            new DocBlockAnnotationFactory(),
        ]));

        return $this->call('l5-swagger:generate');
    }
}
