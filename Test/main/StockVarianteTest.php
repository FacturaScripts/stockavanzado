<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2026 Carlos García Gómez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Join\StockVariante;
use FacturaScripts\Dinamic\Model\MovimientoStock;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

final class StockVarianteTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;

    public function testFieldsAndTotals(): void
    {
        // creamos dos almacenes
        $warehouse1 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse1->save());
        $warehouse2 = $this->getRandomWarehouse();
        $this->assertTrue($warehouse2->save());

        // creamos un producto con coste y precio
        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());
        $variante = $product->getVariants()[0];
        $variante->coste = 10.0;
        $variante->precio = 20.0;
        $this->assertTrue($variante->save());

        // añadimos stock en los dos almacenes
        $stock1 = new Stock();
        $stock1->cantidad = 10;
        $stock1->codalmacen = $warehouse1->codalmacen;
        $stock1->idproducto = $product->idproducto;
        $stock1->referencia = $product->referencia;
        $this->assertTrue($stock1->save());

        $stock2 = new Stock();
        $stock2->cantidad = 4;
        $stock2->codalmacen = $warehouse2->codalmacen;
        $stock2->idproducto = $product->idproducto;
        $stock2->referencia = $product->referencia;
        $this->assertTrue($stock2->save());

        // creamos movimientos de la referencia en ambos almacenes (suma = 10)
        $this->createMovement($product->idproducto, $product->referencia, $warehouse1->codalmacen, 6);
        $this->createMovement($product->idproducto, $product->referencia, $warehouse2->codalmacen, 4);

        $where = [Where::eq('variantes.referencia', $product->referencia)];

        // count() debe devolver una fila por almacén con stock (2), sin GROUP BY
        $this->assertEquals(2, StockVariante::count($where), 'count() incorrecto');

        // cargamos las filas ordenadas por almacén
        $rows = StockVariante::all($where, ['stocks.codalmacen' => 'ASC']);
        $this->assertCount(2, $rows, 'Número de filas incorrecto');

        foreach ($rows as $row) {
            // los campos base se cargan correctamente
            $this->assertEquals($product->referencia, $row->referencia);
            $this->assertEquals($product->idproducto, $row->idproducto);
            $this->assertEquals(10.0, (float)$row->coste);
            $this->assertEquals(20.0, (float)$row->precio);

            // total_movimientos suma por referencia (independiente del almacén y sin multiplicar)
            $this->assertEquals(10.0, (float)$row->total_movimientos, 'total_movimientos incorrecto');

            // totales calculados en función de la cantidad de cada almacén
            $this->assertEquals((float)$row->cantidad * 20.0, (float)$row->total_precio, 'total_precio incorrecto');
            $this->assertEquals((float)$row->cantidad * 10.0, (float)$row->total_coste, 'total_coste incorrecto');
        }

        // las cantidades por almacén son las esperadas
        $quantities = array_map(function ($row) {
            return (float)$row->cantidad;
        }, $rows);
        sort($quantities);
        $this->assertEquals([4.0, 10.0], $quantities, 'Cantidades por almacén incorrectas');

        // eliminamos
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse1->delete());
        $this->assertTrue($warehouse2->delete());
    }

    public function testWithoutMovements(): void
    {
        // creamos un almacén y un producto con stock, pero sin movimientos
        $warehouse = $this->getRandomWarehouse();
        $this->assertTrue($warehouse->save());

        $product = $this->getRandomProduct();
        $this->assertTrue($product->save());

        $stock = new Stock();
        $stock->cantidad = 7;
        $stock->codalmacen = $warehouse->codalmacen;
        $stock->idproducto = $product->idproducto;
        $stock->referencia = $product->referencia;
        $this->assertTrue($stock->save());

        $where = [Where::eq('variantes.referencia', $product->referencia)];
        $rows = StockVariante::all($where);
        $this->assertCount(1, $rows);

        // sin movimientos, total_movimientos debe ser 0 (no null)
        $this->assertSame(0.0, (float)$rows[0]->total_movimientos, 'total_movimientos debería ser 0');

        // eliminamos
        $this->assertTrue($product->delete());
        $this->assertTrue($warehouse->delete());
    }

    private function createMovement(int $idproducto, string $referencia, string $codalmacen, float $cantidad): void
    {
        $movement = new MovimientoStock();
        $movement->cantidad = $cantidad;
        $movement->codalmacen = $codalmacen;
        $movement->documento = 'Test';
        $movement->fecha = Tools::date();
        $movement->hora = Tools::hour();
        $movement->idproducto = $idproducto;
        $movement->referencia = $referencia;
        $this->assertTrue($movement->save());
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
