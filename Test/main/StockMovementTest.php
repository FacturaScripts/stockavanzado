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
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save());

        // añadimos el producto al conteo de stock
        $linea = $conteo->addLine($product->referencia, $product->idproducto, 50);
        $this->assertTrue($linea->exists());

        // ejecutamos el conteo
        $this->assertTrue($conteo->updateStock());

        // comprobamos que está el movimiento de stock
        $movement = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $conteo->codalmacen),
            new DataBaseWhere('docid', $conteo->primaryColumnValue()),
            new DataBaseWhere('docmodel', $conteo->modelClassName()),
            new DataBaseWhere('referencia', $linea->referencia)
        ];
        $this->assertTrue($movement->loadFromCode('', $where));

        // eliminamos el movimiento de stock
        $this->assertTrue($movement->delete());

        // ejecutamos la reconstrucción de movimientos de stock
        StockMovementManager::rebuild($product->idproducto);

        // comprobamos que está el movimiento de stock
        $this->assertTrue($movement->loadFromCode('', $where));

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }
}
