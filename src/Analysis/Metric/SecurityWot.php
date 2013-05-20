<?php
namespace Analysis\Metric;
use Analysis\Metric;
use Analysis\Exception;

class SecurityWot extends Metric
{
    protected $category         = 'Security';
    protected $title            = 'Trust indicators';
    protected $impact_level     = 'medium';
    protected $solve_level      = 'easy';
    protected $pass_level       = 'pass';

    /**
     * @todo finish
     */
    public function process()
    {
        $this->setOutput('Table with WOT details');
    }
}
