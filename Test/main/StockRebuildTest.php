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
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Lib\StockRebuild;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class StockRebuildTest extends TestCase
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

        // obtenemos el stock del producto
        $stock = new Stock();
        $where = [
            Where::column('codalmacen', $warehouse->codalmacen),
            Where::column('referencia', $product->referencia)
        ];
        $this->assertTrue($stock->loadWhere($where));

        // comprobamos que el stock es correcto
        $this->assertEquals(50, $stock->cantidad);

        // cambiamos la cantidad del stock
        $stock->cantidad = 100;
        $this->assertTrue($stock->save());

        // ejecutamos la reconstrucción del stock con base en sus movimientos
        $this->assertTrue(StockRebuild::rebuild($product->idproducto));

        // recargamos el stock
        $stock->load($stock->id());

        // comprobamos que el stock es correcto
        $this->assertEquals(50, $stock->cantidad);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }
}
