<?php

use Hydrant\Document,
    Hydrant\Connection;

class DocumentTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $m = new MongoClient;
        Connection::setMongoClient($m);
        Connection::setDefaultDatabaseName('test');
    }

    public function tearDown()
    {
        Connection::getMongoClient()->dropDB('test');
    }

    public function testHydrate()
    {
        $doc = Document::hydrate();
        $this->assertNull($doc);

        $document1 = [
            '_id'    => new MongoId(),
            '_class' => 'Hydrant\Document',
            'foo'    => 'bar',
            'baz'    => [1,2,3],
            'test'   => [
                'key1'   => 'value1',
                'key2'   => 'value2'
            ]
        ];

        $doc = Document::hydrate($document1);
        $this->assertEquals('Hydrant\Document', get_class($doc));
        $this->assertEquals('bar', $doc->foo);
        $this->assertEquals([1,2,3], $doc->baz);
        $this->assertInstanceOf('stdClass', $doc->test);
        $this->assertEquals('value1', $doc->test->key1);
        $this->assertTrue($doc->isManaged());

        $document2 = [
            'foo' => 'bar',
            '_class' => 'Hydrant\Document'
        ];
        $doc2 = Document::hydrate($document2);
        $this->assertFalse($doc2->isManaged());
    }

    public function testSave()
    {
        $document1 = [
            '_id'    => new MongoId(),
            '_class' => 'Hydrant\Document',
            'foo'    => 'bar',
            'baz'    => [1,2,3],
            'test'   => [
                'key1'   => 'value1',
                'key2'   => 'value2'
            ]
        ];

        $doc = Document::hydrate($document1);
        $this->assertFalse($doc->isDirty());

        $doc->save(true);

        $this->assertTrue($doc->isManaged());
        $this->assertFalse($doc->isDirty());

        $savedData = Connection::getCollection('default')->findOne(['_id' => $doc->_id]);
        $this->assertEquals($doc->foo, $savedData['foo']);
        $this->assertEquals($doc->baz, $savedData['baz']);
        $this->assertInstanceOf('stdClass', $doc->test);

	$originalData1 = $doc->getOriginalData();
	$doc->baz = 'test';
	$originalData2 = $doc->getOriginalData();
	$doc->save();
	$originalData3 = $doc->getOriginalData();
	$this->assertEquals($originalData1, $originalData2);
	$this->assertEquals('test', $originalData3['baz']);	

        try {
            $document2 = new Document;
            $document2->save();
        } catch (MongoException $e) {
            $this->fail('no documents should be empty');
        }

        // test odd foreach bug in fixPersistence
        $d = new Document;
        $d->name = 'test';
        $d->uid = 1;
        $d->provider = new \MongoId();
        $d->save();
        $this->assertNotNull(Connection::getMongoClient()->test->default->find(['_id' => $d->_id]));
    }

    public function testDirtyBits()
    {
        $doc1 = [
            '_class' => 'Hydrant\Document',
            'foo' => 'bar'
        ];

        $doc = Document::hydrate($doc1);
        $this->assertFalse($doc->isDirty());
        $this->assertFalse($doc->isManaged());

        $doc->save();

        $this->assertTrue($doc->isManaged());
        $this->assertFalse($doc->isDirty());

        $doc->foo = 'baz';

        $this->assertTrue($doc->isDirty());

        $doc->save();

        $this->assertFalse($doc->isDirty());

    }


    public function testDelete()
    {
        $doc1 = new Document;
        $doc1->save();

        $this->assertNotNull(Connection::getCollection('default')->findOne(['_id' => $doc1->_id]));

        $doc1->delete();

        $this->assertNull(Connection::getCollection('default')->findOne(['_id' => $doc1->_id]));

        $doc2 = new Document;
        $doc2->setEmbedded(true);
        try {
            $doc2->delete();
            $this->fail("Should not be able to delete an embedded document");
        } catch (Exception $e) {
        }
    }

    public function testFindMany()
    {
        $mongoIds = [new MongoId, new MongoId, new MongoId];
        $stringIds = ['test1', 'test2', 'test3'];
        $mixedIds = ['test1', new MongoId];

        $foundIds = [];
        foreach ($mongoIds as $id) {
            $b = new Document;
            $b->_id = $id;
            $b->save();
        }
        $foundDocs = Document::findMany($mongoIds);
        foreach ($foundDocs as $doc) {
            $foundIds [] = $doc->_id;
        }
        $this->assertEquals($mongoIds, $foundIds);
        Connection::getMongoClient()->dropDB('test');


        $foundIds = [];
        foreach ($stringIds as $id) {
            $b = new Document;
            $b->_id = $id;
            $b->save();
        }
        $foundDocs = Document::findMany($stringIds);
        foreach ($foundDocs as $doc) {
            $foundIds [] = $doc->_id;
        }
        $this->assertEquals($stringIds, $foundIds);
        Connection::getMongoClient()->dropDB('test');

        $foundIds = [];
        foreach ($mixedIds as $id) {
            $b = new Document;
            $b->_id = $id;
            $b->save();
        }
        $foundDocs = Document::findMany($mixedIds);
        foreach ($foundDocs as $doc) {
            $foundIds [] = $doc->_id;
        }
        $this->assertEquals($mixedIds, $foundIds);
        Connection::getMongoClient()->dropDB('test');

    }

    public function testFind()
    {
        $b = new Document;
        $b->_id = 'test-object';
        $b->save();

        $data = Document::find($b->_id);
        $this->assertNotNull($data, 'object was not found');

        $b->delete();
        $data = Document::find($b->_id);
        $this->assertNull($data, 'object was found when no object should exist');

        $data = Document::find('should-not-exist');
        $this->assertNull($data, 'object was found when no object should exist');
    }

    public function testFindMongoIdBug()
    {
        $b1 = new Document;
        $b1->save();
        $this->assertInstanceOf("MongoId", $b1->_id);

        $id_as_string = (string) $b1->_id;
        $b2 = Document::find($id_as_string);
        $this->assertNotNull($b2);
        $this->assertEquals($b1->getStorage(), $b2->getStorage());
    }

    public function testFindAll()
    {
        $b1 = new Document;
        $b1->_id = 'test-objectB';
        $b1->save();

        $b2 = new Document;
        $b2->_id = 'test-objectA';
        $b2->save();


        $data = Document::findAll();
        $this->assertEquals(2, $data->count());
        $this->assertEquals($b2->_id, $data->current()->_id);
        $data->next();
        $this->assertEquals($b1->_id, $data->current()->_id);
    }

    public function testSearch()
    {
        $b1 = new Document;
        $b1->attr = 'value1value';
        $b1->save();

        $b2 = new Document;
        $b2->attr = 'value2value';
        $b2->save();

        $results = Document::search(['attr' => '1']);
        $this->assertEquals(1, $results->count());
        $this->assertEquals($b1->attr, $results->current()->attr);

        $results = Document::search(['attr' => '2']);
        $this->assertEquals(1, $results->count());
        $this->assertEquals($b2->attr, $results->current()->attr);
    }

    public function testGetCollection()
    {
        $collection = Document::getCollection();
        $this->assertEquals('default', $collection->getName());
        $collection = Document::getCollection('test');
        $this->assertEquals('test', $collection->getName());
    }

    public function testSetGet()
    {
        $d = new Document;
        $d->foo[] = 'bar';
        $this->assertEquals(['bar'], $d->foo);

        $d->bar = 'baz';

        $test = $d->bar;
        $test = 'foo';
        $this->assertEquals('baz', $d->bar);
        $this->assertEquals('foo', $test);
    }

    public function testHydrationEmbeddedObjects()
    {
        $d = new Document;
        $a = new ArrayObject();
        $a['foo'] = 'bar';
        $d->foo = 'bar';
        $d->arrayObject = $a;
        $d->save();

        $data = Connection::getMongoClient()->test->default->findOne(['_id' => $d->_id]);
        $this->assertEquals('bar', $data["foo"]);
        $this->assertEquals('bar', $data["arrayObject"]["foo"]);
        $this->assertEquals('ArrayObject', $data["arrayObject"]["_class"]);

        $d2 = Document::hydrate($data);

        $this->assertEquals($d, $d2);
    }

    public function testJsonSerializable()
    {
        $documentData = [
            '_id' => new \MongoId(),
            'name' => 'testname1',
            '_class' => 'Hydrant\Document',
            'subObject' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
            'arrayObject' => [
                '_class' => 'ArrayObject',
                'data1' => 'value1'
            ]
        ];
        $obj = Document::hydrate($documentData);
        $jsonObj = json_decode(json_encode($obj));

        $this->assertEquals((string) $documentData["_id"], $jsonObj->_id);
        $this->assertEquals('testname1', $jsonObj->name);
        $this->assertEquals('value2', $jsonObj->subObject->key2);
        $this->assertEquals('value1', $jsonObj->arrayObject->data1);
    }
}
