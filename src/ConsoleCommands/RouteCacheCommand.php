<?php

namespace MultiSiteRouter\ConsoleCommands;

use Config;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\RouteCollection;

class RouteCacheCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'route:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a route cache file for faster route registration';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Create a new route command instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $this->call('route:clear');

        $settings = [];
        $settings[] = ['application' => 'system', 'organisation_id' => 0];
        $settings[] = ['application' => 'external', 'organisation_id' => 0];
        $settings[] = ['application' => 'api', 'organisation_id' => 1];
        $settings[] = ['application' => 'cms', 'organisation_id' => 1];
        $settings[] = ['application' => 'form', 'organisation_id' => 1];
        $settings[] = ['application' => 'manage', 'organisation_id' => 1];

        $route_cache = '';

        foreach ($settings as $config) {
            $routes = $this->getFreshApplicationRoutes($config);

            if (count($routes) > 0) {
                foreach ($routes as $route) {
                    $route->prepareForSerialization();
                }
                $route_cache .= $this->buildRouteCacheFile($routes);
            }
        }

        $this->files->put(
            $this->laravel->getCachedRoutesPath(), $route_cache
        );

        $this->info('Routes cached successfully!');
    }

    /**
     * Boot a fresh copy of the application and get the routes.
     *
     * @return \Illuminate\Routing\RouteCollection
     */
    protected function getFreshApplicationRoutes($config)
    {
        $_ENV['console_config'] = $config;
        $app = require $this->laravel->basePath().'/bootstrap/app.php';
        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
        return $app['router']->getRoutes();
    }

    /**
     * Build the route cache file.
     *
     * @param  \Illuminate\Routing\RouteCollection  $routes
     * @return string
     */
    protected function buildRouteCacheFile(RouteCollection $routes)
    {
        $stub = $this->files->get(__DIR__.'/stubs/routes.stub');
        $stub = str_replace('{{application}}', Config::get('server.application'), $stub);
        if (Config::get('server.organisation_id') > 0) {
            $stub = str_replace('{{organisation_id}}', '> 0', $stub);
        } else {
            $stub = str_replace('{{organisation_id}}', '== 0', $stub);
        }

        return str_replace('{{routes}}', base64_encode(serialize($routes)), $stub);
    }
}
