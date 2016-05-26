<?hh // strict

namespace sgolemon\Async;
use sgolemon\Async\Process\Exception;
use HH\Asio;

class Process {
  /**
   * Command to execute
   */
  protected ?string $cmd = null;

  /**
   * Process control handle
   */
  protected ?resource $handle = null;

  /**
   * I/O spec, defines IPC pipes to create between processes
   */
  protected array<int,mixed> $spec = array(
    0 => array("pipe", "r"), // stdin
    1 => array("pipe", "w"), // stdout
    2 => array("pipe", "w"), // srderr
  );

  /**
   * Established pipes, see $this->spec
   */
  protected array<int,resource> $pipes = array();

  /**
   * Current working directory (at process start)
   */
  protected string $cwd = '';

  /**
   * Environment variables to set in process
   */
  protected array<string, string> $env = array();

  /**
   * Numeric exit code, set after process completion
   */
  protected ?int $exitcode = null;

  /**
   * Default read blocksize
   */
  const int DEFAULT_READ = 8192;

  /**
   * Arguments to Async\Process constructor match
   * ->setCommand() as a convenience
   *
   * As with ->setCommand(), all parameters will be filtered through
   * escapeshellarg() to reduce surface area for remote code injection
   *
   * @param string $cmd - Command to execute
   * @param string... $args - Arguments to the command
   */
  public function __construct(?string $cmd = null, string ...$args) {
    if ($cmd === null) {
      if (count($args) > 0) {
        throw new Exception\Base(
          "Args provided without a command"
        );
      }
      return;
    }
    $this->setCommand($cmd, ...$args);
    $this->cwd = getcwd();
  }

  /**
   * Set the command to execute,
   * Separate each command argument (including the command itself)
   * as an individual argument.
   * They will be escaped for use using escapeshellarg()
   *
   * @param string $cmd - Command to execute
   * @param string... $args - Arguments to the command
   * @return this - Object instance for chaining
   */
  public function setCommand(string $cmd, string ...$args): this {
    $this->assertNotStarted();
    $rawcmd = escapeshellarg($cmd);
    foreach ($args as $arg) {
      $rawcmd .= ' ' . escapeshellarg($arg);
    }
    return $this->setRawCommand($rawcmd);
  }

  /**
   * Set raw command, performing no output escaping.
   * Call this method if you like Remote Command Injection.
   * Because this is how you get Remote Command Injection.
   *
   * @param string $cmd - Raw command to execute
   * @return this - Object instance for chaining
   */
  public function setRawCommand(string $cmd): this {
    $this->assertNotStarted();
    $this->cmd = $cmd;
    return $this;
  }

  /**
   * Retreive the configured command to execute/being executed
   * @return ?string $cmd - Command string or NULL if not yet defined
   */
  public function getCommand(): ?string {
    return $this->cmd;
  }

  /**
   * Set the working directory to begin command execution in
   *
   * @param string $cwd - Working directory
   * @return this - Object instance for chaining
   */
  public function setCwd(string $cwd): this {
    $this->assertNotStarted();
    if (!is_dir($cwd)) {
      throw new Exception\Failure(
        "No such directory for CWD: $cwd",
      );
    }
    $this->cwd = $cwd;
    return $this;
  }

  /**
   * Set an individual environment variable for the subprocess
   *
   * @param string $key - Environment variable to set
   * @param string $val - Value to set environment variable to
   * @return this - Object instance for chaining
   */
  public function setEnv(string $key, string $val): this {
    $this->assertNotStarted();
    $this->env[$key] = $val;
    return $this;
  }

  /**
   * Overwrite environment variables for the subprocess
   *
   * @param arrat<string, string> - Map of env vars to values
   * @return this - Object instance for chaining
   */
  public function setAllEnv(array<string,string> $env): this {
    $this->assertNotStarted();
    $this->env = $env;
    return $this;
  }

  /**
   * Retreive a previously set environment variable
   *
   * @param string $key - Name of environment variable
   * @return ?string - Value or NULL if not set
   */
  public function getEnv(string $key): ?string {
    if (array_key_exists($key, $this->env)) {
      return $this->env[$key];
    }
    return null;
  }

  /**
   * Get all currently set environment variables
   *
   * @return array<string,string> - Map of env vars to values
   */
  public function getAllEnv(): array<string,string> {
    return $this->env;
  }

