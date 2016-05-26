===== Awaitable Process

Basic Usage:

```
use sgolemon\Async;
use HH\Asio;

async function swapCase(string $str): Awaitable<?string> {
  $proc = new Async\Process('tr', 'a-zA-Z', 'A-Za-z');
  await $proc->run();

  // Will yield control to other awaitables if writing requires blocking
  await $proc->writeStdin($str);
  $proc->closeStdin();

  // Yield control while reading off result of command execution.
  return await $proc->drainStdout();
}

$hELLO = Asio\join(swapCase("Hello"));
```


