<?php

declare(strict_types=1);

namespace example\libgreeting;

/**
 * A tiny virion, to show that Golem loads the virions declared in .poggit.yml.
 */
final class Greeting
{
    public static function welcome(string $name): string
    {
        return "Welcome, $name!";
    }
}