  /**
   * Start the process
   *
   * Strictly speaking, the implementation is
   * synchronous from a PHP standpoint.
   * I've made it "async" in order to future-proof later
   * improvements to the implementations which could potentially
   * involve awaiting on something.
   */
  public async function run(): Awaitable<void> {
    $this->assertNotStarted();
    if ($this->cmd === null) {
      throw new Exception\Config(
        "Use ".__CLASS__."::setCommand() to set the command to run",
      );
    }
    $handle = proc_open(
      $this->cmd,
      $this->spec,
      $this->pipes,
      $this->cwd,
      $this->env,
    );
    if (!is_resource($handle)) {
      throw new Exception\Failure("Unable to start command: {$this->cmd}");
    }
    $this->handle = $handle;
    foreach ($this->pipes as $pipe) {
      stream_set_blocking($pipe, false);
    }
  }

  /**
   * Whether or not the process has been started.
   * Once this is TRUE, no more configuration of the process may be done.
   *
   * @return bool - TRUE if started, FALSE otherwise
   */
  public function isStarted(): bool {
    return $this->handle !== null;
  }

  /**
   * Whether or not the process is (still) running
   *
   * @return bool - TRUE if running, FALSE otherwise
   */
  public function isRunning(): bool {
    if ($this->handle === null) {
      return false;
    }
    $status = $this->getStatus();
    invariant(array_key_exists('running', $status),
             'proc_get_open() not indicating "running"');
    return (bool)$status['running'];
  }

  /**
   * Exit code returned from the completed process.
   *
   * @return ?int - Exit code, or NULL if the process has not completed
   */
  public function exitcode(): ?int {
    if (!$this->isStarted() || $this->isRunning()) {
      return null;
    }
    // isRunning takes care of populating $this->exitcode for us
    return $this->exitcode;
  }

  /**
   * Whether or not the STDOUT pipe is still open
   *
   * @return bool - FALSE if data may still be sent via STDOUT, TRUE otherwise
   */
  public function eofStdout(): bool {
    return feof($this->pipes[1]);
  }

  /**
   * Whether or not the STDERR pipe is still open
   *
   * @return bool - FALSE if data may still be sent via STDERR, TRUE otherwise
   */
  public function eofStderr(): bool {
    return feof($this->pipes[2]);
  }

  /**
   * Read a single block from STDOUT
   *
   * @param int $length - Max bytes to read
   * @param float $timeout - Max wait time, in seconds
   * @return Awaitable<?string> - Data read from process, or NULL on timeout/eof
   */
  public async function readStdout(
    int $length = self::DEFAULT_READ,
    float $timeout = 0.0,
  ): Awaitable<?string> {
    return await $this->readPipe($this->pipes[1], $length, $timeout);
  }

  /**
   * Read a single block from STDERR
   *
   * @param int $length - Max bytes to read
   * @param float $timeout - Max wait time, in seconds
   * @return Awaitable<?string> - Data read from process, or NULL on timeout/eof
   */
  public async function readStderr(
    int $length = self::DEFAULT_READ,
    float $timeout = 0.0,
  ): Awaitable<?string> {
    return await $this->readPipe($this->pipes[2], $length, $timeout);
  }

  /**
   * Read all remaining data from STDOUT
   *
   * @param float $timeout - Max wait time, in seconds
   * @return Awaitable<?string> - Data read from process, or NULL on timeout
   */
  public async function drainStdout(float $timeout = 0.0): Awaitable<?string> {
    return await $this->drainPipe($this->pipes[1], $timeout);
  }

  /**
   * Read all remaining data from STDERR
   *
   * @param float $timeout - Max wait time, in seconds
   * @return Awaitable<?string> - Data read from process, or NULL on timeout
   */
  public async function drainStderr(float $timeout = 0.0): Awaitable<?string> {
    return await $this->drainPipe($this->pipes[2], $timeout);
  }

  /**
   * Read all remaining data from STDOUT & STDERR
   * This is a simple convenience wrapper for awaiting on drainStdout and drainStderr
   *
   * @param float $timeout - Max wait time, in seconds
   * @return Awaitable<?Map<string,?string>> - Data read from process, or NULL on timeout
   */
  public async function drain(
    float $timeout = 0.0,
  ): Awaitable<?Map<string,?string>> {
    $output = await Asio\m(Map {
      'stdout' => $this->drainStdout($timeout),
      'stderr' => $this->drainStderr($timeout),
    });
    if (($output->get('stdout') === null) && ($output->get('stderr') === null)) {
      // Special case for when we got nothing at all
      return null;
    }
    return $output;
  }

  /**
   * Read (and discard) all remaining data from STDOUT & STDERR
   *
   * @param float $timeout - Max wait time, in seconds
   * @return Awaitable<bool> - TRUE if the process ended, FALSE otherwise
   */
  public async function waitClose(float $timeout = 0.0): Awaitable<bool> {
    await $this->drain($timeout);
    return !$this->isRunning();
  }

