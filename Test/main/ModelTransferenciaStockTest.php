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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Model\LineaTransferenciaStock;
use FacturaScripts\Plugins\StockAvanzado\Model\TransferenciaStock;
use FacturaScripts\Test\Core\LogErrorsTrait;
use FacturaScripts\Test\Core\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class ModelTransferenciaStockTest extends TestCase
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
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $almacen->codalmacen;
        $stock->cantidad = 100;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

        // creamos un segundo almacén
        $almacen2 = $this->getRandomWarehouse();
        $this->assertTrue($almacen2->save(), 'almacen-can-not-be-saved');

        // hacemos una transferencia de stock del almacén 1 al almacén 2
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $almacen->codalmacen;
        $transferencia->codalmacendestino = $almacen2->codalmacen;
        $transferencia->observaciones = 'Transferencia de stock';
        $this->assertTrue($transferencia->save(), 'transferencia-can-not-be-saved');

        // añadimos el producto a la transferencia
        $linea = new LineaTransferenciaStock();
        $linea->idtrans = $transferencia->idtrans;
        $linea->referencia = $product->referencia;
        $linea->cantidad = 10;
        $this->assertTrue($linea->save(), 'linea-can-not-be-saved');

        // comprobamos que el stock del almacén 1 es 90
        $stock->loadFromCode($stock->idstock);
        $this->assertEquals(90, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 10
        $stock2 = new Stock();
        $where = [
            new DataBaseWhere('codalmacen', $almacen2->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($stock2->loadFromCode('', $where), 'stock-can-not-be-loaded');
        $this->assertEquals(10, $stock2->cantidad);

        // eliminamos la transferencia
        $this->assertTrue($transferencia->delete(), 'transferencia-can-not-be-deleted');

        // comprobamos que la línea se ha eliminado
        $this->assertFalse($linea->exists(), 'linea-can-not-be-deleted');

        // comprobamos que el stock del almacén 1 es 100
        $stock->loadFromCode($stock->idstock);
        $this->assertEquals(100, $stock->cantidad);

        // comprobamos que el stock del almacén 2 es 0
        $stock2->loadFromCode($stock2->idstock);
        $this->assertEquals(0, $stock2->cantidad);

        // eliminamos
        $this->assertTrue($almacen2->delete(), 'almacen-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'almacen-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
    }

    public function testHtmlOnFields()
    {
        // creamos un almacén
        $almacen = $this->getRandomWarehouse();
        $this->assertTrue($almacen->save(), 'almacen-can-not-be-saved');

        // creamos un segundo almacén
        $almacen2 = $this->getRandomWarehouse();
        $this->assertTrue($almacen2->save(), 'almacen2-can-not-be-saved');

        // creamos una transferencia de stock con html en las observaciones
        $transferencia = new TransferenciaStock();
        $transferencia->codalmacenorigen = $almacen->codalmacen;
        $transferencia->codalmacendestino = $almacen2->codalmacen;
        $transferencia->observaciones = '<test>';
        $this->assertTrue($transferencia->save(), 'transferencia-can-not-be-saved');

        // comprobamos que el html se ha escapado
        $this->assertEquals('&lt;test&gt;', $transferencia->observaciones);

        // eliminamos
        $this->assertTrue($transferencia->delete(), 'transferencia-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'almacen-can-not-be-deleted');
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
