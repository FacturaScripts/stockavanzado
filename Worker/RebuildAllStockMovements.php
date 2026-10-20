<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2026 Carlos García Gómez <carlos@facturascripts.com>
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


namespace FacturaScripts\Plugins\StockAvanzado\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\StockMovementManager;

/**
 * Recorre los productos por páginas y encola la reconstrucción de los que tienen actividad de stock.
 * Cada evento procesa una página y encola la siguiente, así la cola no crece de golpe ni dependemos
 * del tiempo máximo de ejecución.
 */
class RebuildAllStockMovements extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        // el valor del evento es el último idproducto revisado
        $lastId = StockMovementManager::queueRebuildPage((int)$event->value);
        if (null !== $lastId) {
            WorkQueue::send('StockAvanzado.rebuildAllStockMovements', (string)$lastId, $event->params());
        }

        return $this->done();
    }
}
