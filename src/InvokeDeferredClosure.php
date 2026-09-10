<?php

namespace MathiasGrimm\QueueConcurrency;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\Jobs\SyncJob;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

/**
 * A queued closure that reports a failure on a sync connection instead of
 * rethrowing it, so a failover connection does not treat the failure as the
 * connection being down and run the task again.
 */
class InvokeDeferredClosure extends CallQueuedClosure
{
    /**
     * Create a new job instance.
     *
     * The parent's create() uses "new self", which would return a
     * CallQueuedClosure and skip the sync handling below.
     */
    public static function create(Closure $job): static
    {
        return new static(new SerializableClosure($job));
    }

    /**
     * Execute the job.
     */
    public function handle(Container $container): void
    {
        try {
            parent::handle($container);
        } catch (Throwable $e) {
            if ($this->job instanceof SyncJob) {
                report($e);

                return;
            }

            throw $e;
        }
    }
}
