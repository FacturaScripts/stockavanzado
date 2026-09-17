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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Controller;

use Closure;
use FacturaScripts\Dinamic\Lib\StockMovementManager;

/**
 * Agrupa en un único lote de movimientos de stock el cambio de estado de todos
 * los documentos y la generación del nuevo. De lo contrario cada documento crearía
 * y borraría sus movimientos por separado, recalculando los saldos de cada
 * referencia una y otra vez.
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class DocumentStitcher
{
    public function closeDocumentsAfter(): Closure
    {
        return function () {
            StockMovementManager::flushBatch('stitcher:' . spl_object_hash($this));
        };
    }

    public function closeDocumentsBefore(): Closure
    {
        return function () {
            StockMovementManager::beginBatch('stitcher:' . spl_object_hash($this));
        };
    }

    public function generateNewDocumentAfter(): Closure
    {
        return function () {
            StockMovementManager::flushBatch('stitcher:' . spl_object_hash($this));
        };
    }

    public function generateNewDocumentBefore(): Closure
    {
        return function () {
            StockMovementManager::beginBatch('stitcher:' . spl_object_hash($this));
        };
    }
}
