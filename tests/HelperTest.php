<?php
declare(strict_types=1);

namespace Jobby\Tests;

use Jobby\Exception;
use Jobby\Helper;
use Jobby\InfoException;
use Jobby\Jobby;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Jobby\Helper
 */
class HelperTest extends TestCase
{
    private Helper $helper;

    private string $tmpDir;

    private string $lockFile;

    private string $copyOfLockFile;

    protected function setUp(): void
    {
        $this->helper         = new Helper();
        $this->tmpDir         = $this->helper->getTempDir();
        $this->lockFile       = $this->tmpDir . '/test.lock';
        $this->copyOfLockFile = $this->tmpDir . '/test.lock.copy';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APPLICATION_ENV']);
    }

    /**
     * @dataProvider dataProviderTestEscape
     *
     * @test
     */
    public function escape(string $input, string $expected): void
    {
        $actual = $this->helper->escape($input);
        static::assertEquals($expected, $actual);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function dataProviderTestEscape(): array
    {
        return [
            ['lower', 'lower'],
            ['UPPER', 'upper'],
            ['0123456789', '0123456789'],
            ['with    spaces', 'with_spaces'],
            ['invalid!@#$%^&*()chars', 'invalidchars'],
            ['._-', '._-'],
        ];
    }

    /**
     * @covers ::getPlatform
     *
     * @test
     */
    public function getPlatform(): void
    {
        $actual = $this->helper->getPlatform();
        static::assertContains($actual, [Helper::UNIX, Helper::WINDOWS]);
    }

    /**
     * @covers ::getPlatform
     *
     * @test
     */
    public function platformConstants(): void
    {
        static::assertNotEquals(Helper::UNIX, Helper::WINDOWS);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     *
     * @doesNotPerformAssertions
     *
     * @test
     */
    public function aquireAndReleaseLock(): void
    {
        $this->helper->acquireLock($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     * @covers ::releaseLock
     *
     * @test
     */
    public function lockFileShouldContainCurrentPid(): void
    {
        $this->helper->acquireLock($this->lockFile);

        // on Windows, file locking is mandatory not advisory, so you can't do file_get_contents on a locked file
        // therefore, we need to make a copy of the lock file in order to read its contents
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            copy($this->lockFile, $this->copyOfLockFile);
            $lockFile = $this->copyOfLockFile;
        } else {
            $lockFile = $this->lockFile;
        }

        static::assertEquals(getmypid(), file_get_contents($lockFile));

        $this->helper->releaseLock($this->lockFile);
        static::assertEmpty(file_get_contents($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     *
     * @test
     */
    public function lockLifetimeShouldBeZeroIfFileDoesNotExists(): void
    {
        unlink($this->lockFile);
        static::assertFalse(file_exists($this->lockFile));
        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     *
     * @test
     */
    public function lockLifetimeShouldBeZeroIfFileIsEmpty(): void
    {
        file_put_contents($this->lockFile, '');
        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     *
     * @test
     */
    public function lockLifetimeShouldBeZeroIfItContainsAInvalidPid(): void
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            static::markTestSkipped('Test relies on posix_ functions');
        }

        file_put_contents($this->lockFile, 'invalid-pid');
        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
    }

    /**
     * @covers ::getLockLifetime
     *
     * @test
     */
    public function getLocklifetime(): void
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            static::markTestSkipped('Test relies on posix_ functions');
        }

        $this->helper->acquireLock($this->lockFile);

        static::assertEquals(0, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        static::assertEquals(1, $this->helper->getLockLifetime($this->lockFile));
        sleep(1);
        static::assertEquals(2, $this->helper->getLockLifetime($this->lockFile));

        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::releaseLock
     *
     * @test
     */
    public function releaseNonExistin(): void
    {
        $this->expectException(Exception::class);
        $this->helper->releaseLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     *
     * @test
     */
    public function exceptionIfAquireFails(): void
    {
        $this->expectException(InfoException::class);
        $fh = fopen($this->lockFile, 'r+');
        static::assertTrue(is_resource($fh));

        $res = flock($fh, LOCK_EX | LOCK_NB);
        static::assertTrue($res);

        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::acquireLock
     *
     * @test
     */
    public function aquireLockShouldFailOnSecondTry(): void
    {
        $this->expectException(Exception::class);
        $this->helper->acquireLock($this->lockFile);
        $this->helper->acquireLock($this->lockFile);
    }

    /**
     * @covers ::getTempDir
     *
     * @test
     */
    public function getTempDir(): void
    {
        $valid = [sys_get_temp_dir(), getcwd()];
        foreach (['TMP', 'TEMP', 'TMPDIR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $valid[] = $_SERVER[$key];
            }
        }

        $actual = $this->helper->getTempDir();
        static::assertContains($actual, $valid);
    }

    /**
     * @covers ::getApplicationEnv
     *
     * @test
     */
    public function getApplicationEnv(): void
    {
        $_SERVER['APPLICATION_ENV'] = 'foo';

        $actual = $this->helper->getApplicationEnv();
        static::assertEquals('foo', $actual);
    }

    /**
     * @covers ::getApplicationEnv
     *
     * @test
     */
    public function getApplicationEnvShouldBeNullIfUndefined(): void
    {
        $actual = $this->helper->getApplicationEnv();
        static::assertNull($actual);
    }

    /**
     * @covers ::getHost
     *
     * @test
     */
    public function getHostname(): void
    {
        $actual = $this->helper->getHost();
        static::assertContains($actual, [gethostname(), php_uname('n')]);
    }

    /**
     * @covers ::sendMail
     * @covers ::getCurrentMailer
     *
     * @test
     */
    public function sendMail(): void
    {
        $mailer = $this->getSwiftMailerMock();
        $mailer->expects(static::once())
            ->method('send')
        ;

        $jobby                = new Jobby();
        $config               = $jobby->getDefaultConfig();
        $config['output']     = 'output message';
        $config['recipients'] = 'a@a.com,b@b.com';

        $helper = new Helper($mailer);
        $mail   = $helper->sendMail('job', $config, 'message');

        $host  = $helper->getHost();
        $email = "jobby@$host";
        static::assertStringContainsString('job', $mail->getSubject());
        static::assertStringContainsString("[$host]", $mail->getSubject());
        static::assertEquals(1, is_countable($mail->getFrom()) ? count($mail->getFrom()) : 0);
        static::assertEquals('jobby', current($mail->getFrom()));
        static::assertEquals($email, current(array_keys($mail->getFrom())));
        static::assertEquals($email, current(array_keys($mail->getSender())));
        static::assertStringContainsString($config['output'], $mail->getBody());
        static::assertStringContainsString('message', $mail->getBody());
    }

    /**
     * @return MockObject&\Swift_Mailer
     */
    private function getSwiftMailerMock()
    {
        return $this->getMockBuilder(\Swift_Mailer::class)
            ->setConstructorArgs([new \Swift_NullTransport()])
            ->getMock();
    }

    /**
     * @test
     */
    public function itReturnsTheCorrectNullSystemDeviceForUnix(): void
    {
        $helper = $this->createPartialMock(Helper::class, ['getPlatform']);
        $helper->expects(static::once())
            ->method('getPlatform')
            ->willReturn(Helper::UNIX);

        static::assertEquals('/dev/null', $helper->getSystemNullDevice());
    }

    /**
     * @test
     */
    public function itReturnsTheCorrectNullSystemDeviceForWindows(): void
    {
        $helper = $this->createPartialMock(Helper::class, ['getPlatform']);
        $helper->expects(static::once())
               ->method('getPlatform')
               ->willReturn(Helper::WINDOWS);

        static::assertEquals('NUL', $helper->getSystemNullDevice());
    }
}
