<?php

namespace Jobby\Tests;

use PHPUnit\Framework\TestCase;
use Jobby\Exception;

/**
 * @covers Jobby\Exception
 */
class ExceptionTest extends TestCase
{
    public function testInheritsBaseException()
    {
        $e = new Exception();
        static::assertTrue($e instanceof \Exception);
    }
}
