<?php

namespace Bundle\GoogleChartBundle\Tests\LineChart;

use Bundle\GoogleChartBundle\Tests\Library\AbstractChartDataTest;
use Bundle\GoogleChartBundle\Library\LineChart\Line;

class LineTest extends AbstractChartDataTest {
    
    protected $chartData;
    
    public function setUp() {
        $this->chartData = new Line();
    }
    
    /*public function testDefaultOptions() {
        $this->assertFalse($this->chartData->getFilled());
    }*/
    
    public function testFilled() {
        $this->chartData->setFilled(true);
        $this->assertTrue($this->chartData->getFilled()); // should be true
        
        $this->chartData->setFilled(0);
        $this->assertFalse($this->chartData->getFilled()); // should be false
    }
    
    /**
     * @expectedException InvalidArgumentException 
     */
    public function testWidth() {
        $this->chartData->setWidth(100);
        $this->assertEquals($this->chartData->getWidth(), 100);
        
        $this->chartData->setWidth('test');
    }
    

    
}
