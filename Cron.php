<?php
namespace FacturaScripts\Plugins\StockAvanzado;

use FacturaScripts\Plugins\StockAvanzado\Lib\StockMovementManager;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;

class Cron extends \FacturaScripts\Core\Base\CronClass
{
    public function run() {
        if ($this->isTimeForJob("my-job-name", "1 hours")) 
        {
            $movement = new MovimientoStock();
            if ($movement->count() < 1) {
                StockMovementManager::rebuild();
            }
        }
    }
}

