<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos García Gómez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Model\Base;

use Closure;
use FacturaScripts\Dinamic\Lib\StockMovementManager;

/**
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class BusinessDocumentLine
{
    public static function transfer(): Closure
    {
        return function ($fromCodalmacen, $toCodalmacen, $doc) {
            if (empty($this->idproducto)) {
                return;
            }

            // el documento cambia de almacén: reconstruimos los movimientos del producto en segundo plano
            StockMovementManager::queueProductRebuild($this->idproducto);
        };
    }

    protected function updateStock(): Closure
    {
        return function ($doc) {
            if (empty($this->idproducto)) {
                return;
            }

            // el stock ya lo actualiza el core: reconstruimos los movimientos del producto en segundo plano
            StockMovementManager::queueProductRebuild($this->idproducto);
        };
    }
}
