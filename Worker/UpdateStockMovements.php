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

namespace FacturaScripts\Plugins\StockAvanzado\Worker;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuildManager;

class UpdateStockMovements extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        // cargamos el producto
        $product = Producto::find($event->value);
        if (empty($product)) {
            return $this->done();
        }

        // recorremos únicamente los pares (almacén, referencia) con movimientos
        $db = new DataBase();
        $sql = 'SELECT DISTINCT codalmacen, referencia FROM stocks_movimientos'
            . ' WHERE idproducto = ' . $db->var2str($product->idproducto);
        foreach ($db->select($sql) as $row) {
            StockMovementManager::updateReferenceSaldos($row['codalmacen'], $row['referencia']);
        }

        $rebuild = (bool)Tools::settings('default', 'stock_rebuild', false) === true ?? false;
        if ($rebuild) {
            StockRebuildManager::rebuild($product->idproducto);
        }

        return $this->done();
    }
}
