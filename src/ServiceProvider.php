<?php

namespace HnhDigital\LaravelMultisiteRouter;

use App;
use App\Http\Kernel;
use Config;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class ServiceProvider extends RouteServiceProvider
{
    /**
     * Middleware.
     *
     * @var array
     */
    private $middelware = [];

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

        if (file_exists($bindings_file = base_path('routes/bindings.php'))) {
            require_once $bindings_file;
        }

        parent::boot();
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
            // Different URL makeup for local vs production
            if (env('APP_ENV') === 'local') {
                $server_port = ':'.$app->request->server('SERVER_PORT');
            }

            if (env('APP_DEV_NAME') != '') {
                $server_name = str_replace(['-'.env('APP_DEV_NAME'), '.'.env('APP_DEV_NAME')], '', $server_name);
            }

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
        new Kernel($app, $app->router);
        $this->middleware = $app->router->getMiddleware();

        if (function_exists('hookBeforeMultiSiteRouteProcessing')) {
            hookBeforeMultiSiteRouteProcessing();
        }

        foreach (config::get('multisite.sites') as $site => $domains) {
            if (file_exists(base_path('/routes/'.$site.'.php'))) {
                continue;
            }
            $domain = array_get($domains, 0);
            if (env('APP_DEV_NAME') != '') {
                foreach (config('multisite.allowed_domains', []) as $allowed_domain) {
                    $domain = str_replace('.'.$allowed_domain, '.'.env('APP_DEV_NAME').'.'.$allowed_domain, $domain);
                }
            }

            $this->mapRoute($domain, $site);
        }

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
    protected function mapRoute($domain, $site)
    {
        $middleware = [config::get('multisite.middleware.'.$site, 'web')];

        $middleware_types = ['menu', 'check'];

        foreach ($middleware_types as $middleware_type) {
            $middleware_name = $middleware_type.'-'.$site;
            if (array_has($this->middleware, $middleware_name)) {
                $middleware[] = $middleware_name;
            }
        }

        Route::group([
            'middleware' => $middleware,
            'domain'     => $domain,
            'namespace'  => 'App\\Http\\Controllers\\'.studly_case($site),
            'as'         => '['.$site.'] ',
        ], function ($group) use ($site) {
            self::loadRouteFile($site, 'default.php');
            $route_files = array_diff(scandir(base_path('/routes/'.$site)), ['.', '..', 'default.php']);
            foreach ($route_files as $file_name) {
                self::loadRouteFile($site, $file_name);
            }

            if (file_exists(base_path('/routes/filters/'.$site.'.php'))) {
                require_once base_path('/routes/filters/'.$site.'.php');
            }
        });
    }

    /**
     * Load the route.
     *
     * @return void
     */
    protected function loadRouteFile($site, $file_name)
    {
        $entries = explode('.', pathinfo($file_name, PATHINFO_FILENAME));

        $group_options = [];
        if (count($entries) >= 2) {
            array_pop($entries);
            $group_options['middleware'] = $entries;
        }

        Route::group($group_options, function ($group) use ($site, $file_name) {
            require_once base_path('/routes/'.$site.'/'.$file_name);
        });
    }
}
