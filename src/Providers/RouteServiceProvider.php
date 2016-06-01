<?php

namespace MultiSiteRouter\Providers;

use App;
use Config;
use View;
use Illuminate\Routing\Router;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to the controller routes in your routes file.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function boot(Router $router)
    {
        //

        parent::boot($router);
    }

    /**
     * Define the routes for the application.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function map(Router $router)
    {
        if (App::runningInConsole() && isset($_ENV['console_config'])) {
            Config::set('server.application', $_ENV['console_config']['application']);
            Config::set('server.organisation_id', $_ENV['console_config']['organisation_id']);
        }
        $router->group(['namespace' => $this->namespace], function ($router) {
            if (!Config::get('server.organisation_id')) {
                Config::set('server.organisation_id', 0);
            } 
            if (Config::get('server.application') == 'manage' && !Config::get('server.organisation_id')) {
                Config::set('server.application', 'system');
            }
            switch (Config::get('server.application')) {
                case 'api':
                case 'cms':
                case 'external':
                case 'form':
                case 'manage':
                case 'system':
                    self::loadRoute(ucfirst(Config::get('server.application')));
                    break;
                default:
                    self::loadRoute('Cms');
            }
        });
    }

    /**
     * Load route file
     * @param  string $name
     * @param  string $path
     * @return boolean
     */
    public static function loadRoute($name, $path = '')
    {
        if (!empty($path) && stripos($path, 'Http\\Routes') !== false) {
            $file = base_path($path.'\\'.$name.'Routes.php');
        } else {
            if (!empty($path)) {
                $path .= '\\';
            }
            $file = app_path('Http\\Routes\\'.$path.$name.'Routes.php');
        }
        $file = str_replace('\\', '/', $file);
        if (file_exists($file)) {
            require $file;
            return true;
        }
        return false;
    }
}
