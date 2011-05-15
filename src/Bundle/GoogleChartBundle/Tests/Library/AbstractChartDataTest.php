<?php

namespace Bundle\GoogleChartBundle\Tests\Library;


abstract class AbstractChartDataTest extends \PHPUnit_Framework_TestCase {
    
    public function testDataManipulation() {
        
        // add single value
        $this->chartData->add(5); // insert 5
        $this->assertEquals(1, count($this->chartData)); // check data count
        $this->assertEquals(5, $this->chartData[0]); // check the inserted value

        // add indexed value
        $this->chartData[2] = 7; // try to insert number 5 to index 2 as it was an ordinary array
        $this->assertEquals(2, count($this->chartData)); // check data count
        $this->chartData->add(9, 11); // add number 9 to index 11
        $this->assertEquals(3, count($this->chartData)); // check data count
        $this->assertEquals(5, $this->chartData[0]); // check index 0
        $this->assertEquals(7, $this->chartData[2]); // check index 2
        $this->assertEquals(9, $this->chartData[11]); // check index 11
        $this->chartData->add(array(100, 101, 102, 103)); // add array
        $this->assertEquals(7, count($this->chartData)); // check data count

        // unsetting and reseting
        unset($this->chartData[0]); // remove first item from the data collection
        $this->assertEquals(6, count($this->chartData)); // check data count
        $this->assertFalse(isset($this->chartData[0])); // make sure removed item doesn't exists
        $this->chartData->removeAll();
        $this->assertEquals(0, count($this->chartData)); // check data count
        $this->chartData->reset();
        $this->assertEquals('auto', $this->chartData->getColor()); // make sure that default color is set to auto
        
        $this->chartData->removeAll();
        $this->chartData->add(array(100, 101, 102, 103)); // add array to an empty collection
        $this->assertEquals(4, count($this->chartData)); // check data count
    }
        
    public function testSettings() {
        // changing settings
        $this->chartData->setColour('FF0000'); // set new colour
        $this->assertEquals('FF0000', $this->chartData->getColour()); // check the colour
        $this->chartData->setColor('008800'); // the same but the US version
        $this->assertEquals('008800', $this->chartData->getColor()); //the same but the US version
        $this->chartData->setTitle('my cool title');  // change title
        $this->assertEquals('my cool title', $this->chartData->getTitle()); // check the title
    }
    
    public function testIteration() {
        $array = array(100, 101, 102, 103);
        $this->chartData->add($array);
        foreach ($this->chartData as $index => $value) {
            $this->assertEquals($array[$index], $value);
        }
    }
    
    /**
     * @expectedException InvalidArgumentException 
     */
    public function testAddArrayException() {
        $this->chartData->add(array(100, 101, 102, 103), 2); // rise an exception
    }
    
    /**
     * @expectedException InvalidArgumentException 
     */
    public function testColourException() {
        $this->chartData->setColour('red'); // rise an exception
    }
    
}
