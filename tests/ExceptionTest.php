<?php

namespace Jobby\Tests;

use PHPUnit_Framework_TestCase;
use Jobby\Exception;

/**
 * @covers Jobby\Exception
 */
class ExceptionTest extends PHPUnit_Framework_TestCase
{
    public function testInheritsBaseException()
    {
        $e = new Exception();
        static::assertTrue($e instanceof \Exception);
    }
}
