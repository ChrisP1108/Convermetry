<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

/**
 * A real webhook receiver on localhost, for the duration of the suite.
 *
 * The end-to-end suite's last mile. Everything before it can be asserted from
 * inside the process; whether a payload actually left WordPress over HTTP, with
 * the headers and signature it claims, can only be observed by something that
 * receives it.
 */
final class WebhookReceiver
{
    /** @var resource|null */
    private $process = null;

    private string $log;

    private string $token;

    public function __construct(private int $port)
    {
        $this->log   = (string) tempnam(sys_get_temp_dir(), 'cvm-receiver-');
        $this->token = bin2hex(random_bytes(8));
    }

    /**
     * Starts PHP's built-in server and waits until THIS server answers.
     *
     * The identity probe is not defensive decoration. Waiting for the port to
     * accept connections proves only that something is listening, and a server
     * orphaned by an interrupted earlier run answers just as well — writing its
     * captures to a temp file this instance has never heard of. The result is
     * the worst kind of test failure: deliveries succeed, the Activity Log
     * records a 200, and the assertion on what "arrived" reads an empty file.
     * So the caller asks who is there, and only accepts its own token.
     *
     * @return bool True once this instance's server is answering.
     */
    public function start(): bool
    {
        $descriptors = [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', __DIR__, __DIR__ . '/receiver.php'],
            $descriptors,
            $pipes,
            null,
            ['CVM_RECEIVER_LOG' => $this->log, 'CVM_RECEIVER_TOKEN' => $this->token] + getenv()
        );

        if (!is_resource($process)) {
            return false;
        }

        $this->process = $process;

        // Cleanup that survives a fatal, an interrupt, or a failed assertion
        // that skips tearDown. Without it an interrupted run leaves a server
        // holding the port, and the NEXT run's server cannot bind — which the
        // identity probe now reports honestly instead of quietly using the
        // stranger, but is still better not to cause.
        register_shutdown_function(function (): void {
            $this->stop();
        });

        // A process that could not bind the port exits immediately; stop
        // waiting on it rather than burning the whole timeout.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $answer = @file_get_contents(
                'http://127.0.0.1:' . $this->port . '/whoami',
                false,
                stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]])
            );

            if (is_string($answer) && trim($answer) === $this->token) {
                return true;
            }

            $status = proc_get_status($process);

            if (is_array($status) && $status['running'] === false) {
                break;
            }

            usleep(50_000);
        }

        $this->stop();

        return false;
    }

    /**
     * Whether something other than this instance is holding the port.
     *
     * @return bool
     */
    public function portHeldByStranger(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);

        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if (is_file($this->log)) {
            unlink($this->log);
        }
    }

    /**
     * The port the receiver listens on.
     *
     * @return int
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * The URL a webhook endpoint should be pointed at.
     *
     * @param string $path '/ok' answers 200, '/fail' answers 500.
     * @return string
     */
    public function url(string $path = '/ok'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    /**
     * @return void
     */
    public function forget(): void
    {
        file_put_contents($this->log, '');
    }

    /**
     * Every request received so far, oldest first.
     *
     * @return list<array{path: string, method: string, headers: array<string, string>, body: string}>
     */
    public function received(): array
    {
        $out = [];

        foreach (explode("\n", (string) file_get_contents($this->log)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                /** @var array{path: string, method: string, headers: array<string, string>, body: string} $decoded */
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * Waits until at least $count requests have arrived, or the timeout passes.
     *
     * @param int   $count   Requests to wait for.
     * @param float $seconds How long to wait.
     * @return bool
     */
    public function waitFor(int $count, float $seconds = 5.0): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            if (count($this->received()) >= $count) {
                return true;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
