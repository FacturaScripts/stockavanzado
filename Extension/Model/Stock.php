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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Model;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class Stock
{
    public function saveBefore(): Closure
    {
        return function () {
            // si la cantidad es mayor que el stock mínimo, se elimina la notificación
            if (!empty($this->stockmin) && $this->cantidad > $this->stockmin) {
                $this->sa_notification_min = null;
            }

            // si la cantidad es menor que el stock máximo, se elimina la notificación
            if (!empty($this->stockmax) && $this->cantidad < $this->stockmax) {
                $this->sa_notification_max = null;
            }
        };
    }
}