  /**
   * Send data to the process' STDIN pipe
   *
   * @param string $data - Data to send
   * @param flaot $timeout - Max wait time, in seconds
   * @return int - Actual number of bytes written
   */
  public async function writeStdin(
    string $data,
    float $timeout = 0.0,
  ): Awaitable<int> {
    return await $this->writePipe($this->pipes[0], $data, $timeout);
  }

  /**
   * Signal that no more data will be sent to the process' STDIN pipe
   */
  public function closeStdin(): void {
    fclose($this->pipes[0]);
  }

  /*************** Internal Helpers ***************/

  /**
   * Throw if the command has already been ->run()
   */
  protected function assertNotStarted(): void {
    if ($this->isStarted()) {
      throw new Exception\AlreadyStarted();
    }
  }

  /**
   * Throw if the command has not been ->run()
   */
  protected function assertStarted(): void {
    if (!$this->isStarted()) {
      throw new Exception\NotStarted();
    }
  }

  /**
   * Wrapper for proc_get_status since the value of exitcode must be captured
   */
  protected function getStatus(): array<string, mixed> {
    $this->assertStarted();
    $status = proc_get_status($this->handle);
    invariant(array_key_exists('running', $status),
             'proc_get_open() not indicating "running"');

    if (($this->exitcode === null) && !$status['running']) {
      // Per http://php.net/proc_get_status
      // "Only first call of this function return real value,
      //  next calls return -1." Sigh...
      $exitcode = intval($status['exitcode']);
      if ($exitcode !== -1) {
        $this->exitcode = $exitcode;
      }
    }

    return $status;
  }

  /**
   * Single block reader implementation for STDOUT/STDERR
   */
  protected async function readPipe(
    resource $pipe,
    int $length = self::DEFAULT_READ,
    float $timeout = 0.0,
  ): Awaitable<?string> {
    $this->assertStarted();
    $remaining = $timeout;
    for (;;) {
      $start = $timeout ? microtime(true) : 0.0;
      $data = fread($pipe, $length);
      if (is_string($data) && ($data !== '')) {
        return $data;
      }
      if (feof($pipe)) {
        return null;
      }
      $ret = await stream_await($pipe, STREAM_AWAIT_READ, $remaining);
      if ($ret === STREAM_AWAIT_CLOSED) {
        return null;
      } elseif ($ret === STREAM_AWAIT_ERROR) {
        throw new Exception\Failure("Failed reading from process");
      }
      // Otherwise, timeout or retry read
      if ($timeout) {
        $elapsed = microtime(true) - $start;
        if ($elapsed >= $remaining) {
          return null;
        }
        $remaining -= $elapsed;
      }
    }
  }

  /**
   * Multi-block reader implementation for STDOUT/STDERR
   * Reads until the process runs out of data
   */
  protected async function drainPipe(
    resource $pipe,
    float $timeout = 0.0,
  ): Awaitable<?string> {
    $ret = null;
    $remaining = $timeout;
    while (!feof($pipe)) {
      $start = $timeout ? microtime(true) : 0.0;
      $data = await $this->readPipe($pipe, self::DEFAULT_READ, $remaining);
      if ($data === null) {
        return $ret;
      }
      $ret .= $data;
      if ($timeout) {
        $elapsed = microtime(true) - $start;
        if ($elapsed >= $remaining) {
          return $ret;
        }
        $remaining -= $elapsed;
      }
    }
    return $ret;
  }

  /**
   * Writer implementation for STDIN
   */
  protected async function writePipe(
    resource $pipe,
    string $data,
    float $timeout = 0.0,
  ): Awaitable<int> {
    $this->assertStarted();
    $remaining = $timeout;
    $total = 0;
    for (;;) {
      if (!$this->isRunning()) {
        return $total;
      }
      $start = $timeout ? microtime(true) : 0.0;
      $written = fwrite($pipe, $data);
      if (is_int($written)) {
        $total += $written;
        if ($written === strlen($data)) {
          return $total;
        }
        $data = substr($data, $written);
      }

      $ret = await stream_await($pipe, STREAM_AWAIT_WRITE, $remaining);
      if ($ret === STREAM_AWAIT_CLOSED) {
        return $total;
      } elseif ($ret === STREAM_AWAIT_ERROR) {
        throw new Exception\Failure("Failed writing to process");
      }
      // Otherwise, retry write, and/or timeout in next iteration
      if ($timeout) {
        $elapsed = microtime(true) - $start;
        if ($elapsed >= $remaining) {
          return $total;
        }
        $remaining -= $elapsed;
      }
    }
  }
}
