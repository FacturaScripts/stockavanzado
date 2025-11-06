<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\CronJob;

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuildManager;
use FacturaScripts\Dinamic\Model\Producto;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
final class StockMovement extends CronJobClass
{
    const JOB_NAME = 'movements-rebuild';
    const JOB_PERIOD = '99 years';

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        // limpiamos todos los movimientos de stock
        StockMovementManager::setIdProducto(null);
        if (false === StockMovementManager::deleteMovements()) {
            self::echo("\n- Error al eliminar los movimientos de stock.");
            self::saveEcho();
            return;
        }

        // creamos un evento para regenerar los movimientos de stock de cada producto
        $where = [Where::eq('nostock', false)];
        $orderBy = ['idproducto' => 'ASC'];
        $offset = 0;
        $limit = 50;
        do {
            $delay = 1;
            $products = Producto::all($where, $orderBy, $offset, $limit);
            foreach ($products as $product) {
                // enviamos a la cola de trabajo la reconstrucción de los movimientos de stock
                WorkQueue::sendFuture($delay, 'Model.Producto.rebuildStockMovements', $product->id());

                $delay += 2; // incrementamos el retardo para no saturar el sistema
            }

            $offset += $limit;
        } while (count($products) > 0);

        self::saveEcho();
    }
}
