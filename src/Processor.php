<?php

namespace RedisLock;

use Predis\ClientInterface;
use RedisLock\LuaScripts;

/**
 * Simple mutex lock.
 *
 *     __ _(_)_ __  _ __   ___ _ __ _ __   ___  __ _  ___ ___
 *    / _` | | '_ \| '_ \ / _ \ '__| '_ \ / _ \/ _` |/ __/ _ \
 *   | (_| | | | | | | | |  __/ |  | |_) |  __/ (_| | (_|  __/
 *    \__, |_|_| |_|_| |_|\___|_|  | .__/ \___|\__,_|\___\___|
 *    |___/                        |_|
 *
 * @author gjy <ginnerpeace@live.com>
 * @link https://github.com/ginnerpeace/laravel-redis-lock
 */
class Processor
{
    // Redis key prefix
    const KEY_PREFIX = 'mutex-lock:';

    // Response string from redis cmd: set
    const LOCK_SUCCESS = 'OK';

    // Response int from redis lua script: redis.call('del', KEY)
    const UNLOCK_SUCCESS = 1;

    // Params for cmd: set
    const IF_NOT_EXIST = 'NX';

    // Expire type: seconds
    const EXPIRE_TIME_SECONDS = 'EX';

    // Expire type: milliseconds
    const EXPIRE_TIME_MILLISECONDS = 'PX';

    /**
     * Predis Client.
     *
     * @var  Predis\Client
     */
    private $client;

    /**
     * Expire type for the lock key.
     *
     * @var  string
     */
    private $expireType;

    /**
     * Number of retry times.
     *
     * @var  int
     */
    private $retryCount = 3;

    /**
     * How many times do you want to try again.
     *     (milliseconds)
     *
     * @var  int
     */
    private $retryDelay = 200;

    /**
     * This params from service provider.
     *
     * @param   Predis\ClientInterface  $client
     * @param   int  $retryCount
     * @param   int  $retryDelay
     */
    public function __construct(ClientInterface $client, int $retryCount, int $retryDelay)
    {
        $this->client = $client;

        $this->setExpireType(self::EXPIRE_TIME_MILLISECONDS);
        $this->setRetryDelay($retryDelay);
        $this->retryCount = $retryCount;
    }

    /**
     * Set key expire type.
     *
     * @param   string  $value
     * @return  self
     */
    public function setExpireType(string $value): self
    {
        $this->expireType = $value;

        return $this;
    }

    /**
     * Set retry delay time.
     *
     * @param  int  $milliseconds
     */
    public function setRetryDelay(int $milliseconds): self
    {
        $this->retryDelay = $milliseconds;

        return $this;
    }

    /**
     * Get lock.
     *
     * @param   string  $key
     * @param   int  $expire
     * @param   int  $retry
     * @return  array
     *          - Not empty for getted lock.
     *          - Empty for lock timeout.
     */
    public function lock(string $key, int $expire, int $retry = null): array
    {
        $retry = $retry ?? $this->retryCount ?? 0;

        while (! $result = $this->hit($key, $expire)) {
            if (--$retry < 1) {
                return $result;
            }

            usleep(mt_rand(floor($this->retryDelay / 2), $this->retryDelay) * 1000);
        };

        return $result;
    }

    /**
     * Release the lock.
     *
     * @param   array  $payload
     * @return  bool
     */
    public function unlock(array $payload): bool
    {
        if (! isset($payload['key'], $payload['token'])) {
            return false;
        }

        return self::UNLOCK_SUCCESS === $this->client->eval(
            LuaScripts::del(),
            1,
            self::KEY_PREFIX . $payload['key'],
            $payload['token']
        );
    }

    /**
     * Delay a lock if it still effective.
     *
     * @param   array  $payload
     * @param   int  $expire
     * @return  bool
     */
    public function delay(array $payload, int $expire): bool
    {
        if (! isset($payload['key'], $payload['token'])) {
            return false;
        }

        return 1 === $this->client->eval(
            $this->expireType === self::EXPIRE_TIME_MILLISECONDS ?
                LuaScripts::pexpire() : LuaScripts::expire(),
            1,
            self::KEY_PREFIX . $payload['key'],
            $payload['token'],
            $expire
        );
    }

    /**
     * Verify lock payload.
     *
     * @param   array  $payload
     * @return  bool
     */
    public function verify(array $payload): bool
    {
        if (! isset($payload['key'], $payload['token'])) {
            return false;
        }

        return $payload['token'] === $this->client->get(self::KEY_PREFIX . $payload['key']);
    }

    /**
     * Do it.
     *
     * @param   string  $key
     * @param   int  $expire
     * @param   string  $token
     * @return  array
     */
    protected function hit(string $key, int $expire): array
    {

        if (self::LOCK_SUCCESS === (string) $this->client->set(
            self::KEY_PREFIX . $key,
            $token = uniqid(mt_rand()),
            $this->expireType,
            $expire,
            self::IF_NOT_EXIST
        )) {
            return [
                'key' => $key,
                'token' => $token,
                'expire' => $expire,
                'expire_type' => $this->expireType,
            ];
        }

        return [];
    }
}
