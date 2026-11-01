<?php

namespace App\Services\RetailCRM\Http;

use Illuminate\Support\Fluent;

class Response extends Fluent implements \Countable, \IteratorAggregate
{
    /**
     * Check if the response was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->get('success', false);
    }

    /**
     * Count elements.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->attributes);
    }

    /**
     * Get an iterator for the attributes.
     *
     * @return \ArrayIterator
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->attributes);
    }

    /**
     * Magic getter to access attributes or nested arrays as objects.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        $value = parent::__get($key);

        if (is_array($value)) {
            // If it's an associative array, wrap it in Response (Fluent)
            if ($this->isAssoc($value)) {
                return new static($value);
            }
            // If it's a list of arrays, wrap each item
            return array_map(function ($item) {
                return is_array($item) ? new static($item) : $item;
            }, $value);
        }

        return $value;
    }

    /**
     * Check if an array is associative.
     *
     * @param array $array
     * @return bool
     */
    protected function isAssoc(array $array)
    {
        $keys = array_keys($array);
        return array_keys($keys) !== $keys;
    }
}
