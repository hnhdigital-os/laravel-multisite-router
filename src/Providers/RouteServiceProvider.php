<?php

namespace MultiSiteRouter\Providers;

use App;
use Config;
use Illuminate\Routing\Router;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Resource;
use View;

class RouteServiceProvider extends ServiceProvider
{

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function boot(Router $router)
    {
        global $app;
        parent::boot($router);

        if (!App::runningInConsole()) {
            $server_name = $app->request->server('HTTP_HOST');

            if ($server_name !== 'localhost') {
                $full_server_name = $server_name;

                // Different URL makeup for local vs public system
                if (env('APP_ENV') === 'local') {
                    $server_port = ':'.$app->request->server('SERVER_PORT');
                    $server_name = str_replace('.'.env('APP_DEV_NAME'), '', $server_name);
                } else {
                    $server_name = str_replace('-'.env('APP_DEV_NAME'), '', $server_name);
                }

                // Remove underscore and redirect to dashed version
                if (stripos($server_name, '_') !== false) {
                    header("HTTP/1.1 301 Moved Permanently"); 
                    header('Location: '.'http'.((request()->secure()) ? 's' : '').'://'.str_replace('_', '-', $app->request->server('HTTP_HOST')));
                    exit();
                }

                $app['config']->set('multisite.name', $server_name);
                $app['config']->set('session.domain', $full_server_name);
                $app['config']->set('session.cookie', $app['config']->get('multisite.default_session_name'));

                if (function_exists('hookMultiSiteGetConfig')) {
                    hookMultiSiteGetConfig();
                }
            }
        }

        if (file_exists($bindings_file = base_path('routes/ModelBindings.php'))) {
            require_once($bindings_file);
            \Routes\ModelBindings::boot($router);
        }
    }

    /**
     * Define the routes for the application.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function map(Router $router)
    {
        global $app;

        if (App::runningInConsole() && isset($_ENV['console_config'])) {
            $app['config']->set('multisite.current_site', $_ENV['console_config']['current_site']);
            foreach ($app['config']->get('multisite.site_variable_defaults', []) as $variable_name => $default_value) {
                $app['config']->set('multisite.'.$variable_name, $_ENV['console_config'][$variable_name]);
            }
        }

        if (function_exists('hookBeforeMultiSiteRouteProcessing')) {
            hookBeforeMultiSiteRouteProcessing();
        }

        $router->group(['namespace' => $app['config']->get('multisite.controller_namespace')], function ($router) {
            global $app;
            foreach ($app['config']->get('multisite.site_variable_defaults', []) as $variable_name => $default_value) {
                if (!$app['config']->get('multisite.'.$variable_name)) {
                    $app['config']->set('multisite.'.$variable_name, $default_value);
                }
            }

            if (function_exists('hookBeforeMultiSiteLoadRoute')) {
                hookBeforeMultiSiteLoadRoute();
            }

            $sites_list = $app['config']->get('multisite.sites');            
            if (isset($sites_list[$app['config']->get('multisite.current_site')])) {
                self::loadRoute(ucfirst($app['config']->get('multisite.current_site')));
            } else {
                self::loadRoute($app['config']->get('multisite.controller_namespace'));
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
        global $app;

        if (!empty($path) && stripos($path, $app['config']->get('multisite.router_namespace')) !== false) {
            $file = base_path('routes/'.$path.'\\'.$name.'Routes.php');
        } else {
            if (!empty($path)) {
                $path .= '\\';
            }
            $file = base_path('routes/'.$app['config']->get('multisite.router_namespace').'\\'.$path.$name.'Routes.php');
        }
        $file = str_replace('\\', '/', $file);
        if (file_exists($file)) {
            require $file;
            return true;
        }
        return false;
    }
}
