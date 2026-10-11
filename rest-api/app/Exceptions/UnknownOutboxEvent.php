<?php

namespace App\Exceptions;

use UnexpectedValueException;

/** Jenis event outbox tanpa handler: diparkir (failed_at), bukan diselesaikan diam-diam. */
class UnknownOutboxEvent extends UnexpectedValueException {}
