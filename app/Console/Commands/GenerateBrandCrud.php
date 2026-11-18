<?php

namespace App\Console\Commands;

use App\Platform\Crud\BrandCrudDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class GenerateBrandCrud extends Command
{
    protected $signature = 'lba:crud:generate {definition : Path to the exported JSON} {--output= : Directory in a development checkout for the generated class}';

    protected $description = 'Generate the reviewed Principal Master Brand configuration from an exported definition';

    public function handle(BrandCrudDefinition $catalog): int
    {
        if (! $this->laravel->environment('local', 'testing')) {
            $this->error('CRUD generation is available in development only.');

            return self::FAILURE;
        }

        try {
            $input = json_decode(File::get($this->argument('definition')), true, 32, JSON_THROW_ON_ERROR);
            $source = $catalog->render(is_array($input) ? $input : []);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        } catch (JsonException|RuntimeException) {
            $this->error('The definition file must contain readable JSON.');

            return self::FAILURE;
        }

        $directory = $this->option('output') ?: app_path('Platform/Crud/Generated');
        $target = $directory.DIRECTORY_SEPARATOR.'BrandCrud.php';

        if (! is_dir($directory) || file_exists($target) || is_link($target)) {
            $this->error("Output directory is unavailable or the target already exists: {$target}");

            return self::FAILURE;
        }

        $temporary = tempnam($directory, '.brand-');
        if ($temporary === false) {
            $this->error("Could not prepare output in {$directory}.");

            return self::FAILURE;
        }

        try {
            if (file_put_contents($temporary, $source) !== strlen($source) || ! @link($temporary, $target)) {
                $this->error("Could not create {$target}. Check for an existing file and directory permissions.");

                return self::FAILURE;
            }
        } finally {
            unlink($temporary);
        }

        $this->info("Generated {$target}");

        return self::SUCCESS;
    }
}
