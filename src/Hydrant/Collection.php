<?php
namespace Hydrant;

class Collection implements \Iterator, \Countable
{
    private $cursor;
    private $objects;
    private $currentPosition;
    private $persistObjects;

    public function __construct(\Iterator $cursor, $persistObjects = false)
    {
	    $this->cursor = $cursor;
        $this->cursor->rewind();
        $this->currentPosition = 0;
        $this->objects = [];
        $this->persistObjects = $persistObjects;
    }

    public function setPersistObjects($persistObjects)
    {
        if ($persistObjects == false) {
            unset($this->objects);
        }
        $this->persistObjects = $persistObjects;
    }

    public function setCursorTimeout($timeout)
    {
        $this->cursor->timeout($timeout);
    }

    public function count()
    {
        return $this->cursor->count();
    }

    public function current()
    {;
        if ($this->persistObjects) {
            if (isset($this->objects[$this->key()])) {
                return $this->objects[$this->key()];
            }
        }
        $data = $this->cursor->current();
        $obj = Document::hydrate($data);
        $obj->setManaged(true);
        if ($this->persistObjects) {
            $this->objects[] = $obj;
        }
        return $obj;
    }

    public function next()
    {
        $this->cursor->next();
        $this->currentPosition++;
    }

    public function key()
    {
        return $this->currentPosition;
    }

    public function valid()
    {
        return$this->cursor->valid();
    }

    public function rewind()
    {
        $this->cursor->rewind();
        $this->currentPosition = 0;
    }
}
