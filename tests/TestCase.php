<?php

namespace LaravelRootCause\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LaravelRootCause\Collectors\RequestCollector;
use LaravelRootCause\RootCauseServiceProvider;
use LaravelRootCause\Tests\Fixtures\Models\NPlusOneProbe;
use LaravelRootCause\Tests\Fixtures\Models\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RootCauseServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $config = $app->make(ConfigRepository::class);

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $config->set('root_cause.enabled', true);
        $config->set('root_cause.storage.path', sys_get_temp_dir().'/laravel-root-cause-tests/'.uniqid('', true));
        $config->set('root_cause.collectors.query.duplicate_threshold', 3);
        $config->set('root_cause.collectors.query.n_plus_one_threshold', 3);
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $this->configureRoute($router->post('/users', function (Request $request) {
            Validator::make($request->all(), [
                'email' => ['required', 'email'],
            ])->validate();

            return response()->json(['ok' => true]);
        }));

        $this->configureRoute($router->get('/duplicate-query', function () {
            DB::select('select name from sqlite_master');
            DB::select('select name from sqlite_master');
            DB::select('select name from sqlite_master');

            return response()->json(['ok' => true]);
        }));

        $this->configureRoute($router->post('/response-validation', function () {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'email' => ['The email field must be a valid email address.'],
                ],
            ], 422);
        }), 'validation.response');

        $this->configureRoute($router->post('/response-validation-translated', function () {
            return response()->json([
                'message' => 'The submitted data is invalid.',
                'errors' => [
                    'email' => ['The email field must be a valid email address.'],
                ],
            ], 422);
        }), 'validation.response.translated');

        $this->configureRoute($router->post('/response-validation-framework-diff', function (Request $request) {
            return response()->json([
                'message' => 'The team rejected this payload.',
                'errors' => [
                    'email' => ['The email field must be a valid email address.'],
                ],
                'meta' => [
                    'framework_version' => '12.x',
                    'submitted' => $request->all(),
                ],
            ], 422);
        }), 'validation.response.framework-diff');

        $this->configureRoute($router->post('/response-validation-custom-renderer', function (Request $request) {
            return response()->json([
                'error' => [
                    'title' => 'Validation failed',
                    'detail' => 'Custom renderer wrapped the error payload.',
                ],
                'message' => 'Request could not be processed.',
                'errors' => [
                    'email' => ['The email field must be a valid email address.'],
                ],
                'input' => $request->all(),
            ], 422);
        }), 'validation.response.custom-renderer');

        $this->configureRoute($router->post('/response-validation-custom-message', function () {
            return response()->json([
                'message' => 'Request validation failed.',
                'errors' => [
                    'email' => ['The email field must be a valid email address.'],
                ],
            ], 422);
        }), 'validation.response.custom-message');

        $this->configureRoute($router->post('/domain-conflict', function () {
            return response()->json([
                'message' => 'This order cannot be shipped.',
                'errors' => [
                    'order' => ['The order is already archived.'],
                ],
            ], 422);
        }), 'orders.conflict');

        $this->configureRoute($router->get('/users/{user}', function (string $user) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user]);
        }), 'users.show');

        $this->configureRoute($router->get('/explode', function () {
            DB::select('select name from sqlite_master');

            throw new \RuntimeException('Boom from route');
        }), 'explode');

        $this->configureRoute($router->get('/handled-report', function () {
            try {
                throw new \RuntimeException('Handled but reported');
            } catch (\RuntimeException $exception) {
                app(ExceptionHandler::class)->report($exception);

                return response()->json(['ok' => true]);
            }
        }), 'handled-report');

        $this->configureRoute($router->get('/n-plus-one', function () {
            (new NPlusOneProbe)->runQueries();

            return response()->json(['ok' => true]);
        }), 'n-plus-one');
    }

    /**
     * @param  Route|array<int, Route>  $route
     */
    protected function configureRoute(Route|array $route, ?string $name = null): void
    {
        foreach (is_array($route) ? $route : [$route] as $registeredRoute) {
            $registeredRoute->middleware(RequestCollector::class);

            if ($name !== null) {
                $registeredRoute->name($name);
            }
        }
    }
}
