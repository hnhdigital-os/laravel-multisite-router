<?php

namespace MultiSiteRouter\ConsoleCommands;

use Config;
use Illuminate\Console\Command;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class RouteListCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'route:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered routes';

    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * An array of all the registered routes.
     *
     * @var \Illuminate\Routing\RouteCollection
     */
    protected $routes;

    /**
     * The table headers for the command.
     *
     * @var array
     */
    protected $headers = ['Site', 'Domain', 'Method', 'URI', 'Name', 'Action', 'Middleware'];

    /**
     * The table columns for the command.
     *
     * @var array
     */
    protected $columns = ['site', 'host', 'method', 'uri', 'name', 'action', 'middleware'];

    /**
     * Create a new route command instance.
     *
     * @param \Illuminate\Routing\Router $router
     *
     * @return void
     */
    public function __construct(Router $router)
    {
        parent::__construct();
        $this->router = $router;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $this->displayRoutes($this->getRoutes());
    }

    /**
     * Compile the routes into a displayable format.
     *
     * @return array
     */
    protected function getRoutes()
    {
        $columns = $this->getHeaders(true);

        $results = [];

        if ($this->option('site')) {
            $sites_list = [$this->option('site') => Config::get('multisite.sites.'.$this->option('site'))];
        } else {
            $sites_list = Config::get('multisite.sites');
        }

        foreach ($sites_list as $site_name => $config) {
            $config['current_site'] = $site_name;
            $routes = $this->getFreshApplicationRoutes($config);

            foreach ($routes as $route) {
                $current_route_data = $this->getRouteInformation($route);
                $route_data = [];
                foreach ($columns as $column_name) {
                    $route_data[$column_name] = $current_route_data[$column_name];
                }
                $results[] = $route_data;
            }
        }

        if ($sort = $this->option('sort')) {
            if (!in_array($sort, $columns)) {
                $sort = $columns[0];
            }
            $results = Arr::sort($results, function ($value) use ($sort) {
                return $value[$sort];
            });
        }

        if ($this->option('reverse')) {
            $results = array_reverse($results);
        }

        return array_filter($results);
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
     * Get the headers.
     *
     * @return array
     */
    protected function getHeaders($column_names = false)
    {
        if ($this->option('column')) {
            $headers = [];
            $columns = explode(',', $this->option('column'));

            foreach ($this->headers as $key => $value) {
                if (in_array($value, $columns)) {
                    if ($column_names) {
                        $headers[] = $this->columns[$key];
                    } else {
                        $headers[] = $value;
                    }
                }
            }
            if (count($headers)) {
                return $headers;
            }
        }
        if ($column_names) {
            return $this->columns;
        }

        return $this->headers;
    }

    /**
     * Get the route information for a given route.
     *
     * @param \Illuminate\Routing\Route $route
     *
     * @return array
     */
    protected function getRouteInformation(Route $route)
    {
        return $this->filterRoute([
            'site'       => $_ENV['console_config']['current_site'],
            'host'       => $route->domain(),
            'method'     => implode('|', $route->methods()),
            'uri'        => $route->uri(),
            'name'       => $route->getName(),
            'action'     => $route->getActionName(),
            'middleware' => $this->getMiddleware($route),
        ]);
    }

    /**
     * Display the route information on the console.
     *
     * @param array $routes
     *
     * @return void
     */
    protected function displayRoutes(array $routes)
    {
        if ($this->option('no-table')) {
            $output = [];
            foreach ($routes as $route_data) {
                $show_row = false;
                if ($this->option('no-empty')) {
                    foreach ($route_data as $key => $value) {
                        if (!empty($value)) {
                            $show_row = true;
                            break;
                        }
                    }
                } else {
                    $show_row = true;
                }

                if ($show_row) {
                    $output[] = implode('|', array_values($route_data));
                }
            }
            if (count($output)) {
                $this->line($output);
            }
        } else {
            $this->table($this->getHeaders(), $routes);
        }
    }

    /**
     * Get before filters.
     *
     * @param \Illuminate\Routing\Route $route
     *
     * @return string
     */
    protected function getMiddleware($route)
    {
        $middlewares = array_values($route->middleware());

        $actionName = $route->getActionName();

        if (!empty($actionName) && $actionName !== 'Closure') {
            $middlewares = array_merge($middlewares, $this->getControllerMiddleware($actionName));
        }

        return implode(',', $middlewares);
    }

    /**
     * Get the middleware for the given Controller@action name.
     *
     * @param string $actionName
     *
     * @return array
     */
    protected function getControllerMiddleware($actionName)
    {
        Controller::setRouter($this->laravel['router']);

        $segments = explode('@', $actionName);

        return $this->getControllerMiddlewareFromInstance(
            $this->laravel->make($segments[0]), $segments[1]
        );
    }

    /**
     * Get the middlewares for the given controller instance and method.
     *
     * @param \Illuminate\Routing\Controller $controller
     * @param string                         $method
     *
     * @return array
     */
    protected function getControllerMiddlewareFromInstance($controller, $method)
    {
        $middleware = $this->router->getMiddleware();

        $results = [];

        foreach ($controller->getMiddleware() as $name => $options) {
            if (!$this->methodExcludedByOptions($method, $options)) {
                $results[] = Arr::get($middleware, $name, $name);
            }
        }

        return $results;
    }

    /**
     * Determine if the given options exclude a particular method.
     *
     * @param string $method
     * @param array  $options
     *
     * @return bool
     */
    protected function methodExcludedByOptions($method, array $options)
    {
        return (!empty($options['only']) && !in_array($method, (array) $options['only'])) ||
            (!empty($options['except']) && in_array($method, (array) $options['except']));
    }

    /**
     * Filter the route by URI and / or name.
     *
     * @param array $route
     *
     * @return array|null
     */
    protected function filterRoute(array $route)
    {
        if (($this->option('name') && !Str::contains($route['name'], $this->option('name'))) ||
             $this->option('path') && !Str::contains($route['uri'], $this->option('path')) ||
             $this->option('method') && !Str::contains($route['method'], $this->option('method'))) {
            return;
        }

        return $route;
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['site', null, InputOption::VALUE_OPTIONAL, 'Filter by the current site.'],

            ['column', null, InputOption::VALUE_OPTIONAL, 'Return only these columns.'],

            ['no-table', null, InputOption::VALUE_NONE, 'Return only text.'],

            ['no-empty', null, InputOption::VALUE_NONE, 'Return only non-empty values.'],

            ['organisation', null, InputOption::VALUE_OPTIONAL, 'Filter by the organisation.'],

            ['method', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by method.'],

            ['name', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by name.'],

            ['path', null, InputOption::VALUE_OPTIONAL, 'Filter the routes by path.'],

            ['reverse', 'r', InputOption::VALUE_NONE, 'Reverse the ordering of the routes.'],

            ['sort', null, InputOption::VALUE_OPTIONAL, 'The column (host, method, uri, name, action, middleware) to sort by.', 'uri'],
        ];
    }
}
