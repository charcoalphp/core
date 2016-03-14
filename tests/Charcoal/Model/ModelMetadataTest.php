<?php

namespace Charcoal\Tests\Model;

use \Charcoal\Model\ModelMetadata;

class ModelMetadataTest extends \PHPUnit_Framework_TestCase
{
    public function testContructor()
    {
        $obj = new ModelMetadata();
        $this->assertInstanceOf('\Charcoal\Model\ModelMetadata', $obj);
    }

    public function testArrayAccessGet()
    {
        $obj = new ModelMetadata();
        $obj->foo = 'bar';

        $this->assertEquals($obj->foo, $obj['foo']);
    }

    public function testArrayAccessSet()
    {
        $obj = new ModelMetadata();
        $obj['foo'] = 'bar';

        $this->assertEquals($obj->foo, $obj['foo']);
    }

    public function testArrayAccessSetWithNoOffsetThrowsException()
    {
        $this->setExpectedException('\InvalidArgumentException');

        $obj = new ModelMetadata();
        $obj[] = 'bar';
    }

    public function testArrayAccessUnset()
    {
        $obj = new ModelMetadata();
        $this->assertObjectNotHasAttribute('foo', $obj);

        $obj['foo'] = 'bar';
        $this->assertObjectHasAttribute('foo', $obj);

        unset($obj['foo']);
        //$this->assertObjectNotHasAttribute('foo', $obj);
    }

    public function testSetData()
    {
        $data = [
            'foo' => 'bar',
            'bar' => 'foo'
        ];

        $obj = new ModelMetadata();
        $obj->merge($data);

        $this->assertEquals($obj->foo, 'bar');
        $this->assertEquals($obj->bar, 'foo');
    }

    public function testSetDataIsChainable()
    {
        $obj = new ModelMetadata();
        $ret = $obj->merge([]);

        $this->assertSame($obj, $ret);
    }
}
