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

use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Plugins\StockAvanzado\Lib\StockValue;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class StockValueTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testCreate(): void
    {
        // creamos un almacén
        $warehouse = new Almacen();
        $warehouse->nombre = 'Warehouse ' . mt_rand(1, 99);
        $this->assertTrue($warehouse->save(), 'almacen-can-not-be-saved');

        // creamos un producto
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save(), 'product-can-not-be-saved');

        // obtenemos la primera variante
        $variante = $product->getVariants()[0];

        // ponemos el precio y coste
        $variante->coste = 10.0;
        $variante->precio = 20.0;
        $this->assertTrue($variante->save(), 'variant-can-not-be-saved');

        // añadimos stock del producto al almacén
        $stock = new Stock();
        $stock->cantidad = 10;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save(), 'stock-can-not-be-saved');

        // ejecutamos la clase StockValue
        $this->assertTrue(StockValue::update($warehouse), 'stock-value-can-not-be-updated');

        // actualizamos el almacén
        $warehouse->loadFromCode($warehouse->codalmacen);

        // comprobamos que el stock valorado es correcto
        $this->assertEquals(100.0, $warehouse->stock_valorado_coste, 'stock-value-coste-is-not-correct');
        $this->assertEquals(200.0, $warehouse->stock_valorado_precio, 'stock-value-precio-is-not-correct');

        // eliminamos
        $this->assertTrue($product->delete(), 'product-can-not-be-deleted');
        $this->assertTrue($warehouse->delete(), 'warehouse-can-not-be-deleted');
    }
}