<?php

declare(strict_types=1);

namespace Hirasso\WP\FPEvents\FieldGroups;

use Exception;
use Hirasso\WP\FPEvents\Core;

/**
 * Global field names
 */
abstract class Fields extends \Hirasso\WP\FPEvents\Singleton
{
    protected Core $core;

    protected function __construct()
    {
        $this->core = Core::instance();
        $this->addHooks();
    }

    private function addHooks(): void
    {
        add_action('acf/include_fields', [$this, 'acf_include_fields']);
    }

    /**
     * Include ACF fields
     */
    final public function acf_include_fields()
    {
        if (! function_exists('acf_add_local_field_group')) {
            throw new Exception("'acf_add_local_field_group()' is not defined");
        }

        $this->addFields();
    }

    /**
     * Must be implemented by children. Should register ACF fields
     */
    abstract protected function addFields();

    /**
     * Returns the field key for a given field name
     */
    public static function key(string $fieldName): string
    {
        return "field_$fieldName";
    }
}
