<?php

namespace Nesk\Rialto\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nesk\Rialto\Data\BasicResource;
use Nesk\Rialto\Data\JsFunction;
use Nesk\Rialto\Exceptions\Node;
use Nesk\Rialto\Tests\Implementation\FsWithoutProcessDelegation;
use Nesk\Rialto\Tests\Implementation\FsWithProcessDelegation;
use Nesk\Rialto\Tests\Implementation\Resources\Stats;
use Symfony\Component\Process\Process;

class ImplementationTest extends TestCase
{
    private const JS_FUNCTION_CREATE_DEPRECATION_PATTERN = '/^Nesk\\\\Rialto\\\\Data\\\\JsFunction::create\(\)/';

    public function setUp(): void
    {
        parent::setUp();

        $this->dirPath = \realpath(__DIR__ . '/resources');
        $this->filePath = "{$this->dirPath}/file";

        $this->fs = $this->canPopulateProperty('fs') ? new FsWithProcessDelegation() : null;
    }

    public function tearDown(): void
    {
        $this->fs = null;
    }

    public function testCanCallMethodAndGetItsReturnValue()
    {
        $content = $this->fs->readFileSync($this->filePath, 'utf8');

        $this->assertEquals('Hello world!', $content);
    }

    public function testCanGetProperty()
    {
        $constants = $this->fs->constants;

        $this->assertIsArray($constants);
    }

    public function testCanSetProperty()
    {
        $this->fs->foo = 'bar';
        $this->assertEquals('bar', $this->fs->foo);

        $this->fs->foo = null;
        $this->assertNull($this->fs->foo);
    }

    public function testCanReturnBasicResources()
    {
        $resource = $this->fs->readFileSync($this->filePath);

        $this->assertInstanceOf(BasicResource::class, $resource);
    }

    public function testCanReturnSpecificResources()
    {
        $resource = $this->fs->statSync($this->filePath);

        $this->assertInstanceOf(Stats::class, $resource);
    }

    public function testCanCastResourcesToString()
    {
        $resource = $this->fs->statSync($this->filePath);

        $this->assertEquals('[object Object]', (string) $resource);
    }

    /**
     * @test
     * @dontPopulateProperties fs
     */
    public function testCanOmitProcessDelegation()
    {
        $this->fs = new FsWithoutProcessDelegation();

        $resource = $this->fs->statSync($this->filePath);

        $this->assertInstanceOf(BasicResource::class, $resource);
        $this->assertNotInstanceOf(Stats::class, $resource);
    }

    public function testCanUseNestedResources()
    {
        $resources = $this->fs->multipleStatSync($this->dirPath, $this->filePath);

        $this->assertCount(2, $resources);
        $this->assertContainsOnlyInstancesOf(Stats::class, $resources);

        $isFile = $this->fs->multipleResourcesIsFile($resources);

        $this->assertFalse($isFile[0]);
        $this->assertTrue($isFile[1]);
    }

    public function testCanUseMultipleResourcesWithoutConfusion()
    {
        $dirStats = $this->fs->statSync($this->dirPath);
        $fileStats = $this->fs->statSync($this->filePath);

        $this->assertInstanceOf(Stats::class, $dirStats);
        $this->assertInstanceOf(Stats::class, $fileStats);

        $this->assertTrue($dirStats->isDirectory());
        $this->assertTrue($fileStats->isFile());
    }

    public function testCanReturnMultipleTimesTheSameResource()
    {
        $stats1 = $this->fs->Stats;
        $stats2 = $this->fs->Stats;

        $this->assertEquals($stats1, $stats2);
    }

    /**
     * @group js-functions
     */
    public function testCanUseJsFunctionsWithABody()
    {
        $functions = [
            $this->ignoreUserDeprecation(self::JS_FUNCTION_CREATE_DEPRECATION_PATTERN, function () {
                // @phpstan-ignore-next-line
                return JsFunction::create("return 'Simple callback';");
            }),
            JsFunction::createWithBody("return 'Simple callback';"),
        ];

        foreach ($functions as $function) {
            $value = $this->fs->runCallback($function);
            $this->assertEquals('Simple callback', $value);
        }
    }

