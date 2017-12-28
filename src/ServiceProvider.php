<?php

namespace HnhDigital\LaravelMultisiteRouter;

use App;
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
     * Middleware types for automatic inclusion.
     *
     * @var array
     */
    private $middleware_types = [
        'menu', 'check',
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
        $this->loadFile(base_path('routes/patterns.php'));
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
        // No adjustments required when running in console.
        if (App::runningInConsole()) {
            return;
        }

        // Get the server name.
        $full_server_name = $server_name = $this->app->request->server('HTTP_HOST');

        // Convert if the dev name is included with a hyphen.
        if (env('APP_DEV_NAME') != '') {
            $server_name = str_replace(['-'.env('APP_DEV_NAME'), '.'.env('APP_DEV_NAME')], '', $server_name);
            $server_name = str_replace(env('APP_DEV_NAME'), '', $server_name);
        }

        // Redirect if servername contains an underscore, or the server name begins with www.
        if (stripos($server_name, '_') !== false || stripos($server_name, 'www.') !== false) {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: '.'http'.((request()->secure()) ? 's' : '').'://'.str_replace(['_', 'www.'], ['-', ''], $this->app->request->server('HTTP_HOST')));
            exit();
        }

        // Sub domain. Removed allowed domains.
        $current_site = $sub_domain = substr(str_replace($this->app['config']->get('multisite.allowed.domains'), '', $server_name), 0, -1);

        // Server name. Remove the sub-domain partial.
        $current_domain = substr($server_name, strlen($sub_domain) + 1);

        // Explode the sub domain.
        $sub_domain_elements = explode('.', $sub_domain);

        // Get the first part. This is use to describe the site.
        $current_site = array_get($sub_domain_elements, 0);

        // Update the platform using the sub domains remaining partials.
        $current_group = array_get($sub_domain_elements, 1, '');

        // Update the current multisite configuration.
        $this->app['config']->set('multisite.current.domain', $current_domain);
        $this->app['config']->set('multisite.current.site', $current_site);
        $this->app['config']->set('multisite.current.group', $current_group);

        // Update the session configuration.
        $this->app['config']->set('session.domain', $full_server_name);
        $this->app['config']->set('session.cookie', $this->app['config']->get('multisite.default.session'));
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
        $local_variables['app'] = $this->app;

        if (file_exists($path)) {
            extract($local_variables);

            $closure = require $path;

            // A closure was returned instead of just running code.
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
        // Get the middleware.
        $this->middleware = $this->app->router->getMiddleware();

        // Interate through sites list.
        foreach ($this->app['config']->get('multisite.sites') as $site => $domain) {
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
        // Ignore sites that have a single route file.
        if (file_exists(base_path('/routes/'.$site.'.php'))) {
            return;
        }

        // Replace the development name if set.
        if (env('APP_DEV_NAME') != '') {
            foreach ($this->app['config']->get('multisite.allowed.domains', []) as $allowed_domain) {
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
        // Setup middleware array. Default to web.
        $middleware_array = [$this->app['config']->get('multisite.site.middleware.'.$site, 'web')];

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

            // Apply route filters if file exists.
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
            $this->loadFile(base_path('/routes/'.$site.'/'.$path), ['group' => $group]);
        });
    }
}
