<?php

namespace MultiSiteRouter\Providers;

use App;
use Config;
use MultiSiteRouter\ConsoleCommands\RouteCacheCommand;
use MultiSiteRouter\ConsoleCommands\RouteListCommand;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->serverName();        

        if (file_exists($multisite_file = base_path('routes/multisite.php'))) {
            $multisite_function = include_once $multisite_file;
            if ($multisite_function instanceof \Closure) {
                $multisite_function();
            }
        }

        parent::boot();

        if (file_exists($bindings_file = base_path('routes/bindings.php'))) {
            require_once $bindings_file;
        }
    }

    /**
     * Define server name and session naming.
     *
     * @return void
     */
    private function serverName()
    {
        global $app;

        // Development specific
        if (!App::runningInConsole() && ($full_server_name = $server_name = $app->request->server('HTTP_HOST')) !== 'localhost') {
            // Different URL makeup for local vs public system
            if (env('APP_ENV') === 'local') {
                $server_port = ':'.$app->request->server('SERVER_PORT');
            }

            $server_name = str_replace(['-'.env('APP_DEV_NAME'), '.'.env('APP_DEV_NAME')], '', $server_name);

            // Remove underscore and redirect to dashed version
            if (stripos($server_name, '_') !== false || stripos($server_name, 'www.') !== false) {
                header('HTTP/1.1 301 Moved Permanently');
                header('Location: '.'http'.((request()->secure()) ? 's' : '').'://'.str_replace(['_', 'www.'], ['-', ''], $app->request->server('HTTP_HOST')));
                exit();
            }

            $app['config']->set('multisite.name', $server_name);
            $app['config']->set('session.domain', $full_server_name);
            $app['config']->set('session.cookie', $app['config']->get('multisite.default_session_name'));
        }
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        global $app;

        if (App::runningInConsole() && isset($_ENV['console_config'])) {
            $app = $_ENV['console_config']['app'];
            $app['config']->set('multisite.name', $_ENV['console_config']['name']);
            $app['config']->set('multisite.current_site', $_ENV['console_config']['current_site']);
            foreach ($app['config']->get('multisite.site_variable_defaults', []) as $variable_name => $default_value) {
                $app['config']->set('multisite.'.$variable_name, $_ENV['console_config'][$variable_name]);
            }
        }

        if (function_exists('hookBeforeMultiSiteRouteProcessing')) {
            hookBeforeMultiSiteRouteProcessing();
        }

        $this->mapRoute($app);

        if (function_exists('hookAfterMultiSiteRouteProcessing')) {
            hookAfterMultiSiteRouteProcessing();
        }
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapRoute($app)
    {
        $available_middleware = $app->router->getMiddleware();
        $site = config::get('multisite.current_site');
        $middleware = [config::get('multisite.middleware.'.$site, 'web')];

        $middleware_types = ['menu', 'check'];

        foreach ($middleware_types as $middleware_type) {
            if (array_has($available_middleware, $middleware_type.'-'.$site, false)) {
                $middleware[] = $middleware_type.'-'.$site;
            }
        }

        Route::group([
            'middleware' => $middleware,
            'namespace'  => 'App\\Http\\Controllers\\'.studly_case($site),
        ], function ($router) use ($site) {
            self::loadRouteFile($site, 'default.php');
            $routes = array_diff(scandir(base_path('/routes/'.$site)), ['.', '..', 'default.php']);
            foreach ($routes as $name) {
                self::loadRouteFile($site, $name);
            }
        });
    }

    /**
     * Load the route.
     *
     * @return void
     */
    protected function loadRouteFile($site, $route_file)
    {
        $entries = explode('.', pathinfo($route_file, PATHINFO_FILENAME));

        $middleware = [];
        if (count($entries) >= 2) {
            array_pop($entries);
            $middleware = ['middleware' => $entries];
        }

        Route::group($middleware, function () use ($site, $route_file) {
            require_once base_path('/routes/'.$site.'/'.$route_file);
        });        
    }
}
