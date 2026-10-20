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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Model\Base;

use Closure;
use FacturaScripts\Dinamic\Lib\StockMovementManager;

/**
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class BusinessDocument
{
    public function saveUpdate(): Closure
    {
        return function () {
            // si cambia la fecha, cambia el orden de los movimientos: reconstruimos los productos del documento
            if ($this->getOriginal('fecha') === $this->fecha && $this->getOriginal('hora') === $this->hora) {
                return true;
            }

            foreach ($this->getLines() as $line) {
                StockMovementManager::queueProductRebuild($line->idproducto);
            }
            return true;
        };
    }
}
