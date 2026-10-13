<?php
/**
 * Campaign Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\campaignmanager\tests\Stubs;

use craft\console\Application as ConsoleApplication;
use InvalidArgumentException;
use ReflectionObject;
use ReflectionProperty;

/**
 * Application that answers for the running console application but hands
 * out an in-memory session, which the console application refuses to do.
 *
 * It is installed as `Craft::$app` only around a controller action and put
 * back immediately afterwards.
 *
 * A test can also place components of its own in front of the real
 * application's with {@see withLocalComponents()}. They answer `get()`,
 * `has()` and `getComponents()` only while the work runs and live in this
 * proxy alone; the real application's service locator is never written for
 * them, so its definitions and loaded instances stay exactly as they were.
 *
 * @since 5.16.0
 */
final class SessionApplication extends ConsoleApplication
{
    /** @var array<string, object> */
    private array $localComponents = [];

    public function __construct(
        private readonly ConsoleApplication $application,
        private readonly InMemorySession $session,
    ) {
        // The real application's constructor is not run again; its public
        // state (edition, id, language, parameters, ...) is mirrored instead.
        foreach ((new ReflectionObject($application))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && $property->isInitialized($application)) {
                $this->{$property->getName()} = $property->getValue($application);
            }
        }
    }

    public function getSession(): InMemorySession
    {
        return $this->session;
    }

    /**
     * Run the work with the given components answering for their IDs in
     * place of the real application's, then drop them again, whether the
     * work returned or threw. Nested calls restore the overlay the outer
     * call had.
     *
     * @template T
     * @param array<string, object> $components
     * @param callable(): T $work
     * @return T
     */
    public function withLocalComponents(array $components, callable $work): mixed
    {
        $previous = $this->localComponents;
        foreach ($components as $id => $component) {
            if ($id === 'session') {
                throw new InvalidArgumentException('The session is always this application\'s in-memory session.');
            }
            $this->localComponents[$id] = $component;
        }

        try {
            return $work();
        } finally {
            $this->localComponents = $previous;
        }
    }

    /**
     * The IDs of the components currently answering in place of the real
     * application's; empty outside {@see withLocalComponents()}.
     *
     * @return list<string>
     */
    public function getLocalComponentIds(): array
    {
        return array_keys($this->localComponents);
    }

    public function get($id, $throwException = true): ?object
    {
        if ($id === 'session') {
            return $this->session;
        }

        return $this->localComponents[$id] ?? $this->application->get($id, $throwException);
    }

    public function has($id, $checkInstance = false): bool
    {
        return $id === 'session' || isset($this->localComponents[$id]) || $this->application->has($id, $checkInstance);
    }

    public function set($id, $definition): void
    {
        $this->application->set($id, $definition);
    }

    public function getComponents($returnDefinitions = true): array
    {
        $components = $this->application->getComponents($returnDefinitions);
        $components['session'] = $returnDefinitions ? InMemorySession::class : $this->session;

        // A local component is its own definition, as an object installed with set() would be
        return $this->localComponents + $components;
    }

    public function clear($id): void
    {
        $this->application->clear($id);
    }

    public function getIsInitialized(): bool
    {
        return $this->application->getIsInitialized();
    }

    public function onInit(callable $callback): void
    {
        $this->application->onInit($callback);
    }
}
