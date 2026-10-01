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
use ReflectionObject;
use ReflectionProperty;

/**
 * Application that answers for the running console application but hands
 * out an in-memory session, which the console application refuses to do.
 *
 * It is installed as `Craft::$app` only around a controller action and put
 * back immediately afterwards.
 *
 * @since 5.16.0
 */
final class SessionApplication extends ConsoleApplication
{
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

    public function get($id, $throwException = true): ?object
    {
        if ($id === 'session') {
            return $this->session;
        }

        return $this->application->get($id, $throwException);
    }

    public function has($id, $checkInstance = false): bool
    {
        return $id === 'session' || $this->application->has($id, $checkInstance);
    }

    public function set($id, $definition): void
    {
        $this->application->set($id, $definition);
    }

    public function getComponents($returnDefinitions = true): array
    {
        $components = $this->application->getComponents($returnDefinitions);
        $components['session'] = $returnDefinitions ? InMemorySession::class : $this->session;

        return $components;
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
