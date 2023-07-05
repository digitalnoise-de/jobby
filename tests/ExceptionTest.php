<?php
declare(strict_types=1);

namespace Jobby\Tests;

use Jobby\Exception;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Jobby\Exception
 */
class ExceptionTest extends TestCase
{
    /**
     * @test
     */
    public function inheritsBaseException(): void
    {
        $e = new Exception();
        static::assertTrue($e instanceof \Exception);
    }
}