    /**
     * @group js-functions
     */
    public function testCanUseJsFunctionsWithParameters()
    {
        $functions = [
            $this->ignoreUserDeprecation(self::JS_FUNCTION_CREATE_DEPRECATION_PATTERN, function () {
                // @phpstan-ignore-next-line
                return JsFunction::create(['fs'], "
                    return 'Callback using arguments: ' + fs.constructor.name;
                ");
            }),
            JsFunction::createWithParameters(['fs'])
                ->body("return 'Callback using arguments: ' + fs.constructor.name;"),
        ];

        foreach ($functions as $function) {
            $value = $this->fs->runCallback($function);
            $this->assertEquals('Callback using arguments: Object', $value);
        }
    }

    /**
     * @group js-functions
     */
    public function testCanUseJsFunctionsWithScope()
    {
        $functions = [
            $this->ignoreUserDeprecation(self::JS_FUNCTION_CREATE_DEPRECATION_PATTERN, function () {
                // @phpstan-ignore-next-line
                return JsFunction::create("
                    return 'Callback using scope: ' + foo;
                ", ['foo' => 'bar']);
            }),
            JsFunction::createWithScope(['foo' => 'bar'])
                ->body("return 'Callback using scope: ' + foo;"),
        ];

        foreach ($functions as $function) {
            $value = $this->fs->runCallback($function);
            $this->assertEquals('Callback using scope: bar', $value);
        }
    }

    /**
     * @group js-functions
     */
    public function testCanUseResourcesInJsFunctions()
    {
        $fileStats = $this->fs->statSync($this->filePath);

        $functions = [
            JsFunction::createWithParameters(['fs', 'fileStats' => $fileStats])
                ->body("return fileStats.isFile();"),
            JsFunction::createWithScope(['fileStats' => $fileStats])
                ->body("return fileStats.isFile();"),
        ];

        foreach ($functions as $function) {
            $isFile = $this->fs->runCallback($function);
            $this->assertTrue($isFile);
        }
    }

    /**
     * @group js-functions
     */
    public function testCanUseAsyncWithJsFunctions()
    {
        $function = JsFunction::createWithAsync()
            ->body("
                await Promise.resolve();
                return true;
            ");

        $this->assertTrue($this->fs->runCallback($function));

        $function = $function->async(false);

        $this->expectException(Node\FatalException::class);
        $this->expectExceptionMessage('await is only valid in async function');

        $this->fs->runCallback($function);
    }

    /**
     * @group js-functions
     */
    public function testJsFunctionsAreSyncByDefault()
    {
        $function = JsFunction::createWithBody('await null');

        $this->expectException(Node\FatalException::class);
        $this->expectExceptionMessage('await is only valid in async function');

        $this->fs->runCallback($function);
    }

    public function testCanReceiveHeavyPayloadsWithNonAsciiChars()
    {
        $payload = $this->fs->getHeavyPayloadWithNonAsciiChars();

        $this->assertStringStartsWith('😘', $payload);
        $this->assertStringEndsWith('😘', $payload);
    }

    public function testNodeCrashThrowsAFatalException()
    {
        $this->expectException(\Nesk\Rialto\Exceptions\Node\FatalException::class);
        $this->expectExceptionMessage('Object.__inexistantMethod__ is not a function');

        $this->fs->__inexistantMethod__();
    }

    public function testCanCatchErrors()
    {
        $this->expectException(\Nesk\Rialto\Exceptions\Node\Exception::class);
        $this->expectExceptionMessage('Object.__inexistantMethod__ is not a function');

        $this->fs->tryCatch->__inexistantMethod__();
    }

    public function testCatchingANodeExceptionDoesntCatchFatalExceptions()
    {
        $this->expectException(\Nesk\Rialto\Exceptions\Node\FatalException::class);
        $this->expectExceptionMessage('Object.__inexistantMethod__ is not a function');

        try {
            $this->fs->__inexistantMethod__();
        } catch (Node\Exception $exception) {
            //
        }
    }

    /**
     * @dontPopulateProperties fs
     */
    public function testInDebugModeNodeExceptionsContainStackTraceInMessage()
    {
        $this->fs = new FsWithProcessDelegation(['debug' => true]);

        $regex = '/\n\nError: "Object\.__inexistantMethod__ is not a function"\n\s+at /';

        try {
            $this->fs->tryCatch->__inexistantMethod__();
        } catch (Node\Exception $exception) {
            $this->assertMatchesRegularExpression($regex, $exception->getMessage());
        }

        try {
            $this->fs->__inexistantMethod__();
        } catch (Node\FatalException $exception) {
            $this->assertMatchesRegularExpression($regex, $exception->getMessage());
        }
    }

    public function testNodeCurrentWorkingDirectoryIsTheSameAsPhp()
    {
        $result = $this->fs->accessSync('tests/resources/file');

        $this->assertNull($result);
    }

    public function testExecutablePathOptionChangesTheProcessPrefix()
    {
        $this->expectException(\Symfony\Component\Process\Exception\ProcessFailedException::class);
        $this->expectExceptionMessageMatches('/Error Output:\n=+\n.*__inexistant_process__.*not found/');

        new FsWithProcessDelegation(['executable_path' => '__inexistant_process__']);
    }

    /**
     * @dontPopulateProperties fs
     */
    public function testIdleTimeoutOptionClosesNodeOnceTimerIsReached()
    {
        $this->fs = new FsWithProcessDelegation(['idle_timeout' => 0.5]);

        $this->fs->constants;

        \sleep(1);

        $this->expectException(\Nesk\Rialto\Exceptions\IdleTimeoutException::class);
        $this->expectExceptionMessageMatches('/^The idle timeout \(0\.500 seconds\) has been exceeded/');

        $this->fs->constants;
    }

    /**
     * @dontPopulateProperties fs
     */
    public function testReadTimeoutOptionThrowsAnExceptionOnLongActions()
    {
        $this->expectException(\Nesk\Rialto\Exceptions\ReadSocketTimeoutException::class);
        $this->expectExceptionMessageMatches('/^The timeout \(0\.010 seconds\) has been exceeded/');

        $this->fs = new FsWithProcessDelegation(['read_timeout' => 0.01]);

        $this->fs->wait(20);
    }

    /**
     * @group logs
     * @dontPopulateProperties fs
     */
    public function testForbiddenOptionsAreRemoved()
    {
        $loggerHandler = new TestHandler();
        $logger = new Logger('test', [$loggerHandler]);

        $this->fs = new FsWithProcessDelegation([
            'logger' => $logger,
            'read_timeout' => 5,
            'stop_timeout' => 0,
            'foo' => 'bar',
        ]);

        $options = $loggerHandler->getRecords()[0]['context']['options'];
        $this->assertArrayHasKey('read_timeout', $options);
        $this->assertArrayNotHasKey('stop_timeout', $options);
        $this->assertArrayNotHasKey('foo', $options);
    }

    /**
     * @dontPopulateProperties fs
     */
    public function testConnectionDelegateReceivesOptions()
    {
        $this->fs = new FsWithProcessDelegation([
            'log_node_console' => true,
            'new_option' => false,
        ]);

        $this->assertNull($this->fs->getOption('read_timeout')); // Assert this option is stripped by the supervisor
        $this->assertTrue($this->fs->getOption('log_node_console'));
        $this->assertFalse($this->fs->getOption('new_option'));
    }

    /**
     * @dontPopulateProperties fs
     */
    public function testProcessStatusIsTracked()
    {
        if (PHP_OS === 'WINNT') {
            $this->markTestSkipped('This test is not supported on Windows.');
        }

        if ((new Process(['which', 'pgrep']))->run() !== 0) {
            $this->markTestSkipped('The "pgrep" command is not available.');
        }

        $oldPids = $this->getPidsForProcessName('node');
        $this->fs = new FsWithProcessDelegation();
        $newPids = $this->getPidsForProcessName('node');

        $newNodeProcesses = \array_values(\array_diff($newPids, $oldPids));
        $newNodeProcessesCount = \count($newNodeProcesses);
        $this->assertCount(
            1,
            $newNodeProcesses,
            "One Node process should have been created instead of $newNodeProcessesCount. Try running again."
        );

        $processKilled = \posix_kill($newNodeProcesses[0], SIGKILL);
        $this->assertTrue($processKilled);

        \usleep(10000); # To make sure the process had enough time to be killed.

        $this->expectException(\Nesk\Rialto\Exceptions\ProcessUnexpectedlyTerminatedException::class);
        $this->expectExceptionMessage('The process has been unexpectedly terminated.');

        $this->fs->foo;
    }

    public function testProcessIsProperlyShutdownWhenThereAreNoMoreReferences()
    {
        if (!\class_exists('WeakReference')) {
            $this->markTestSkipped('This test requires weak references: https://www.php.net/weakreference');
        }

        $ref = \WeakReference::create($this->fs->getProcessSupervisor());

        $resource = $this->fs->readFileSync($this->filePath);

        $this->assertInstanceOf(BasicResource::class, $resource);

        $this->fs = null;
        unset($resource);

        $this->assertNull($ref->get());
    }

    /**
     * @group logs
     * @dontPopulateProperties fs
     */
    public function testLoggerIsUsedWhenProvided()
    {
        $loggerHandler = new TestHandler();
        $logger = new Logger('test', [$loggerHandler]);
        $this->fs = new FsWithProcessDelegation(['logger' => $logger]);

        $this->assertNotCount(0, $loggerHandler->getRecords());
    }

    /**
     * @dataProvider shouldLogNodeConsoleProvider
     * @group logs
     * @dontPopulateProperties fs
     */
    public function testNodeConsoleCallsAreLogged(bool $shouldLogNodeConsole)
    {
        $loggerHandler = new TestHandler();
        $logger = new Logger('test', [$loggerHandler]);
        $this->fs = new FsWithProcessDelegation([
            'log_node_console' => $shouldLogNodeConsole,
            'logger' => $logger,
        ]);

        $this->fs->runCallback(JsFunction::createWithBody("console.log('Hello World!')"));

        $this->assertTrue(self::logHandlerContainsRecord($loggerHandler, 'Received a Node log:'));
    }

    public function shouldLogNodeConsoleProvider(): \Generator
    {
        yield [false];
        yield [true];
    }

    /**
     * @group logs
     * @dontPopulateProperties fs
     */
    public function testDelayedNodeConsoleCallsAndDataOnStandardStreamsAreLogged()
    {
        $loggerHandler = new TestHandler();
        $logger = new Logger('test', [$loggerHandler]);
        $this->fs = new FsWithProcessDelegation([
            'log_node_console' => true,
            'logger' => $logger,
        ]);

        $this->fs->runCallback(JsFunction::createWithBody("
            setTimeout(() => {
                process.stdout.write('Hello Stdout!');
                console.log('Hello Console!');
            });
        "));

        \usleep(10000); // 10ms, to be sure the delayed instructions just above are executed.

        $this->assertTrue(self::logHandlerContainsRecord($loggerHandler, 'Received data on stdout:'));
        $this->assertTrue(self::logHandlerContainsRecord($loggerHandler, 'Received a Node log:'));
    }

    private static function logHandlerContainsRecord(TestHandler $testHandler, string $messageStartWith): bool
    {
        $records = \array_filter($testHandler->getRecords(), function (array $record) use ($messageStartWith): bool {
            return \strpos($record['message'], $messageStartWith) === 0;
        });

        return $records > 1;
    }
}
