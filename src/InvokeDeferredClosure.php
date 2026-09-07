<?php

namespace MathiasGrimm\QueueConcurrency;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use Throwable;

/**
 * A fire and forget task dispatched by QueueDriver::defer().
 *
 * It exists instead of CallQueuedClosure for one reason: on a synchronous
 * queue link a rethrown failure is not a recorded failure, it is what makes
 * a failover queue treat the link as dead and run the task again on the next
 * one. Everywhere else it behaves like CallQueuedClosure would: the worker
 * decides how many attempts it gets, the closure may ask for the job, and a
 * closure whose models are gone is discarded rather than failed.
 */
class InvokeDeferredClosure implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Delete the job when its models no longer exist, as CallQueuedClosure does.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    public function __construct(public SerializableClosure $task)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ContainerContract $container): void
    {
        try {
            $container->call($this->task->getClosure(), ['job' => $this]);
        } catch (Throwable $e) {
            if ($this->job instanceof SyncJob) {
                report($e);

                return;
            }

            throw $e;
        }
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        $reflection = new ReflectionFunction($this->task->getClosure());

        return 'Deferred concurrency task ('.basename((string) $reflection->getFileName()).':'.$reflection->getStartLine().')';
    }
}
