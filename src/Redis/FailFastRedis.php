<?php

declare(strict_types=1);

namespace App\Redis;

/**
 * The application's phpredis connection: fails fast and can be re-established on demand.
 *
 * Two phpredis behaviours make the stock connection unsuitable for long-lived workers:
 *
 *  1. When an established connection is lost, phpredis 6 retries the command up to 10 times with
 *     exponential back-off. Measured against a stopped Redis container the first command after the loss
 *     blocks for ~39 seconds. Driver retries are disabled here (OPT_MAX_RETRIES = 0).
 *  2. A failed connection stays dead ("Redis server went away") until connect() is called again, even after
 *     the server is back. {@see self::reconnect()} gives the circuit breaker a way to heal the handle.
 *
 * Creation never touches the network and never throws, so building the service graph is safe while Redis is
 * down. The first reconnect()/command connects.
 *
 * Supported DSN: redis://[:password@]host[:port][/db][?timeout=0.5&read_timeout=0.5]
 */
final class FailFastRedis extends \Redis
{
    private string $host = 'localhost';
    private int $port = 6379;
    private float $connectTimeout = 0.5;
    private float $readTimeout = 0.5;
    private ?string $password = null;
    private int $database = 0;

    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);
        if (false === $parts || 'redis' !== ($parts['scheme'] ?? null) || !isset($parts['host'])) {
            throw new \InvalidArgumentException('REDIS_URL must look like redis://[:password@]host[:port][/db][?timeout=0.5&read_timeout=0.5].');
        }

        parse_str($parts['query'] ?? '', $query);

        $redis = new self();
        $redis->host = $parts['host'];
        $redis->port = $parts['port'] ?? 6379;
        $redis->connectTimeout = is_numeric($query['timeout'] ?? null) ? (float) $query['timeout'] : 0.5;
        $redis->readTimeout = is_numeric($query['read_timeout'] ?? null) ? (float) $query['read_timeout'] : 0.5;
        $redis->password = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
        $redis->database = isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0;

        return $redis;
    }

    /**
     * Drops the current socket (if any) and opens a fresh one.
     *
     * @return bool whether Redis is reachable now
     */
    public function reconnect(): bool
    {
        try {
            @$this->close();
        } catch (\Throwable) {
            // Nothing to close.
        }

        try {
            if (!@$this->connect($this->host, $this->port, $this->connectTimeout, null, 0, $this->readTimeout)) {
                return false;
            }

            $this->setOption(\Redis::OPT_MAX_RETRIES, 0);

            if (null !== $this->password) {
                $this->auth($this->password);
            }

            if ($this->database > 0) {
                $this->select($this->database);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
