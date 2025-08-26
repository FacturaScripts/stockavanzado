<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Dinamic\Lib\InitialStockMovement;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockValue;
use FacturaScripts\Plugins\StockAvanzado\CronJob\StockMinMax;

final class Cron extends CronClass
{
    public function run(): void
    {
        // añadimos este proceso al cron para no tener que hacerlo durante la instalación del plugin
        $this->job(StockMovementManager::JOB_NAME)
            ->every(StockMovementManager::JOB_PERIOD)
            ->withoutOverlapping()
            ->run(function () {
                StockMovementManager::rebuild();
            });

        // con este proceso añadimos un conteo inicial a los productos que no tengan movimientos
        $this->job(InitialStockMovement::JOB_NAME)
            ->every(InitialStockMovement::JOB_PERIOD)
            ->withoutOverlapping()
            ->run(function () {
                InitialStockMovement::run();
            });

        // con este proceso recalculamos el valor del stock de cada almacén
        $this->job(StockValue::JOB_NAME)
            ->every(StockValue::JOB_PERIOD)
            ->withoutOverlapping()
            ->run(function () {
                StockValue::updateAll();
            });

        // con este proceso notificamos de stock bajo/alto
        $this->job(StockMinMax::JOB_NAME)
            ->everyDayAt(1)
            ->withoutOverlapping()
            ->run(function () {
                StockMinMax::run();
            });
    }
}
