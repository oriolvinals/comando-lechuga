<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A FútbolFantasy team page we can't read a lineup from — the sync skips the
 * team and leaves its rows untouched.
 */
class FutbolFantasyPageException extends RuntimeException {}
