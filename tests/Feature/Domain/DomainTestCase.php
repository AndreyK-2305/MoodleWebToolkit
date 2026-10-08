<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class DomainTestCase extends TestCase
{
    use DomainFixtures;
    use RefreshDatabase;
}
