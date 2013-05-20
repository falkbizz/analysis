<?php
class Analysis_Metric_UsabilityUrlTest extends PHPUnit_Framework_TestCase
{
    public function testProcess()
    {
        $analyzer = new Analysis\Analyzer();
        $page = new Analysis\Page();

        $metric = new Analysis\Metric\UsabilityUrl();
        $metric->setAnalyzer($analyzer);
        $metric->setPage($page);

        $this->assertTrue($metric->process());
    }
}