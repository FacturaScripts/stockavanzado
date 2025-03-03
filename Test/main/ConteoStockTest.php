<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Model\ConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaConteoStock;
use FacturaScripts\Plugins\StockAvanzado\Model\MovimientoStock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class ConteoStockTest extends TestCase
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

        // añadimos stock del producto al almacén
        $stock = new Stock();
        $stock->cantidad = 0;
        $stock->codalmacen = $almacen->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

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

        // comprobamos que el conteo está completado
        $conteo->loadFromCode($conteo->primaryColumnValue());
        $this->assertTrue($conteo->completed, 'stock-count-not-completed');

        // comprobamos que el stock del producto es 50
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(50, $stock->cantidad, 'stock-quantity-is-not-50');

        // eliminamos el conteo
        $this->assertTrue($conteo->delete(), 'stock-count-can-not-be-deleted');

        // comprobamos que la línea ya no existe
        $this->assertFalse($linea->exists(), 'stock-count-line-still-exists');

        // comprobamos que el stock vuelva a 0
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(0, $stock->cantidad, 'stock-quantity-is-not-0');

        // comprobamos que no hay movimientos de stock
        $movement = new MovimientoStock();
        $whereRef = [new DataBaseWhere('referencia', $product->referencia)];
        $this->assertFalse($movement->loadFromCode('', $whereRef), 'stock-movement-exists');

        // eliminamos
        $this->assertTrue($stock->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($almacen->delete());
    }

    public function testCantCreateWithoutWarehouse(): void
    {
        // intentamos crear un conteo sin almacén
        $conteo = new ConteoStock();
        $conteo->codalmacen = null;
        $conteo->observaciones = 'Test';
        $this->assertFalse($conteo->save(), 'stock-count-can-be-saved-without-warehouse');
    }

    public function testCantCreateOnInvalidWarehouse(): void
    {
        // intentamos crear un conteo en un almacén inexistente
        $conteo = new ConteoStock();
        $conteo->codalmacen = 'invalid';
        $conteo->observaciones = 'Test';
        $this->assertFalse($conteo->save(), 'stock-count-can-be-saved-with-invalid-warehouse');
    }

    public function testEscapeHtml(): void
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un conteo con html en las observaciones
        $conteo = new ConteoStock();
        $conteo->codalmacen = $almacen->codalmacen;
        $conteo->observaciones = '<script>alert("XSS")</script>';
        $this->assertTrue($conteo->save(), 'stock-count-can-not-be-saved');

        // comprobamos que se ha escapado el html
        $this->assertEquals('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $conteo->observaciones, 'html-not-escaped');

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($almacen->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}