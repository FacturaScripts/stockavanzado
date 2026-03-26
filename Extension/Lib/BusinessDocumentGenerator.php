<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Lib;

use Closure;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Dinamic\Lib\StockMovementManager;

class BusinessDocumentGenerator
{
    public function cloneLine(): Closure
    {
        return function ($prototype, $line, $cantidad, $newDoc, $newLine) {
            if (false === $newDoc instanceof TransformerDocument) {
                return;
            }

            $parentDoc = $line->getDocument();
            if ($parentDoc instanceof TransformerDocument) {
                StockMovementManager::addLineBusinessDocument($line, $parentDoc);
            }

            StockMovementManager::addLineBusinessDocument($newLine, $newDoc);
        };
    }
}
