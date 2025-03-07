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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockMovementManager;
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class StockMovementTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testCreate(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $almacen->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save(), 'stock-count-can-not-be-saved');

        // añadimos el producto al conteo de stock
        $linea = $conteo->addLine($product->referencia, $product->idproducto, 50);
        $this->assertTrue($linea->exists(), 'stock-count-line-can-not-be-saved');

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock(), 'stock-count-not-recalculate');

        // comprobamos que está el movimiento de stock
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $conteo->codalmacen),
            new DataBaseWhere('docid', $conteo->primaryColumnValue()),
            new DataBaseWhere('docmodel', $conteo->modelClassName()),
            new DataBaseWhere('referencia', $linea->referencia)
        ];
        $this->assertTrue($movement->loadFromCode('', $where), 'stock-movement-not-found');

        // eliminamos el movimiento de stock
        $this->assertTrue($movement->delete(), 'stock-movement-not-deleted');

        // ejecutamos la reconstrucción de movimientos de stock
        StockMovementManager::rebuild($product->idproducto);

        // comprobamos que está el movimiento de stock
        $this->assertTrue($movement->loadFromCode('', $where), 'stock-movement-not-rebuilt');

        // eliminamos
        $this->assertTrue($conteo->delete(), 'stock-count-not-deleted');
        $this->assertTrue($product->delete(), 'product-not-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-not-deleted');
    }
}