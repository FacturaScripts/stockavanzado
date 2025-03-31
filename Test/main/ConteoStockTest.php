<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
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
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        // añadimos stock del producto al almacén
        $stock = new Stock();
        $stock->cantidad = 0;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save());

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

        // comprobamos que el conteo está completado
        $conteo->loadFromCode($conteo->primaryColumnValue());
        $this->assertTrue($conteo->completed);

        // si intento volver a ejecutarlo debe devolver true porque ya está completado
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

        // comprobamos que el stock del producto es 50
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(50, $stock->cantidad);

        // eliminamos el conteo
        $this->assertTrue($conteo->delete());

        // comprobamos que la línea ya no existe
        $this->assertFalse($linea->exists());

        // comprobamos que el movimiento de stock ya no existe
        $this->assertFalse($movement->exists());

        // comprobamos que el stock vuelve a 0
        $stock->loadFromCode($stock->primaryColumnValue());
        $this->assertEquals(0, $stock->cantidad);

        // eliminamos
        $this->assertTrue($stock->delete());
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    public function testCantCreateWithoutWarehouse(): void
    {
        // intentamos crear un conteo sin almacén
        $conteo = new ConteoStock();
        $conteo->codalmacen = null;
        $conteo->observaciones = 'Test';
        $this->assertFalse($conteo->save());
    }

    public function testCantCreateOnInvalidWarehouse(): void
    {
        // intentamos crear un conteo en un almacén inexistente
        $conteo = new ConteoStock();
        $conteo->codalmacen = 'invalid';
        $conteo->observaciones = 'Test';
        $this->assertFalse($conteo->save());
    }

    public function testEscapeHtml(): void
    {
        // creamos un almacén
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        // creamos un conteo con html en las observaciones
        $conteo = new ConteoStock();
        $conteo->codalmacen = $warehouse->codalmacen;
        $conteo->observaciones = '<script>alert("XSS")</script>';
        $this->assertTrue($conteo->save());

        // comprobamos que se ha escapado el html
        $this->assertEquals('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $conteo->observaciones);

        // eliminamos
        $this->assertTrue($conteo->delete());
        $this->assertTrue($warehouse->delete());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
