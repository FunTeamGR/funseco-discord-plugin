<?php

namespace Funseco\Discord\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Discord\DiscordServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            DiscordServiceProvider::class,
        ];
    }
}
