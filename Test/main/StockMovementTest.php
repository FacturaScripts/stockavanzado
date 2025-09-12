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

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
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
            Where::column('codalmacen', $conteo->codalmacen),
            Where::column('docid', $conteo->id()),
            Where::column('docmodel', $conteo->modelClassName()),
            Where::column('referencia', $linea->referencia)
        ];
        $this->assertTrue($movement->loadWhere($where));

        // comprobamos la cantidad del movimiento
        $this->assertEquals(50, $movement->cantidad);

        // comprobamos el saldo del movimiento
        $this->assertEquals(50, $movement->saldo);

        // eliminamos el movimiento de stock
        $this->assertTrue($movement->delete());

        // ejecutamos la reconstrucción de movimientos de stock
        StockMovementManager::rebuild($product->idproducto);

        // comprobamos que está el movimiento de stock
        $this->assertTrue($movement->loadWhere($where));

        // comprobamos la cantidad del movimiento
        $this->assertEquals(50, $movement->cantidad);

        // comprobamos el saldo del movimiento
        $this->assertEquals(50, $movement->saldo);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }
}
