<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025 Carlos García Gómez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\WorkEvent;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class StockMovement extends CronJobClass
{
    const JOB_NAME = 'movements-rebuild';
    const JOB_PERIOD = '99 years';

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        // si ya hay una reconstrucción completa en curso, no lanzamos otra
        $where = [
            Where::eq('done', false),
            Where::eq('name', 'StockAvanzado.rebuildAllStockMovements')
        ];
        if (WorkEvent::count($where) > 0) {
            self::echo("\n- Ya hay una reconstrucción de movimientos en curso.");
            self::saveEcho();
            return;
        }

        // no borramos los movimientos: cada producto los sustituye al reconstruirse, dentro de una transacción.
        // El worker RebuildAllStockMovements recorre los productos con actividad por páginas y encola su reconstrucción.
        // El identificador de ejecución evita que WorkQueue lo descarte si ya se lanzó otro en este proceso
        WorkQueue::send('StockAvanzado.rebuildAllStockMovements', '0', ['run' => uniqid()]);

        self::saveEcho();
    }
}
