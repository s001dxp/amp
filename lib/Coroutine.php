<?php declare(strict_types = 1);

namespace Amp;

use Interop\Async\{ Awaitable, Loop };

/**
 * Creates an awaitable from a generator function yielding awaitables.
 *
 * When an awaitable is yielded, execution of the generator is interrupted until the awaitable is resolved. A success
 * value is sent into the generator, while a failure reason is thrown into the generator. Using a coroutine,
 * asynchronous code can be written without callbacks and be structured like synchronous code.
 */
final class Coroutine implements Awaitable {
    use Internal\Placeholder;

    /**
     * Maximum number of immediate coroutine continuations before deferring next continuation to the loop.
     *
     * @internal
     */
    const MAX_CONTINUATION_DEPTH = 3;

    /** @var \Generator */
    private $generator;

    /** @var callable(\Throwable|null $exception, mixed $value): void */
    private $when;

    /** @var int */
    private $depth = 0;

    /**
     * @param \Generator $generator
     */
    public function __construct(\Generator $generator) {
        $this->generator = $generator;

        /**
         * @param \Throwable|null $exception Exception to be thrown into the generator.
         * @param mixed $value Value to be sent into the generator.
         */
        $this->when = function ($exception, $value) {
            if ($this->depth > self::MAX_CONTINUATION_DEPTH) { // Defer continuation to avoid blowing up call stack.
                Loop::defer(function () use ($exception, $value) {
                    ($this->when)($exception, $value);
                });
                return;
            }

            try {
                if ($exception) {
                    // Throw exception at current execution point.
                    $yielded = $this->generator->throw($exception);
                } else {
                    // Send the new value and execute to next yield statement.
                    $yielded = $this->generator->send($value);
                }

                if ($yielded instanceof Awaitable) {
                    ++$this->depth;
                    $yielded->when($this->when);
                    --$this->depth;
                    return;
                }

                if ($this->generator->valid()) {
                    $got = \is_object($yielded) ? \get_class($yielded) : \gettype($yielded);
                    throw new InvalidYieldError(
                        $this->generator,
                        \sprintf("Unexpected yield (%s expected, got %s)", Awaitable::class, $got)
                    );
                }

                $this->resolve($this->generator->getReturn());
            } catch (\Throwable $exception) {
                $this->dispose($exception);
            }
        };

        try {
            $yielded = $this->generator->current();

            if ($yielded instanceof Awaitable) {
                ++$this->depth;
                $yielded->when($this->when);
                --$this->depth;
                return;
            }

            if ($this->generator->valid()) {
                $got = \is_object($yielded) ? \get_class($yielded) : \gettype($yielded);
                throw new InvalidYieldError(
                    $this->generator,
                    \sprintf("Unexpected yield (%s expected, got %s)", Awaitable::class, $got)
                );
            }

            $this->resolve($this->generator->getReturn());
        } catch (\Throwable $exception) {
            $this->dispose($exception);
        }
    }

    /**
     * Runs the generator to completion then fails the coroutine with the given exception.
     *
     * @param \Throwable $exception
     */
    private function dispose(\Throwable $exception) {
        if ($this->generator->valid()) {
            try {
                try {
                    // Ensure generator has run to completion to avoid throws from finally blocks on destruction.
                    do {
                        $this->generator->throw($exception);
                    } while ($this->generator->valid());
                } finally {
                    // Throw from finally to attach any exception thrown from generator as previous exception.
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                // $exception will be used to fail the coroutine.
            }
        }

        $this->fail($exception);
    }
}
