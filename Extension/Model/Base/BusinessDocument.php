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
    /**
     * Abre un lote de movimientos de stock cuando el documento es nuevo o cambia de estado,
     * que es cuando se generan o recalculan muchos movimientos de golpe.
     */
    public function saveBefore(): Closure
    {
        return function () {
            if ($this->exists() && isset($this->idestado) && $this->getOriginal('idestado') == $this->idestado) {
                return true;
            }

            StockMovementManager::beginBatch('doc:' . spl_object_hash($this));
            return true;
        };
    }

    /**
     * Cierra el lote abierto en saveBefore(), procesando los movimientos pendientes.
     */
    public function save(): Closure
    {
        return function () {
            StockMovementManager::flushBatch('doc:' . spl_object_hash($this));
            return true;
        };
    }

    /**
     * Cierra el lote tras insertar un documento nuevo.
     */
    public function saveInsert(): Closure
    {
        return function () {
            StockMovementManager::flushBatch('doc:' . spl_object_hash($this));
            return true;
        };
    }

    public function saveUpdate(): Closure
    {
        return function () {
            StockMovementManager::updateDocumentDate($this);
            StockMovementManager::cleanResidualMovements($this);

            // cerramos el lote: primero se limpian los movimientos residuales y después
            // se recalculan los que siguen siendo necesarios
            StockMovementManager::flushBatch('doc:' . spl_object_hash($this));
            return true;
        };
    }
}
