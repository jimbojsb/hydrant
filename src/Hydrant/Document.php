<?php
namespace Hydrant;

class Document
{
    protected $isEmebdded = false;
    protected $isManaged = false;
    protected $data;
    protected $originalData;
    protected $persistenceType;
    protected $isDirty = false;

    protected static $collectionName = 'default';

    public function __construct($data = [])
    {
        $this->data = $data;
        $this->originalData = [];
        $this->setPersistenceType($data['_class']);
        unset($this->data['_class']);
    }

    public function __get($property)
    {
        return $this->data[$property];
    }

    public function __set($property, $value)
    {
        if ($this->isManaged) {
            if (!$this->originalData) {
                $this->originalData = $this->data;
            }
            $this->isDirty = true;
        }
        $this->data[$property] = $value;
    }

    public function __unset($property)
    {
        if ($this->isManaged) {
            $this->isDirty = true;
        }
        unset($this->data[$property]);
    }

    public function __isset($property)
    {
        return isset($this->data[$property]);
    }

    public function setManaged($managed)
    {
        $this->isManaged = $managed;
    }

    public function setPersistenceType($persistenceType = null)
    {
        $this->persistenceType = $persistenceType ?: get_class($this);
    }

    public function getPersistenceType()
    {
        return $this->persistenceType;
    }

    public function getStorage()
    {
        return $this->data;
    }

    public function getOriginalData()
    {
        return $this->originalData;
    }

    public function isDirty()
    {
        return $this->isDirty;
    }

    public function isManaged()
    {
        return $this->isManaged;
    }

    public function getOriginalCopy()
    {
        if ($this->originalData) {
            return new self($this->originalData);
        } else {
            return $this;
        }
    }

    public function setEmbedded($embedded)
    {
        $this->isEmebdded = $embedded;
    }

    public function save($upsert = false)
    {
        if ($this->isEmebdded) {
            throw new \Exception("Cannot persist embedded objects directly");
        }

        if (!$this->isDirty && $this->isManaged && !$upsert) {
            return;
        }

        $mongoCollection = Connection::getCollection(static::$collectionName);
        if ($this->isManaged) {
            $data = $this->data;
            $this->fixPersistance($data);
            $options['upsert'] = $upsert;
            $mongoCollection->update(
                ['_id' => $this->_id],
                $data,
                $options
            );
            $this->isDirty = false;
        } else {
            if (!$this->_id) {
                $this->_id = new \MongoId();
            }
            $data = $this->data;
            $this->fixPersistance($data);
            $this->isManaged = true;
            $mongoCollection->insert($data);
        }
    }

    private function fixPersistance(&$data)
    {
        foreach ($data as $key => &$val) {
            if (is_array($val)) {
                if (array_values($val) !== $val) {
                    $val['_class'] = 'array';
                }
            } else if ($val instanceof \Hydrant\Document) {
                $storage = $val->getStorage();
                $storage['_class'] = $val->getPersistenceType();
                $val = $storage;
            }
            if (is_array($val)) {
                foreach ($val as $key => &$value) {
                    if (is_array($value)) {
                        $this->fixPersistance($value);
                    }
                }
            }
        }
    }

    public function delete()
    {
        if ($this->isEmebdded) {
            throw new \Exception("Cannot persist embedded objects directly");
        }
        Connection::getCollection(static::$collectionName)->remove(['_id' => $this->_id]);
    }

    /**
     * @param array $data
     * @param bool $isEmbedded
     * @return \Hydrant\Document
     */
    public static function hydrate($data = [], $isEmbedded = false)
    {
        if (!$data){
            return null;
        }

        $data["_class"] = $data["_class"] ?: get_called_class();
        foreach ($data as $key => &$val) {
            if (is_array($val)) {
                if (array_values($val) !== $val) {
                    $val = self::hydrate($val, true);
                }
            }
        }

        if ($data['_class'] === 'array') {
            unset($data['_class']);
            return $data;
        } else {
            $obj = new $data['_class']($data);
            if ($isEmbedded) {
                $obj->setEmbedded($isEmbedded);
            }
            if ($obj->_id) {
                $obj->setManaged(true);
            }
            return $obj;
        }
    }

    public static function setCollectionName($collectionName)
    {
        self::$collectionName = $collectionName;
    }

    public static function getCollection($collectionName = null)
    {
        return Connection::getCollection($collectionName ?: static::$collectionName);
    }

    public static function find($id)
    {
        if (ctype_xdigit($id)) {
            if (strlen($id) == 24) {
                $id = new \MongoId($id);
            }
        }
        return self::hydrate(self::getCollection()->findOne(['_id' => $id]));
    }

    public static function findMany(array $ids)
    {
        foreach ($ids as &$id) {
            if (ctype_xdigit($id)) {
                if (strlen($id) == 24) {
                    $id = new \MongoId($id);
                }
            }
        }
        return new Collection(self::getCollection()->find(['_id' => ['$in' => $ids]]));
    }

    public static function findAll()
    {
        return new Collection(self::getCollection()->find()->sort(['_id' => 1]));
    }

    public static function search($fieldQueryPair) {
        //Make this a wildcard search if it isn't already.

        foreach($fieldQueryPair as $key => $value) {
            if (substr($value, 0, 1) != '/' && substr($value, -1) != '/') {
                $fieldQueryPair[$key] = new \MongoRegex('/.*' . $value . '.*/i');
            }
        }

        return new Collection(self::getCollection()->find($fieldQueryPair));
    }
}