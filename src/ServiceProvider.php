<?php

namespace HnhDigital\LaravelMultisiteRouter;

use App;
use App\Http\Kernel;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class ServiceProvider extends RouteServiceProvider
{
    /**
     * Local copy of the applications middleware.
     *
     * @var array
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateField)
     */
    private $middelware = [];

    /**
     * Simple routes.
     *
     * @var array
     */
    private $simple_routes = [];

    /**
     * Middleware types for automatic inclusion.
     *
     * @var array
     */
    private $middleware_types = [
        'menu', 'check'
    ];

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->serverName();
        $this->loadFile(base_path('routes/multisite.php'));
        $this->loadFile(base_path('routes/bindings.php'));
        parent::boot();
    }

    /**
     * Define server name and session naming.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExitExpression)
     */
    private function serverName()
    {
        global $app;

        // No adjustments required when running in console.
        if (App::runningInConsole()) {
            return;
        }

        // Get the server name.
        $full_server_name = $server_name = $app->request->server('HTTP_HOST');

        // Convert if the dev name is included with a hyphen.s
        if (env('APP_DEV_NAME') != '') {
            $server_name = str_replace(['-'.env('APP_DEV_NAME'), '.'.env('APP_DEV_NAME')], '', $server_name);
        }

        // Redirect if servername contains an underscore, or the server name begins with www.
        if (stripos($server_name, '_') !== false || stripos($server_name, 'www.') !== false) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: '.'http'.((request()->secure()) ? 's' : '').'://'.str_replace(['_', 'www.'], ['-', ''], $app->request->server('HTTP_HOST')));
            exit();
        }

        // Update the configuration.
        $app['config']->set('multisite.name', $server_name);
        $app['config']->set('session.domain', $full_server_name);
        $app['config']->set('session.cookie', $app['config']->get('multisite.default_session_name'));
    }

    /**
     * Load a file and run the closure if it returns that.
     *
     * @param string $path
     *
     * @return void
     */
    private function loadFile($path, $local_variables = [])
    {
        if (file_exists($path)) {
            extract($local_variables);

            $closure = include_once $path;
            if ($closure instanceof \Closure) {
                $closure();
            }
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

        // Start a new kernel.
        new Kernel($app, $app->router);

        // Get the middleware.
        $this->middleware = $app->router->getMiddleware();

        // Interate through sites list.
        foreach ($app['config']->get('multisite.sites') as $site => $domain) {
            $this->mapSite($site, $domain);            
        }
    }

    /**
     * Map the given site/domain.
     *
     * @param string $domain
     * @param string $site
     *
     * @return array
     */
    private function mapSite($site, $domain)
    {
        global $app;

        // Ignore sites that have a single route file.
        if (file_exists(base_path('/routes/'.$site.'.php'))) {
            $this->simple_routes[] = $site;
            return;
        }

        // Replace the development name if set.
        if (env('APP_DEV_NAME') != '') {
            foreach ($app['config']->get('multisite.allowed_domains', []) as $allowed_domain) {
                $domain = str_replace('.'.$allowed_domain, '.'.env('APP_DEV_NAME').'.'.$allowed_domain, $domain);
            }
        }

        // Map these routes.
        $this->mapRoute($site, $domain);
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @param string $site
     * @param string $domain
     *
     * @return void
     */
    protected function mapRoute($site, $domain)
    {
        global $app;

        // Setup middleware array. Default to web.
        $middleware_array = [$app['config']->get('multisite.middleware.'.$site, 'web')];

        // Check if these middleware types exist.
        foreach ($this->middleware_types as $middleware_type) {
            $middleware_name = $middleware_type.'-'.$site;
            if (array_has($this->middleware, $middleware_name)) {
                $middleware_array[] = $middleware_name;
            }
        }

        // Create a route group around these routes.
        Route::group([
            'middleware' => $middleware_array,
            'domain'     => $domain,
            'namespace'  => 'App\\Http\\Controllers\\'.studly_case($site),
            'as'         => '['.$site.'] ',
        ], function ($group) use ($site) {

            // Include the default file.
            $this->loadRouteFile($site, 'default.php');

            // Scan all route files, excluding the default.
            $route_files = array_diff(scandir(base_path('/routes/'.$site)), ['.', '..', 'default.php']);

            // Load and process each route file.
            foreach ($route_files as $file_name) {
                $this->loadRouteFile($site, $file_name);
            }

            // Apply route filters if file is exists.
            $this->loadFile(base_path('/routes/filters/'.$site.'.php'), ['group' => $group]);
        });
    }

    /**
     * Load the route.
     *
     * @param string $site
     * @param string $path
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    protected function loadRouteFile($site, $path)
    {
        // Discover middleware in filename.
        $entries = explode('.', pathinfo($path, PATHINFO_FILENAME));

        // Allocate middleware to the group.
        $group_options = [];
        if (count($entries) >= 2) {
            array_pop($entries);
            $group_options['middleware'] = $entries;
        }

        // Include the file at the given path.
        Route::group($group_options, function ($group) use ($site, $path) {
            require_once base_path('/routes/'.$site.'/'.$path);
        });
    }
}
