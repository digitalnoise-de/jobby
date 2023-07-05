<?php

namespace Jobby\Tests;

use PHPUnit\Framework\TestCase;
use Jobby\Helper;
use Jobby\Jobby;
use Opis\Closure\SerializableClosure;

/**
 * @coversDefaultClass Jobby\Jobby
 */
class JobbyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    private $logFile;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
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
    protected function tearDown()
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShell()
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
    public function testBackgroundProcessIsNotSpawnedIfJobIsNotDueToBeRun()
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
    public function testOpisClosure()
    {
        $fn = static function () {
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
    public function testClosure()
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
    public function testShouldRunAllJobsAdded()
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

        static::assertContains('job-1', $this->getLogContent());
        static::assertContains('job-2', $this->getLogContent());
    }

    /**
     * This is the same test as testClosure but (!) we use the default
     * options to set the output file.
     */
    public function testDefaultOptionsShouldBeMerged()
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
    public function testDefaultConfig()
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
    public function testSetConfig()
    {
        $jobby = new Jobby();
        $oldCfg = $jobby->getConfig();

        $jobby->setConfig(['dateFormat' => 'foo bar']);
        $newCfg = $jobby->getConfig();

        static::assertEquals(count($oldCfg), count($newCfg));
        static::assertEquals('foo bar', $newCfg['dateFormat']);
    }

    /**
     * @covers ::getJobs
     */
    public function testGetJobs()
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
     * @expectedException \Jobby\Exception
     */
    public function testExceptionOnMissingJobOptionCommand()
    {
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
     * @expectedException \Jobby\Exception
     */
    public function testExceptionOnMissingJobOptionSchedule()
    {
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
    public function testShouldRunJobsAsync()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => function () {
                    return true;
                },
                'schedule' => '* * * * *',
            ]
        );

        $timeStart = microtime(true);
        $jobby->run();
        $duration = microtime(true) - $timeStart;

        static::assertLessThan(0.5, $duration);
    }

    public function testShouldFailIfMaxRuntimeExceeded()
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

        static::assertContains('ERROR: MaxRuntime of 1 secs exceeded!', $this->getLogContent());
    }

    /**
     * @return string
     */
    private function getLogContent()
    {
        return file_get_contents($this->logFile);
    }

    private function getSleepTime()
    {
        return $this->helper->getPlatform() === Helper::UNIX ? 1 : 2;
    }
}
