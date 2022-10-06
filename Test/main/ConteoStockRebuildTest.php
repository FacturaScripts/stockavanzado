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
use FacturaScripts\Core\Model\Base\ModelCore;
use FacturaScripts\Core\Model\Stock;
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
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $stock->codalmacen = $almacen->codalmacen;
        $stock->cantidad = 100;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

        // creamos un conteo de stock
        $conteo = new ConteoStock();
        $conteo->codalmacen = $almacen->codalmacen;
        $conteo->fechainicio = date(ModelCore::DATE_STYLE);
        $conteo->fechafin = date(ModelCore::DATE_STYLE);
        $conteo->observaciones = 'Test';
        $this->assertTrue($conteo->save(), 'stock-count-can-not-be-saved');

        // añadimos el producto al conteo de stock
        $linea = new LineaConteoStock();
        $linea->idconteo = $conteo->idconteo;
        $linea->idproducto = $product->idproducto;
        $linea->referencia = $product->referencia;
        $linea->cantidad = 50;
        $this->assertTrue($linea->save(), 'stock-count-line-can-not-be-saved');

        // actualizamos stock según el conteo
        $this->assertTrue($conteo->recalculateStock(), 'stock-count-not-recalculate');

        // comprobamos la cantidad del stock del producto sea igual a la cantidad del conteo
        $stock = new Stock();
        $where = [new DataBaseWhere('referencia', $product->referencia)];
        $stock->loadFromCode('', $where);
        $this->assertTrue($stock->exists(), 'stock-product-not-exists');
        $this->assertEquals($linea->cantidad, $stock->cantidad, 'stock-quantity-does-not-match');

        // eliminamos
        $this->assertTrue($linea->delete(), 'stock-count-line-can-not-be-deleted');
        $this->assertTrue($conteo->delete(), 'stock-count-can-not-be-deleted');
        $this->assertTrue($stock->delete(), 'stock-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}