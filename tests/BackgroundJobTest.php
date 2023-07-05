<?php

namespace Jobby\Tests;

use PHPUnit\Framework\TestCase;
use Jobby\BackgroundJob;
use Jobby\Helper;
use Opis\Closure\SerializableClosure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @coversDefaultClass Jobby\BackgroundJob
 */
class BackgroundJobTest extends TestCase
{
    public const JOB_NAME = 'name';

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
        $this->logFile = __DIR__ . '/_files/BackgroundJobTest.log';
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
     * @return array<string, array{0: array<string, mixed>, 1: string|int}>
     */
    public function runProvider(): array
    {
        $echo = static function (): bool {
            echo 'test';

            return true;
        };
        $uid = static function (): bool {
            echo getmyuid();

            return true;
        };
        $job = ['closure' => $echo];

        return [
            'diabled, not run'       => [$job + ['enabled' => false], ''],
            'normal job, run'        => [$job, 'test'],
            'wrong host, not run'    => [$job + ['runOnHost' => 'something that does not match'], ''],
            'current user, run,'     => [['closure' => $uid], getmyuid()],
        ];
    }

    /**
     * @covers ::getConfig
     */
    public function testGetConfig(): void
    {
        $job = new BackgroundJob('test job',[]);
        static::assertIsArray($job->getConfig());
    }

    /**
     * @dataProvider runProvider
     *
     * @covers ::run
     *
     * @param array<string, mixed> $config
     * @param string|int           $expectedOutput
     */
    public function testRun(array $config, $expectedOutput): void
    {
        $this->runJob($config);

        static::assertEquals($expectedOutput, $this->getLogContent());
    }

    /**
     * @covers ::runFile
     */
    public function testInvalidCommand(): void
    {
        $this->runJob(['command' => 'invalid-command']);

        static::assertStringContainsString('invalid-command', (string)$this->getLogContent());

        if ($this->helper->getPlatform() === Helper::UNIX) {
            static::assertStringContainsString('not found', (string)$this->getLogContent());
            static::assertStringContainsString("ERROR: Job exited with status '127'", $this->getLogContent());
        } else {
            static::assertStringContainsString('not recognized as an internal or external command', (string)$this->getLogContent());
        }
    }

    /**
     * @covers ::runFunction
     */
    public function testClosureNotReturnTrue(): void
    {
        $this->runJob(
            [
                'closure' => fn() => false,
            ]
        );

        static::assertStringContainsString('ERROR: Closure did not return true! Returned:', (string)$this->getLogContent());
    }

    /**
     * @covers ::getLogFile
     */
    public function testHideStdOutByDefault(): void
    {
        ob_start();
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => null,
            ]
        );
        $content = ob_get_contents();
        ob_end_clean();

        static::assertEmpty($content);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldCreateLogFolder(): void
    {
        $logfile = dirname($this->logFile) . '/foo/bar.log';
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => $logfile,
            ]
        );

        $dirExists = file_exists(dirname($logfile));
        $isDir = is_dir(dirname($logfile));

        unlink($logfile);
        rmdir(dirname($logfile));

        static::assertTrue($dirExists);
        static::assertTrue($isDir);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldSplitStderrAndStdout(): void
    {
        $dirname = dirname($this->logFile);
        $stdout = $dirname . '/stdout.log';
        $stderr = $dirname . '/stderr.log';
        $this->runJob(
            [
                'command' => "(echo \"stdout output\" && (>&2 echo \"stderr output\"))",
                'output_stdout' => $stdout,
                'output_stderr' => $stderr,
            ]
        );

        static::assertStringContainsString('stdout output', @file_get_contents($stdout));
        static::assertStringContainsString('stderr output', @file_get_contents($stderr));

        unlink($stderr);
        unlink($stdout);

    }

    /**
     * @covers ::mail
     */
    public function testNotSendMailOnMissingRecipients(): void
    {
        $helper = $this->createMock(Helper::class);
        $helper->expects(static::never())
            ->method('sendMail')
        ;

        $this->runJob(
            [
                'closure'    => fn() => false,
                'recipients' => '',
            ],
            $helper
        );
    }

    /**
     * @covers ::mail
     */
    public function testMailShouldTriggerHelper(): void
    {
        $helper = $this->createPartialMock(Helper::class, ['sendMail']);
        $helper->expects(static::once())
            ->method('sendMail')
        ;

        $this->runJob(
            [
                'closure'    => fn() => false,
                'recipients' => 'test@example.com',
            ],
            $helper
        );
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntime(): void
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            static::markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createPartialMock(Helper::class, ['getLockLifetime']);
        $helper->expects(static::once())
            ->method('getLockLifetime')
            ->will(static::returnValue(0))
        ;

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        static::assertEmpty($this->getLogContent());
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntimeShouldFailIsExceeded(): void
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            static::markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createPartialMock(Helper::class, ['getLockLifetime']);
        $helper->expects(static::once())
            ->method('getLockLifetime')
            ->will(static::returnValue(2))
        ;

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        static::assertStringContainsString('MaxRuntime of 1 secs exceeded! Current runtime: 2 secs', (string)$this->getLogContent());
    }

    /**
     * @dataProvider haltDirProvider
     * @covers       ::shouldRun
     *
     * @param bool $createFile
     * @param bool $jobRuns
     */
    public function testHaltDir(bool $createFile, bool $jobRuns): void
    {
        $dir = __DIR__ . '/_files';
        $file = $dir . '/' . static::JOB_NAME;

        $fs = new Filesystem();

        if ($createFile) {
            $fs->touch($file);
        }

        $this->runJob(
            [
                'haltDir' => $dir,
                'closure' => function () {
                    echo 'test';

                    return true;
                },
            ]
        );

        if ($createFile) {
            $fs->remove($file);
        }

        $content = $this->getLogContent();
        static::assertEquals($jobRuns, is_string($content) && !empty($content));
    }

    /**
     * @return list<array{0: bool, 1: bool}>
     */
    public function haltDirProvider(): array
    {
        return [
            [true, false],
            [false, true],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runJob(array $config, Helper $helper = null): void
    {
        $config = $this->getJobConfig($config);

        $job = new BackgroundJob(self::JOB_NAME, $config, $helper);
        $job->run();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function getJobConfig(array $config): array
    {
        $helper = new Helper();

        if (isset($config['closure'])) {
            $wrapper = new SerializableClosure($config['closure']);
            $config['closure'] = serialize($wrapper);
        }

        return array_merge(
            [
                'enabled'    => 1,
                'haltDir'    => null,
                'runOnHost'  => $helper->getHost(),
                'dateFormat' => 'Y-m-d H:i:s',
                'schedule'   => '* * * * *',
                'output'     => $this->logFile,
                'maxRuntime' => null,
                'runAs'      => null,
            ],
            $config
        );
    }

    /**
     * @return string|false
     */
    private function getLogContent()
    {
        return @file_get_contents($this->logFile);
    }
}
