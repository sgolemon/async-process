<?hh // strict

namespace sgolemon\Async\Process\Exception;

/**
 * Thrown when trying to perform an action on an Async\Process
 * which may not be performed on started processes
 */
class AlreadyStarted extends Base {}
