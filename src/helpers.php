<?php

use Hirasso\WP\FPEvents\FPEvents;

function fp_events(): FPEvents
{
    return FPEvents::instance()->addHooks();
}
