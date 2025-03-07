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
use FacturaScripts\Dinamic\Lib\InitialStockMovement;
use FacturaScripts\Dinamic\Model\LineaConteoStock;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class InitialStockTest extends TestCase
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
        $stock->cantidad = 10;
        $stock->codalmacen = $almacen->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

        // ejecutamos la clase InitialStockMovement
        InitialStockMovement::run();

        // buscamos un movimiento para el producto
        $movimiento = new MovimientoStock();
        $where = [
            new DataBaseWhere('codalmacen', $almacen->codalmacen),
            new DataBaseWhere('referencia', $product->referencia)
        ];
        $this->assertTrue($movimiento->loadFromCode('', $where), 'stock-movement-not-found');

        // buscamos la línea del conteo
        $line = new LineaConteoStock();
        $where = [
            new DataBaseWhere('referencia', $product->referencia),
            new DataBaseWhere('idproducto', $product->idproducto)
        ];
        $this->assertTrue($line->loadFromCode('', $where), 'stock-count-line-not-found');

        // obtenemos el conteo de la línea
        $conteo = $line->getConteo();
        $this->assertTrue($conteo->exists(), 'stock-count-not-found');

        // eliminamos el conteo
        $this->assertTrue($conteo->delete(), 'stock-count-not-deleted');

        // comprobamos que la línea no existe
        $this->assertFalse($line->exists(), 'stock-count-line-not-deleted');

        // comprobamos que el movimiento no existe
        $this->assertFalse($movimiento->exists(), 'stock-movement-not-deleted');

        // eliminamos
        $this->assertTrue($stock->delete(), 'stock-can-not-be-deleted');
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($almacen->delete(), 'warehouse-can-not-be-deleted');
    }
}