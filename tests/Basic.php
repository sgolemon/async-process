<?hh

require __DIR__ . '/../vendor/autoload.php';

use sgolemon\Async;
use HH\Asio;

class AsyncProcessBasicTest extends PHPUnit_Framework_TestCase {
  public function testHello() {
    $proc = new Async\Process('echo', '-n', 'Hello');
    Asio\join($proc->run());
    $this->assertTrue($proc->isStarted());
    $this->assertFalse($proc->eofStdout());
    $output = Asio\join($proc->drainStdout());
    $this->assertEquals("Hello", $output);
    $this->assertTrue($proc->eofStdout());
    $this->assertFalse($proc->isRunning());
  }

  public function testBidi() {
    $proc = new Async\Process('tr', 'A-Za-z', 'a-zA-Z');
    Asio\join($proc->run());
    $result = Asio\join(Asio\v(Vector {
      async {
        await $proc->writeStdin('Hello');
        $proc->closeStdin();
      },
      $proc->drainStdout(),
    }));
    $this->assertEquals('hELLO', $result->get(1));
  }

  public function testCwd() {
    $tmp = realpath(sys_get_temp_dir());
    $proc = (new Async\Process('pwd'))
      ->setCwd($tmp);
    Asio\join($proc->run());
    $output = trim(Asio\join($proc->drainStdout()));
    $this->assertEquals($tmp, $output);
  }

  public function testEnv() {
    $proc = (new Async\Process())
      ->setRawCommand('echo -n "$ASYNC_PROCESS"')
      ->setEnv('ASYNC_PROCESS', 'FooBar');
    Asio\join($proc->run());
    $output = trim(Asio\join($proc->drainStdout()));
    $this->assertEquals('FooBar', $output);
  }

  public function testStderr() {
    $proc = new Async\Process('tr');
    Asio\join($proc->run());
    list($output) = explode("\n", Asio\join($proc->drainStderr()), 2);
    $this->assertEquals('tr: missing operand', $output);
  }

  public function testExitcode() {
    $proc = new Async\Process('bash', '-c', 'exit 2');
    Asio\join($proc->run());
    Asio\join($proc->waitClose());
    $this->assertEquals(2, $proc->exitcode());
  }
}
