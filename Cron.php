<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado;

use FacturaScripts\Core\Base\CronClass;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockValue;

class Cron extends CronClass
{
    public function run()
    {
        // añadimos este proceso al cron para no tener que hacerlo durante la instalación del plugin
        if ($this->isTimeForJob(StockMovementManager::JOB_NAME, StockMovementManager::JOB_PERIOD)) {
            StockMovementManager::rebuild();
            $this->jobDone(StockMovementManager::JOB_NAME);
        }

        // con este proceso recalculamos el valor del stock de cada almacén
        if($this->isTimeForJob(StockValue::JOB_NAME, StockValue::JOB_PERIOD)) {
            StockValue::updateAll();
            $this->jobDone(StockValue::JOB_NAME);
        }
    }
}

