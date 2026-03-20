<?php

namespace LaravelRootCause\Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use LaravelRootCause\Data\Evidence;
use LaravelRootCause\Data\Signal;
use LaravelRootCause\Data\TraceEnvelope;
use LaravelRootCause\Diagnostics\CandidateFixGenerator;
use LaravelRootCause\Diagnostics\ConfidenceScorer;
use LaravelRootCause\Diagnostics\RuleEngine;
use LaravelRootCause\Support\ClassFileResolver;
use LaravelRootCause\Tests\Fixtures\Models\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RuleEngineTest extends TestCase
{
    public function test_it_diagnoses_validation_contract_mismatch(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_validation',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'POST',
                'uri' => '/users',
                'route_name' => 'users.store',
            ],
            context: ['request_url' => 'http://localhost/users'],
            response: ['status_code' => 422],
            signals: [
                new Signal(
                    type: 'validation_failed',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'form_request' => 'App\\Http\\Requests\\StoreUserRequest',
                        'failed_fields' => ['email' => ['required']],
                        'input_keys' => ['name'],
                    ],
                    evidence: [
                        new Evidence('validation_rule', [
                            'source' => 'App\\Http\\Requests\\StoreUserRequest',
                            'field' => 'email',
                            'rule' => 'required',
                            'message' => 'The email field is required.',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('validation_contract_mismatch', $diagnosis->rootCauseCategory);
        $this->assertGreaterThan(0.5, $diagnosis->confidence);
    }

    public function test_it_diagnoses_missing_route_binding(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_binding',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/users/999',
                'route_name' => 'users.show',
                'route_parameter_names' => ['user'],
            ],
            context: ['request_url' => 'http://localhost/users/999'],
            response: ['status_code' => 404],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => ModelNotFoundException::class,
                        'message' => 'No query results for model [App\\Models\\User] 999',
                        'model' => 'App\\Models\\User',
                        'ids' => ['999'],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => ModelNotFoundException::class,
                            'message' => 'No query results for model [App\\Models\\User] 999',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('missing_route_binding', $diagnosis->rootCauseCategory);
        $this->assertContains('Verify the route parameters [user] match the controller signature.', $diagnosis->candidateFixes);
    }

    public function test_it_diagnoses_wrapped_route_binding_failures(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_wrapped_binding',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/users/999',
                'route_name' => 'users.show',
                'route_parameter_names' => ['user'],
            ],
            context: ['request_url' => 'http://localhost/users/999'],
            response: ['status_code' => 404],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => NotFoundHttpException::class,
                        'message' => 'Not found.',
                        'model' => 'App\\Models\\User',
                        'ids' => ['999'],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => NotFoundHttpException::class,
                            'message' => 'Not found.',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('missing_route_binding', $diagnosis->rootCauseCategory);
    }

    public function test_it_diagnoses_route_binding_when_the_controller_parameter_is_model_typed(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_reflected_binding',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/accounts/999',
                'route_name' => 'accounts.show',
                'controller' => RouteBindingRuleTestController::class.'@show',
                'route_parameter_names' => ['account'],
            ],
            context: ['request_url' => 'http://localhost/accounts/999'],
            response: ['status_code' => 404],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => ModelNotFoundException::class,
                        'message' => 'No query results for model [LaravelRootCause\\Tests\\Fixtures\\Models\\User] 999',
                        'model' => User::class,
                        'ids' => ['999'],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => ModelNotFoundException::class,
                            'message' => 'No query results for model [LaravelRootCause\\Tests\\Fixtures\\Models\\User] 999',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('missing_route_binding', $diagnosis->rootCauseCategory);
        $this->assertContains('Verify the route parameters [account] match the controller signature.', $diagnosis->candidateFixes);
    }

    public function test_it_diagnoses_route_binding_for_invokable_controllers(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_invokable_binding',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/accounts/999',
                'route_name' => 'accounts.show',
                'controller' => InvokableRouteBindingRuleTestController::class,
                'route_parameter_names' => ['account'],
            ],
            context: ['request_url' => 'http://localhost/accounts/999'],
            response: ['status_code' => 404],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => ModelNotFoundException::class,
                        'message' => 'No query results for model [LaravelRootCause\\Tests\\Fixtures\\Models\\User] 999',
                        'model' => User::class,
                        'ids' => ['999'],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => ModelNotFoundException::class,
                            'message' => 'No query results for model [LaravelRootCause\\Tests\\Fixtures\\Models\\User] 999',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('missing_route_binding', $diagnosis->rootCauseCategory);
        $this->assertContains('RuleEngineTest.php', array_map('basename', $diagnosis->affectedFiles));
    }

    public function test_it_does_not_blame_route_binding_for_manual_model_lookups_inside_the_action(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_manual_lookup',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/users/999',
                'route_name' => 'users.show',
                'controller' => ManualLookupRuleTestController::class.'@show',
                'route_parameter_names' => ['user'],
            ],
            context: ['request_url' => 'http://localhost/users/999'],
            response: ['status_code' => 404],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => ModelNotFoundException::class,
                        'message' => 'No query results for model [LaravelRootCause\\Tests\\Fixtures\\Models\\User] 999',
                        'model' => User::class,
                        'ids' => ['999'],
                        'application_frames' => [],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => ModelNotFoundException::class,
                            'message' => 'No query results for model [LaravelRootCause\\Tests\\Fixtures\\Models\\User] 999',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('unhandled_exception', $diagnosis->rootCauseCategory);
    }

    public function test_it_diagnoses_query_pathology(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_n_plus_one',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/posts',
            ],
            context: ['request_url' => 'http://localhost/posts'],
            response: ['status_code' => 200],
            signals: [
                new Signal(
                    type: 'query_pathology_suspected',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'classification' => 'n_plus_one_suspected',
                        'fingerprint' => 'select * from "comments" where "post_id" = ?',
                        'count' => 4,
                        'worst_offender_frame' => [
                            'file' => '/app/Http/Controllers/PostController.php',
                            'line' => 44,
                        ],
                    ],
                    evidence: [
                        new Evidence('query_pathology', [
                            'fingerprint' => 'select * from "comments" where "post_id" = ?',
                            'count' => 4,
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('n_plus_one_suspected', $diagnosis->rootCauseCategory);
        $this->assertContains('/app/Http/Controllers/PostController.php', $diagnosis->affectedFiles);
    }

    public function test_it_diagnoses_unhandled_exceptions(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_exception',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/explode',
            ],
            context: ['request_url' => 'http://localhost/explode'],
            response: ['status_code' => 500],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => \RuntimeException::class,
                        'message' => 'Boom from route',
                        'application_frames' => [
                            [
                                'file' => '/app/Http/Controllers/BoomController.php',
                                'line' => 18,
                                'class' => 'App\\Http\\Controllers\\BoomController',
                                'function' => '__invoke',
                            ],
                        ],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => \RuntimeException::class,
                            'message' => 'Boom from route',
                        ]),
                        new Evidence('query_summary', [
                            'fingerprint' => 'select * from "users" where "id" = ?',
                            'count' => 3,
                            'duration_ms' => 10.2,
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('unhandled_exception', $diagnosis->rootCauseCategory);
        $this->assertContains('/app/Http/Controllers/BoomController.php', $diagnosis->affectedFiles);
        $this->assertGreaterThan(0.5, $diagnosis->confidence);
    }

    public function test_it_does_not_classify_plain_not_found_http_exceptions_as_route_binding_failures(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_plain_404',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: [
                'method' => 'GET',
                'uri' => '/missing',
            ],
            context: ['request_url' => 'http://localhost/missing'],
            response: ['status_code' => 404],
            signals: [
                new Signal(
                    type: 'exception_thrown',
                    capturedAt: '2026-03-13T14:00:01+09:00',
                    payload: [
                        'exception_class' => NotFoundHttpException::class,
                        'message' => 'Not found.',
                        'application_frames' => [],
                    ],
                    evidence: [
                        new Evidence('exception', [
                            'class' => NotFoundHttpException::class,
                            'message' => 'Not found.',
                        ]),
                    ],
                ),
            ],
        );

        $diagnosis = $engine->diagnose($trace);

        $this->assertNotNull($diagnosis);
        $this->assertSame('unhandled_exception', $diagnosis->rootCauseCategory);
    }

    public function test_it_returns_null_when_no_rule_matches(): void
    {
        $engine = new RuleEngine(
            new ConfidenceScorer,
            new CandidateFixGenerator,
            new ClassFileResolver,
        );

        $trace = new TraceEnvelope(
            traceId: 'trc_none',
            kind: 'http_request',
            startedAt: '2026-03-13T14:00:01+09:00',
            endedAt: '2026-03-13T14:00:02+09:00',
            environment: 'testing',
            app: ['php_version' => '8.3', 'laravel_version' => '12.x'],
            entrypoint: ['method' => 'GET', 'uri' => '/health'],
            context: ['request_url' => 'http://localhost/health'],
            response: ['status_code' => 200],
        );

        $this->assertNull($engine->diagnose($trace));
    }
}

class RouteBindingRuleTestController
{
    public function show(User $account): void {}
}

class ManualLookupRuleTestController
{
    public function show(string $user): void {}
}

class InvokableRouteBindingRuleTestController
{
    public function __invoke(User $account): void {}
}
