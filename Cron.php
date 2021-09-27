<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Plugins\StockAvanzado\Lib\StockMovementManager;

class Cron extends CronClass
{
    const JOB_NAME = 'rebuild-movements';

    public function run()
    {
        /**
         * To speed up the installation, we add this cron to regenerate all the stock movements,
         * but we will only execute it once (100 years -> never more)
         */
        if ($this->isTimeForJob(self::JOB_NAME, '100 years')) {
            StockMovementManager::rebuild();
            $this->jobDone(self::JOB_NAME);
        }
    }
}

