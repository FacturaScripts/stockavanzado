<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaConteoStock;
use FacturaScripts\Test\Core\LogErrorsTrait;
use FacturaScripts\Test\Core\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class ConteoStockRebuildTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testCreate()
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // añadimos stock del producto al almacén
        $stock = new Stock();
        $stock->cantidad = 100;
        $stock->codalmacen = $almacen->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->pterecibir = 4;
        $stock->referencia = $product->referencia;
        $stock->reservada = 2;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $almacen->codalmacen;
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save(), 'stock-count-can-not-be-saved');

        // añadimos el producto al conteo de stock
        $linea = new LineaConteoStock();
        $linea->idconteo = $conteo->idconteo;
        $linea->idproducto = $product->idproducto;
        $linea->referencia = $product->referencia;
        $linea->cantidad = 50;
        $this->assertTrue($linea->save(), 'stock-count-line-can-not-be-saved');

        // comprobamos que el stock sigue siendo 100, ptereceibir 4 y reservada 2
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(100, $stock->cantidad, 'stock-quantity-is-not-100');
        $this->assertEquals(4, $stock->pterecibir, 'stock-pterecibir-is-not-4');
        $this->assertEquals(2, $stock->reservada, 'stock-reservada-is-not-2');

        // actualizamos stock según el conteo
        $this->assertTrue($conteo->updateStock(), 'stock-count-not-recalculate');

        // comprobamos que ahora el stock sea 50, pero ptereceibir 4 y reservada 2
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(50, $stock->cantidad, 'stock-quantity-is-not-50');
        $this->assertEquals(4, $stock->pterecibir, 'stock-pterecibir-is-not-4');
        $this->assertEquals(2, $stock->reservada, 'stock-reservada-is-not-2');

        // eliminamos el conteo
        $this->assertTrue($conteo->delete(), 'stock-count-can-not-be-deleted');

        // comprobamos que la línea ya no existe
        $this->assertFalse($linea->exists(), 'stock-count-line-still-exists');

        // comprobamos que el stock sigue siendo 50, ptrecibir 4 y reservada 2
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(50, $stock->cantidad, 'stock-quantity-is-not-50');
        $this->assertEquals(4, $stock->pterecibir, 'stock-pterecibir-is-not-4');
        $this->assertEquals(2, $stock->reservada, 'stock-reservada-is-not-2');

        // eliminamos
        $this->assertTrue($stock->delete(), 'stock-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}