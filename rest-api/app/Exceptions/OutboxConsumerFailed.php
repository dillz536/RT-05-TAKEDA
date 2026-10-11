<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Pengganti exception consumer outbox yang aman disimpan di failed_jobs/log: hanya kelas dan
 * kode (mis. SQLSTATE), tanpa SQL, binding, atau ID akun pelapor. Exception asal tidak dirantai.
 */
class OutboxConsumerFailed extends RuntimeException
{
    public static function from(Throwable $e): self
    {
        $code = (string) $e->getCode();

        return new self(class_basename($e).($code !== '' && $code !== '0' ? ':'.$code : ''));
    }
}
