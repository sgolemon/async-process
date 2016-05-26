<?hh // strict

namespace sgolemon\Async\Process\Exception;

/**
 * Thrown when trying to perform an action on an Async\Process
 * which may only be performed on started processes
 */
class NotStarted extends Base {}
