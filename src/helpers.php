<?php

use Hirasso\WP\FPEvents\FPEvents;

if (!function_exists('fpe')) {
    function fpe()
    {
        return FPEvents::instance();
    }
}
