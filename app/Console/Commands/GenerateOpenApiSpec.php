<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('app:generate-open-api-spec')]
#[Description('Generate OpenAPI 3.1 specification from annotated controllers')]
class GenerateOpenApiSpec extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Generating OpenAPI 3.1 specification...');

        $output = public_path('docs/openapi.json');

        if (! file_exists(dirname($output))) {
            mkdir(dirname($output), 0755, true);
        }

        try {
            // Use the CLI approach that works with swagger-php 6.x
            $process = new Process(
                ['./vendor/bin/openapi', 'app/Http', '--format', 'json', '--output', $output],
                base_path()
            );

            $process->run();

            if (! $process->isSuccessful()) {
                $this->error('Failed to generate OpenAPI specification: '.$process->getErrorOutput());

                return self::FAILURE;
            }

            // Read the generated file to get stats
            $contents = file_exists($output) ? file_get_contents($output) : false;

            if ($contents !== false) {
                $openapi = json_decode($contents, true);
                $pathCount = count($openapi['paths'] ?? []);
                $schemaCount = count($openapi['components']['schemas'] ?? []);

                $this->info("OpenAPI specification written to: {$output}");
                $this->info("Total paths: {$pathCount}");
                $this->info("Total schemas: {$schemaCount}");

                return self::SUCCESS;
            }

            $this->error('Generated file not found');

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error generating OpenAPI specification: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
