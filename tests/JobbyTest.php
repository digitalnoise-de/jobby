<?php

namespace Jobby\Tests;

use Jobby\Exception;
use PHPUnit\Framework\TestCase;
use Jobby\Helper;
use Jobby\Jobby;
use Opis\Closure\SerializableClosure;

/**
 * @coversDefaultClass Jobby\Jobby
 */
class JobbyTest extends TestCase
{
    /**
     * @var string
     */
    private $logFile;

    private Helper $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->logFile = __DIR__ . '/_files/JobbyTest.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->helper = new Helper();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShell(): void
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldShell',
            [
                'command'  => 'php ' . __DIR__ . '/_files/helloworld.php',
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        static::assertEquals('Hello World!', $this->getLogContent());
    }

    /**
     * @return void
     */
    public function testBackgroundProcessIsNotSpawnedIfJobIsNotDueToBeRun(): void
    {
        $hour = date("H", strtotime("+1 hour"));
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldShell',
            [
                'command'  => 'php ' . __DIR__ . '/_files/helloworld.php',
                'schedule' => "* {$hour} * * *",
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        static::assertFalse(file_exists($this->logFile), "Failed to assert that log file doesn't exist and that background process did not spawn");
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testOpisClosure(): void
    {
        $fn = static function (): bool {
            echo 'Another function!';

            return true;
        };

        $jobby = new Jobby();
        $wrapper = new SerializableClosure($fn);
        $serialized = serialize($wrapper);
        $wrapper = unserialize($serialized);
        $closure = $wrapper->getClosure();

        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => $closure,
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        static::assertEquals('Another function!', $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testClosure(): void
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () {
                    echo 'A function!';

                    return true;
                },
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        static::assertEquals('A function!', $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShouldRunAllJobsAdded(): void
    {
        $jobby = new Jobby(['output' => $this->logFile]);
        $jobby->add(
            'job-1',
            [
                'schedule' => '* * * * *',
                'command'  => static function () {
                    echo 'job-1';

                    return true;
                },
            ]
        );
        $jobby->add(
            'job-2',
            [
                'schedule' => '* * * * *',
                'command'  => static function () {
                    echo 'job-2';

                    return true;
                },
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        static::assertStringContainsString('job-1', $this->getLogContent());
        static::assertStringContainsString('job-2', $this->getLogContent());
    }

    /**
     * This is the same test as testClosure but (!) we use the default
     * options to set the output file.
     */
    public function testDefaultOptionsShouldBeMerged(): void
    {
        $jobby = new Jobby(['output' => $this->logFile]);
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () {
                    echo "A function!";

                    return true;
                },
                'schedule' => '* * * * *',
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        static::assertEquals('A function!', $this->getLogContent());
    }

    /**
     * @covers ::getDefaultConfig
     */
    public function testDefaultConfig(): void
    {
        $jobby = new Jobby();
        $config = $jobby->getDefaultConfig();

        static::assertNull($config['recipients']);
        static::assertEquals('sendmail', $config['mailer']);
        static::assertNull($config['runAs']);
        static::assertNull($config['output']);
        static::assertEquals('Y-m-d H:i:s', $config['dateFormat']);
        static::assertTrue($config['enabled']);
        static::assertFalse($config['debug']);
    }

    /**
     * @covers ::setConfig
     * @covers ::getConfig
     */
    public function testSetConfig(): void
    {
        $jobby = new Jobby();
        $oldCfg = $jobby->getConfig();

        $jobby->setConfig(['dateFormat' => 'foo bar']);
        $newCfg = $jobby->getConfig();

        static::assertCount(count($oldCfg), $newCfg);
        static::assertEquals('foo bar', $newCfg['dateFormat']);
    }

    /**
     * @covers ::getJobs
     */
    public function testGetJobs(): void
    {
        $jobby = new Jobby();
        static::assertCount(0, $jobby->getJobs());

        $jobby->add(
            'test job1',
            [
                'command' => 'test',
                'schedule' => '* * * * *'
            ]
        );

        $jobby->add(
            'test job2',
            [
                'command' => 'test',
                'schedule' => '* * * * *'
            ]
        );

        static::assertCount(2, $jobby->getJobs());
    }

    /**
     * @covers ::add
     */
    public function testExceptionOnMissingJobOptionCommand(): void
    {
        $this->expectException(Exception::class);
        $jobby = new Jobby();

        $jobby->add(
            'should fail',
            [
                'schedule' => '* * * * *',
            ]
        );
    }

    /**
     * @covers ::add
     */
    public function testExceptionOnMissingJobOptionSchedule(): void
    {
        $this->expectException(Exception::class);
        $jobby = new Jobby();

        $jobby->add(
            'should fail',
            [
                'command' => static function () {
                },
            ]
        );
    }

    /**
     * @covers ::run
     * @covers ::runWindows
     * @covers ::runUnix
     */
    public function testShouldRunJobsAsync(): void
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => fn() => true,
                'schedule' => '* * * * *',
            ]
        );

        $timeStart = microtime(true);
        $jobby->run();
        $duration = microtime(true) - $timeStart;

        static::assertLessThan(0.5, $duration);
    }

    public function testShouldFailIfMaxRuntimeExceeded(): void
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            static::markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $jobby = new Jobby();
        $jobby->add(
            'slow job',
            [
                'command'    => 'sleep 4',
                'schedule'   => '* * * * *',
                'maxRuntime' => 1,
                'output'     => $this->logFile,
            ]
        );

        $jobby->run();
        sleep(2);
        $jobby->run();
        sleep(2);

        static::assertStringContainsString('ERROR: MaxRuntime of 1 secs exceeded!', $this->getLogContent());
    }

    /**
     * @return string
     */
    private function getLogContent()
    {
        return file_get_contents($this->logFile);
    }

    /**
     * @return int<1, 2>
     */
    private function getSleepTime(): int
    {
        return $this->helper->getPlatform() === Helper::UNIX ? 1 : 2;
    }
}
